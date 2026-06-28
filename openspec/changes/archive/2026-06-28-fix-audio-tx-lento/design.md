# Design: fix-audio-tx-lento — Corrección de velocidad TX en WebRTC Bridge

## Technical Approach

Dual-layer fix para el sample rate mismatch que causa slow motion (3×) en TX audio:

1. **Browser-side (capas 1 y 2)**: CIC downsample en `onaudioprocess` + envío de `rate` real en cada `audio_tx`
2. **Server-side (capa 3)**: Resample lineal condicional cuando `rate != 16000` como safety net

La combinación cubre browsers modernos (downsample en origen) y browsers viejos o casos edge donde el downsample falla (server fallback con warning log).

## Architecture Decisions

### Decision: CIC stage-1 running-sum para downsample browser

| Opción | Tradeoff | Decisión |
|--------|----------|----------|
| Decimación directa (tomar 1 de cada 3) | Sin anti-aliasing, aliasing audible | ❌ Rechazado |
| FIR filter con ventana | Cálculo pesado para ScriptProcessor (> 50 taps) | ❌ Rechazado |
| **CIC running-sum, decimate-by-3** | O(n), anti-aliasing básico, -13dB @ 8kHz, primer null @ 16kHz | ✅ **Seleccionado** |

**Rationale**: CIC stage-1 es ~3 sumas + 1 división por callback de 1024 samples (~0.01ms). Provee filtering suficiente para voz humana. En 48kHz→16kHz el aliasing cae dentro del rango de ulaw 8kHz que ya limita a 4kHz post `tx_process()`.

### Decision: `linear_resample_float32()` puro Python sin scipy

| Opción | Tradeoff | Decisión |
|--------|----------|----------|
| `scipy.signal.resample` | Dependencia externa pesada (~15MB), FFT overhead | ❌ Rechazado |
| Polyphase filter bank | Complejidad alta, overkill para safety net esporádico | ❌ Rechazado |
| **Linear interpolation** | ~10 LOC, O(n), precisión suficiente para fallback que rara vez se activa | ✅ **Seleccionado** |

**Rationale**: El server fallback se activa SOLO si browser-side downsample no funciona. Para Chrome 60+ y Firefox 98+ el downsample funciona siempre. Linear interpolation es aceptable para voz humana en este path de excepción.

### Decision: `rate` field en cada `audio_tx`, no solo en primer mensaje

| Opción | Tradeoff | Decisión |
|--------|----------|----------|
| Rate solo en primer mensaje | Complejidad de estado, frágil si reconecta | ❌ Rechazado |
| **Rate en cada mensaje** | 10-15 bytes extra por ~8KB payload, stateless, robusto | ✅ **Seleccionado** |

**Rationale**: El overhead es despreciable (~0.2%). Stateless significa que no hay edge cases con reconexión ni cambios de sample rate en medio de una llamada (raro pero posible).

## Data Flow

```
BROWSER (ptt-widget.js)
┌────────────────────────────────────────────────────────────┐
│ AudioContext({sampleRate:16000}) → catch → default (48kHz) │
│   ↓                                                         │
│ onaudioprocess(e):                                          │
│   input = e.inputBuffer.getChannelData(0)  ← Float32[1024] │
│   ↓                                                         │
│   actualRate = this._txCtx.sampleRate                       │
│   let outRate, outSamples;                                  │
│   ↓                                                         │
│   if actualRate === 16000:                                  │
│     outSamples = input       ← pass-through                 │
│     outRate = 16000                                         │
│   else:                                                     │
│     try:                                                    │
│       outSamples = _downsampleCIC(input, actualRate) → 16k │
│       outRate = 16000  ← CIC exitoso, data a 16kHz         │
│     catch e:                                                │
│       outSamples = input     ← CIC falló, raw data         │
│       outRate = actualRate   ← server fallback necesario   │
│   ↓                                                         │
│   _sendAudioChunk(outSamples, outRate)                      │
│     → _samplesToHex(outSamples)                             │
│     → {"type":"audio_tx","data":"<hex>","rate":outRate}     │
└────────────────────────────────────────────────────────────┘
         │
         │ WebSocket JSON (~8.5KB por mensaje a 16kHz)
         ▼

SERVER (server.py)
┌─────────────────────────────────────────────────────────────┐
│ _handle_ws_message():                                       │
│   rate = payload.get("rate", 16000)                         │
│   pcm_f32 = bytes.fromhex(hex)                              │
│   ↓                                                         │
│   if rate != 16000:                                         │
│     pcm_f32 = linear_resample_float32(pcm_f32, rate, 16000) │
│     logger.warning("Fallback resample: rate=%d", rate)      │
│   ↓                                                         │
│   tx_process(pcm_f32)  → float32→s16 → 16k→8k → ulaw      │
│   → IAX2 mini frame a Asterisk                              │
└─────────────────────────────────────────────────────────────┘
```

### Pipe dream de rates

| Browser | sampleRate real | CIC downsample | outRate enviado | Server fallback |
|---------|----------------|----------------|-----------------|-----------------|
| Chrome 60+ | 16000 | No (passthrough) | 16000 | No |
| Firefox < 98 | 48000 | Sí (M=3, 48→16) | 16000 | No |
| Safari < 16.4 | 48000 | Sí (M=3, 48→16) | 16000 | No |
| Edge: CIC exception | 48000 | Falla (catch) | 48000 | Sí (linear 48→16) |
| 44.1kHz raro | 44100 | **No soportado** | 16000 (post-CIC ~14700 real) | Diferencia~8% audible. No soportado explícitamente — <1% hardware |
| 32kHz raro | 32000 | Sí (M=2, 32000/2=16000) | 16000 | No |

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `public/assets/js/ptt-widget.js` | Modify | Add `_downsampleCIC()`, modify `_sendAudioChunk` signature, add rate field to `audio_tx` JSON |
| `app/Services/WebRTCBridge/server.py` | Modify | Add `linear_resample_float32()`, add rate check + fallback resample in `audio_tx` handler |
| `app/Services/WebRTCBridge/audio.py` | No change | Pipeline sigue igual — siemrpe recibe float32 a 16kHz nominal |

