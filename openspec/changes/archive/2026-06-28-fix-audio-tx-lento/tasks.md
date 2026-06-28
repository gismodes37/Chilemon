# Tasks: fix-audio-tx-lento — Corrección de velocidad TX en WebRTC Bridge

**Delivery strategy**: auto-forecast (review budget 400 lines)
**PR scope**: Single PR (~200 líneas total)

---

## Orden de implementación

```
Browser-side (ptt-widget.js)          Server-side (server.py)
┌─────────────────────┐               ┌──────────────────────┐
│ T1: _downsampleCIC  │               │ T4: linear_resample  │
│       ↓             │               │       ↓              │
│ T3: rate en audio_tx│               │ T5: audio_tx handler │
│       ↓             │               └──────────────────────┘
│ T2: onaudioprocess   │
└─────────────────────┘
```

T1 y T4 son independientes (pueden hacerse en paralelo). T2 depende de T1 + T3.
T5 depende de T4.

---

## T1: `_downsampleCIC()` en ptt-widget.js

### File(s) to modify
- `public/assets/js/ptt-widget.js`

### What to implement

Agregar un método privado `_downsampleCIC(samples, actualRate)` dentro de la clase `PTTWidget`. Implementa un filtro CIC (Cascaded Integrator-Comb) stage-1 con running-sum y decimación para convertir audio de cualquier sample rate a ~16kHz.

**Algoritmo**:

1. Calcular `M = Math.round(actualRate / 16000)`
2. Si `M <= 1`, retornar `samples` sin modificar (passthrough)
3. Calcular `outLen = Math.floor(samples.length / M)` — número de samples de salida
4. Crear `Float32Array` con longitud `outLen`
5. Para cada posición de salida `i`:
   - `base = i * M` — índice inicial en el array de entrada
   - Sumar `M` samples consecutivos desde `base`
   - Dividir la suma por `M` (promedio = running-sum con decimación)
   - Asignar a `output[i]`
6. Hacer `console.warn("[ChileMon] CIC downsample: %d→16000 Hz, M=%d", actualRate, M)`
7. Retornar el nuevo `Float32Array`

**Casos borde**:
| Rate | M | input | output | Comportamiento |
|------|---|-------|--------|----------------|
| 16000 | 1 | 1024 | 1024 | Passthrough (M<=1) |
| 48000 | 3 | 1024 | ~341 | Running-sum, promedio ÷3 |
| 44100 | 3 | 1024 | ~341 | CIC downsample a 14700 Hz |
| 32000 | 2 | 1024 | ~512 | Running-sum, promedio ÷2 → 16000 exacto |

**Detalles de implementación**:
- Usar `for` loops nativos sin `Array.map` ni `Array.reduce` para evitar overhead de function calls en el hot path
- No usar `Math.floor` innecesario dentro del loop — `outLen` ya está calculado
- El running-sum + decimación es O(n) con M~3, aproximadamente 0.01ms por callback de 1024 samples
- La división por M normaliza la ganancia (evita clipping por acumulación)

### Acceptance criteria
- [x] `_downsampleCIC()` existe como método de `PTTWidget`
- [x] Con `actualRate=16000` retorna el mismo array (passthrough)
- [x] Con `actualRate=48000` y 1024 inputs retorna Float32Array de ~341 samples
- [x] Con `actualRate=32000` y 1024 inputs retorna Float32Array de ~512 samples
- [x] `console.warn` se dispara cuando downsample está activo (rate != 16000)
- [x] No se dispara `console.warn` en passthrough (rate === 16000)

### Dependencies
Ninguna.

---

## T3: Campo `rate` en mensaje `audio_tx` (y firma de `_sendAudioChunk`)

### File(s) to modify
- `public/assets/js/ptt-widget.js`

### What to implement

Modificar el método `_sendAudioChunk(samples)` para que acepte y use un parámetro `rate`, e incluya el campo `"rate"` en el JSON del mensaje `audio_tx`.

**Cambios en la firma**:
```diff
- _sendAudioChunk(samples) {
+ _sendAudioChunk(samples, rate = 16000) {
```

**Cambios en la construcción del mensaje**:
```diff
- const msg = JSON.stringify({ type: 'audio_tx', data: hex });
+ const msg = JSON.stringify({ type: 'audio_tx', data: hex, rate: rate });
```

**Consideraciones**:
- El default `rate = 16000` asegura backward compatibility con cualquier otro caller que no pase rate explícitamente
- El campo `rate` se incluye en CADA mensaje `audio_tx`, no solo en el primero (stateless, robusto ante reconexiones)
- Overhead: ~15 bytes extra por ~8KB payload (~0.2%)

### Acceptance criteria
- [x] `_sendAudioChunk` acepta segundo parámetro `rate` con default 16000
- [x] El JSON enviado incluye `"rate": <valor>` 
- [x] Backward compatible: llamado sin rate = `"rate": 16000`
- [x] El mensaje existente de visualizer a `feedPCM` sigue funcionando (usa samples sin rate)

### Dependencies
Ninguna.

---

## T2: Modificar `onaudioprocess` en ptt-widget.js

### File(s) to modify
- `public/assets/js/ptt-widget.js`

### What to implement

Reemplazar el callback `onaudioprocess` actual (líneas 454-459) para que detecte el sample rate real del `AudioContext`, aplique downsample CIC condicionalmente, y pase el rate correcto a `_sendAudioChunk`.

**Implementación actual**:
```javascript
this._micProcessor.onaudioprocess = (e) => {
    if (!this.pttActive) return;
    const input = e.inputBuffer.getChannelData(0);
    console.log('PTT: onaudioprocess firing, samples=', input.length, 'pttActive=', this.pttActive);
    this._sendAudioChunk(input);
};
```

**Nueva implementación**:
```javascript
this._micProcessor.onaudioprocess = (e) => {
    if (!this.pttActive) return;
    const input = e.inputBuffer.getChannelData(0);
    const actualRate = this._txCtx.sampleRate;
    let outSamples, outRate;
    if (actualRate === 16000) {
        outSamples = input;
        outRate = 16000;
    } else {
        try {
            outSamples = this._downsampleCIC(input, actualRate);
            outRate = 16000;
        } catch (err) {
            console.warn('[ChileMon] CIC downsample falló, raw data enviado:', err);
            outSamples = input;
            outRate = actualRate;
        }
    }
    this._sendAudioChunk(outSamples, outRate);
};
```

**Flujo de decisión**:

```
actualRate = this._txCtx.sampleRate
           │
           ▼
    ┌─────────────┐
    │ rate=16000? │
    └──────┬──────┘
       yes │     no
           ▼     ▼
    passthrough  try _downsampleCIC()
    outRate=16000    │
              exitoso │ falla
                      ▼     ▼
              outRate=16000  raw data
                             outRate=actualRate
```

**El `console.log` actual de debug** (`'PTT: onaudioprocess firing...'`) se puede remover o mantener — queda a criterio del implementador. La tarea no exige cambios en logging de debug existente.

### Acceptance criteria
- [x] `onaudioprocess` lee `this._txCtx.sampleRate` en cada callback
- [x] Si rate === 16000, pasa samples sin modificar (passthrough)
- [x] Si rate !== 16000, llama a `this._downsampleCIC()` dentro de try/catch
- [x] Si CIC exitoso, envía con `rate=16000` (data ya downsampled)
- [x] Si CIC lanza excepción, envía raw data con `rate=actualRate`
- [x] No se rompe la retrocompatibilidad: `_sendAudioChunk` recibe los parámetros correctos

### Dependencies
- **T1**: `_downsampleCIC()` debe existir
- **T3**: `_sendAudioChunk` debe aceptar parámetro `rate`

---

## T4: `linear_resample_float32()` en server.py

### File(s) to modify
- `app/Services/WebRTCBridge/server.py`

### What to implement

Agregar función **fuera de la clase** `WebRTCBridgeApp` (a nivel de módulo, junto a `_env_str`/`_env_int`) para resamplear audio PCM float32 mediante interpolación lineal.

**Firma**:
```python
def linear_resample_float32(data: bytes, in_rate: int, out_rate: int) -> bytes:
```

**Implementación**:

1. Si `in_rate == out_rate`, retornar `data` sin cambios (early return)
2. Calcular cantidad de input samples: `count = len(data) // 4`
3. Unpackear con `struct.unpack(f'<{count}f', data)` en una `list[float]` (nota: tuple es inmutable pero la lista se puede convertir)
4. Calcular `ratio = in_rate / out_rate` (puede ser flotante)
5. Calcular `out_count = round(count * out_rate / in_rate)` — número de samples de salida
6. Para cada output sample `i` de `0` a `out_count - 1`:
   - `pos = i * ratio` — posición flotante en el espacio de input
   - `idx = int(pos)` — floor index
   - `frac = pos - idx` — fracción entre samples
   - Si `idx + 1 < count`: `sample = samples[idx] * (1 - frac) + samples[idx + 1] * frac`
   - Si no (boundary): `sample = samples[idx]`
   - Acumular en `list[float]` de salida