## Interfaces / Contracts

### `_downsampleCIC(samples: Float32Array, actualRate: number) → Float32Array`

```javascript
/**
 * Cascaded Integrator-Comb downsample, stage-1.
 * Running sum sobre M=3 samples, luego decimate-by-M.
 * Para 48kHz→16kHz: M=3 (1024 inputs → ~341 outputs).
 * Para rates variables: M = round(actualRate / 16000).
 *
 * @param {Float32Array} samples - Input PCM a actualRate
 * @param {number} actualRate - Sample rate real del AudioContext
 * @returns {Float32Array} PCM a 16kHz
 */
```

### `linear_resample_float32(data: bytes, in_rate: int, out_rate: int) → bytes`

```python
def linear_resample_float32(data: bytes, in_rate: int, out_rate: int) -> bytes:
    """Resample float32 PCM from in_rate to out_rate via linear interpolation.

    Pure Python (struct + math). Handles arbitrary input/output rates.
    O(n) where n = output sample count.

    Parameters
    ----------
    data : bytes
        Packed float32 PCM at in_rate.
    in_rate : int
        Input sample rate in Hz.
    out_rate : int
        Desired output sample rate in Hz.

    Returns
    -------
    bytes
        Packed float32 PCM at out_rate.
    """
```

### `audio_tx` message contract (extendido)

```json
{
    "type": "audio_tx",
    "data": "<hex-encoded float32 PCM>",
    "rate": 48000
}
```

- `rate`: entero, sample rate del audio en `data`. Valor real de `AudioContext.sampleRate`.
- Si `rate` está ausente, server asume 16000 (backward compat).
- Server MUST validar que `rate` es un entero entre 8000 y 96000. Si está fuera de rango, asume 16000 y loggea warning.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit (JS) | `_downsampleCIC` output length + frequency preservation | Generar sine wave a 1kHz/48kHz → downsample → verificar 341 outputs (1024/3), FFT show peak @ 1kHz |
| Unit (Python) | `linear_resample_float32` accuracy | Sine 48kHz → resample a 16kHz → verificar sample count ratio 3:1 + frecuencia preservada |
| Integration | Pipeline completo browser→server | Usar `sox` para generar tono 1kHz @ 48kHz WAV → inyectar en `_handle_ws_message` → capturar ulaw → medir frecuencia |
| E2E manual | PTT con voz en Firefox < 98 | Confirmar audio en radio suena a velocidad normal, no slow motion |
| E2E manual | PTT con voz en Chrome | Confirmar downsample NO se activa (rate=16000), servidor logs sin warning |
| Regression | PTT key/unkey + DTMF | Verificar que cambios no rompen funcionalidad existente |

### Verificación con tono

```bash
# Generar tono 1kHz @ 48kHz (simula lo que envía el browser a 48kHz)
sox -n -r 48000 -b 32 -e floating-point test_tone_48k.f32 synth 3 sine 1000 vol 0.5

# Procesar con el resample lineal
python3 -c "
import struct
with open('test_tone_48k.f32','rb') as f:
    data = f.read()
# Aplicar linear_resample_float32
# Verificar: output frequency ≈ 1000Hz ± 1%
"
```

## Migration / Rollout

No migration required. Los cambios son aditivos:
- `rate` field es opcional → servidores viejos ignoran el campo extra
- Si `rate` no está presente, server asume 16000 (compatibilidad backward)
- El downsample CIC se aplica condicionalmente → no afecta browsers que ya funcionan correctamente

Rollback: revertir commits de `ptt-widget.js` y `server.py`. El pipeline anterior sigue funcionando.

## Open Questions (Resueltas)

- [x] **console.warn en CIC downsample**: Sí, incluir `console.warn("[ChileMon] CIC downsample activo: %d→16000 Hz", actualRate)` para debug en campo. Se agrega en el `try` block después del downsample exitoso.
- [x] **Feature flag `tx_downsample_enabled`**: NO se implementa en este cambio. Se agrega en deploy si es necesario. El cambio es aditivo y backward-compatible.
- [x] **44.1kHz y rates no-estándar**: Se documenta como **no soportado explícitamente**. Los dispositivos modernos (2020+) usan 48kHz o 16kHz. Si `actualRate` no es 16000 ni 48000, el CIC downsample se intenta con `M = Math.round(actualRate / 16000)`, y el server recibe `outRate = actualRate / M` (la tasa real post-CIC, que será ~16000). Si el server detecta `outRate != 16000`, aplica fallback linear resample automáticamente.

### Caso 44.1kHz específico
Para `actualRate = 44100`:
- M = Math.round(44100 / 16000) = 3
- CIC downsample con M=3 → 44100/3 = 14700 Hz
- Server recibe `rate: 14700` → fallback linear resample 14700→16000
- Audio correcto pero con doble procesamiento
- En la práctica, 44.1kHz en AudioContext es extremadamente raro en hardware moderno (>99% usa 48kHz)