7. Packear con `struct.pack(f'<{len(out)}f', *out)` y retornar bytes

**Edge cases**:
| in_rate | out_rate | ratio | output/input | Comportamiento |
|---------|----------|-------|-------------|----------------|
| 48000 | 16000 | 3.0 | 1/3 | Submuestreo 3:1 |
| 16000 | 16000 | 1.0 | 1:1 | Passthrough |
| 44100 | 16000 | 2.75625 | ~0.363 | Submuestreo fraccional |
| 14700 | 16000 | 0.91875 | ~1.088 | Upsample (caso raro 44.1k→CIC→14700) |

**No usar dependencias externas**: solo `struct` y `math` (de la stdlib). No scipy, no numpy, no librosa.

### Acceptance criteria
- [x] `linear_resample_float32()` existe como función a nivel de módulo
- [x] Con in_rate === out_rate, retorna los mismos bytes (passthrough)
- [x] Con 48kHz→16kHz: output tiene ~1/3 de los bytes de entrada
- [x] Con 16kHz→16kHz: output idéntico a input
- [x] Con 44100→16000: output tiene ~0.363 × input bytes
- [x] Con 14700→16000: output tiene más bytes que input (upsample)
- [x] Valores interpolados preservan la ganancia (sine wave no se distorsiona)
- [x] Solo usa `struct` de la stdlib (no requiere `math`)

### Dependencies
Ninguna.

---

## T5: Modificar handler `audio_tx` en server.py

### File(s) to modify
- `app/Services/WebRTCBridge/server.py`

### What to implement

Modificar el bloque `elif msg_type == "audio_tx":` (líneas 448-462) para leer el campo `rate` del payload, validarlo, y aplicar `linear_resample_float32()` condicionalmente antes de `tx_process()`.

**Cambios dentro del `try` block existente** (mantener estructura try/except):

```python
pcm_f32_hex = payload.get("data", "")
if not pcm_f32_hex:
    return

# Leer rate con default 16000 (backward compat)
rate = payload.get("rate", 16000)

# Validar rate: debe ser int entre 8000 y 96000
if not isinstance(rate, int) or rate < 8000 or rate > 96000:
    logger.warning("audio_tx invalid rate=%s, assuming 16000", rate)
    rate = 16000

pcm_f32 = bytes.fromhex(pcm_f32_hex)

# Fallback resample si rate != 16000
if rate != 16000:
    pcm_f32 = linear_resample_float32(pcm_f32, rate, 16000)
    logger.warning("Fallback resample activated: rate=%d", rate)

ulaw = tx_process(pcm_f32)
sent = self._active_call.send_voice(ulaw)
if sent:
    logger.debug("audio_tx sent %d bytes ulaw (rate=%d)", len(ulaw), rate)
```

**Detalles importantes**:
- Mantener el `try/except (ValueError, KeyError)` existente — el nuevo código se agrega DENTRO del mismo `try`
- `rate = payload.get("rate", 16000)` asegura backward compatibility con mensajes viejos que no incluyen `rate`
- La validación de rango (8000-96000) evita tasas inválidas que causarían resample incorrecto
- El `logger.warning` es la señal de que el fallback se activó — crítico para monitoreo en producción
- `linear_resample_float32()` se importa/usa como función del mismo módulo
- El log de debug de `audio_tx sent` se actualiza para incluir `rate` en el mensaje

### Acceptance criteria
- [x] El handler `audio_tx` lee campo `rate` con default 16000
- [x] Valida que rate es int en rango 8000-96000; si no, loggea warning y asume 16000
- [x] Si rate === 16000, pasa directamente a `tx_process()` (sin cambios en pipeline)
- [x] Si rate !== 16000, llama `linear_resample_float32()` antes de `tx_process()`
- [x] Se loggea `logger.warning("Fallback resample activated: rate=%d", rate)` cuando fallback se activa
- [x] Backward compatible: mensajes sin campo `rate` funcionan igual que antes
- [x] No se rompen otros tipos de mensaje (ptt, dtmf, ping)

### Dependencies
- **T4**: `linear_resample_float32()` debe existir
