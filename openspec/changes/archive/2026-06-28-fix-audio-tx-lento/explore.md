# Exploration: Audio TX lento en WebRTC Bridge

## Current Pipeline TX

### Diagrama ASCII (Browser → Asterisk)

```
BROWSER (ptt-widget.js)
┌─────────────────────────────────────────────────────────────┐
│ getUserMedia({audio: true})                                 │
│   ↓                                                         │
│ AudioContext({sampleRate: 16000})   ← TRY, fallback=default│
│   ↓                                                         │
│ MediaStreamSourceNode                                       │
│   → GainNode (TX gain 0..2)                                 │
│   → ScriptProcessorNode(1024, 1, 1)                         │
│   → connect(destination)    ← necesario para que fire       │
│   ↓                                                         │
│ onaudioprocess(e) cada ~N ms:                               │
│   input = e.inputBuffer.getChannelData(0)                   │
│   → Float32Array[1024]   ← 1024 samples de audio crudo      │
│   ↓                                                         │
│ _samplesToHex(input):                                       │
│   1024 × Float32 → 4096 bytes → 8192 hex chars             │
│   ↓                                                         │
│ ws.send(JSON.stringify({type:"audio_tx", data:hex}))        │
│   → WebSocket (JSON string, ~8.5KB por mensaje)             │
└─────────────────────────────────────────────────────────────┘
         │
         │ WebSocket (ws://host:9091/ws)
         ▼

SERVER (server.py)
┌─────────────────────────────────────────────────────────────┐
│ _handle_ws_message():                                       │
│   msg = json.loads(data)     ← type="audio_tx"             │
│   pcm_f32 = bytes.fromhex(hex)   ← 4096 bytes raw float32 │
│   ↓                                                         │
│ tx_process(pcm_f32)  [audio.py]:                           │
│   float32_to_s16(): 4096B → 2048B s16le  ← ASiGNE 16kHz  │
│     unpack(1024 floats) → clamp → pack(1024 shorts)        │
│   ↓                                                         │
│   resample_16k_to_8k(): 2048B → 1024B s16le               │
│     decimate simple: toma cada 2da muestra                  │
│     1024 shorts → 512 shorts                                │
│   ↓                                                         │
│   pcm_to_ulaw(): 1024B → 512B ulaw                         │
│     audioop.lin2ulaw(s16, 2)                                │
│   ↓                                                         │
│ send_voice(ulaw)  [iax2.py]:                                │
│   Mini frame: [0x8000|dst_callno(15b)][ts_low(16b)]        │
│   + 512B ulaw payload                                       │
│   → UDP a Asterisk:4569                                      │
└─────────────────────────────────────────────────────────────┘
         │
         │ UDP (mini frame)
         ▼

ASTERISK (chan_iax2)
┌─────────────────────────────────────────────────────────────┐
│ Recibe mini frame con 512B ulaw                             │
│ Trata como ulaw a 8kHz → 64ms de audio                     │
│ Envía a la radio vía DAHDI/IAX al nodo remoto               │
└─────────────────────────────────────────────────────────────┘

FORMA DE ONDA REAL (1024 samples period):
  16kHz: 1024 samples = 64ms de audio → 512 ulaw = 64ms ✓
  48kHz: 1024 samples = 21ms de audio → 512 ulaw = 64ms → 3× SLOW ❌
```

## Root Cause Analysis

### Bug Primario: Sample Rate Mismatch (48kHz → tratado como 16kHz)

**Mecanismo exacto:**

1. `ptt-widget.js:432-438` intenta crear un `AudioContext` a 16kHz:
   ```javascript
   try {
       this._txCtx = new AudioContext({sampleRate: 16000});
   } catch (_) {
       this._txCtx = new AudioContext();  // ← 48kHz en la mayoría de browsers
   }
   ```

2. `AudioContext({sampleRate: 16000})` NO es universalmente soportado:
   - Chrome 60+ (2017) → OK
   - Firefox 98+ (2022) → OK
   - Safari 16.4+ (2023) → OK
   - Firefox < 98 → **falla**, Safari < 16.4 → **falla**
   - Algunas configuraciones de Chrome en Linux → puede ignorar el hint

3. Cuando el constructor falla (`catch`), el `AudioContext` se crea a la tasa por defecto del hardware: **48kHz** (casi siempre).

4. `ScriptProcessorNode(1024, 1, 1)` entrega **siempre 1024 muestras por callback**, independientemente de la sample rate:
   - A 16kHz: 1024 muestras = 64ms de audio (correcto)
   - A 48kHz: 1024 muestras = 21.3ms de audio (real)

5. El servidor (`audio.py:133-143`) ejecuta `resample_16k_to_8k()` que es **decimación simple** (toma cada 2da muestra):
   - Asume entrada a 16kHz → 1024 → 512 muestras → 64ms de ulaw a 8kHz
   - Real (48kHz): 1024 → 512 muestras → PERO esto es solo 21ms de audio real reproducido como 64ms
   - **Resultado: audio 3× más lento (slow motion).**

6. **Por qué no es "audio más rápido" si mandamos más datos:**
   A 48kHz los callbacks disparan cada ~21ms. El servidor recibe audio_tx 3× más frecuente (47 msg/s vs 15 msg/s). Cada mensaje produce 512B ulaw. Asterisk recibe 24KB/s de ulaw cuando espera 8KB/s. El jitter buffer de Asterisk se acumula y/o descarta frames, pero incluso cuando reproduce, cada frame de 512 ulaw samples se reproduce como 64ms de audio cuando solo contiene 21ms de información real. El efecto neto es **cámara lenta**.

### Bug Secundario: Decimación sin anti-aliasing

`resample_16k_to_8k()` en `audio.py:133-143`:
```python
def resample_16k_to_8k(pcm_16k: bytes) -> bytes:
    samples = len(pcm_16k) // 2
    data = struct.unpack(f"<{samples}h", pcm_16k)
    decimated = data[0::2]
    return struct.pack(f"<{len(decimated)}h", *decimated)
```

- **No hay filtro anti-aliasing.** Para 16kHz→8kHz debería aplicar un low-pass en 4kHz antes de decimar.
- El comentario dice "safe because the input has already been bandlimited to 4 kHz by the ulaw codec" — esto es **incorrecto para el TX path** porque el ulaw NO se ha aplicado antes del resample. El resample ocurre en PCM s16, antes de `pcm_to_ulaw()`.
- En la práctica, para voz humana, el aliasing puede ser menor (la mayor parte de la energía vocal está bajo 4kHz), pero no es correcto.

## Secondary Issues

### 1. Hex Encoding Overhead Innecesario

- 1024 float32 → 4096 bytes → 8192 hex chars → JSON envuelto (~200 chars overhead) → ~8.5KB por mensaje
- A 16kHz: ~133 KB/s de tráfico WS
- A 48kHz (pero tratado como 16kHz): ~400 KB/s
- **Podría enviarse como binario directo (ArrayBuffer)**, reduciendo payload a la mitad (~4.3KB/mensaje) y evitando parsing de hex en ambos lados.
- `ws.binaryType = 'arraybuffer'` ya está configurado en línea 172, pero el TX usa JSON con hex en vez de ArrayBuffer.

### 2. ScriptProcessorNode Deprecado

- `createScriptProcessor()` está **deprecado desde 2014** (Web Audio API v1 spec).
- Reemplazo: `AudioWorklet` (https://developer.mozilla.org/en-US/docs/Web/API/AudioWorklet)
- ScriptProcessor no estándar: causa problemas de latencia en mobile, puede tener glitches en buffer underrun, y ejecuta el callback en el hilo principal (bloquea UI).
- AudioWorklet corre en un hilo separado (AudioWorkletGlobalScope) con latencia predecible.

### 3. Sin Verificación de Sample Rate Real

El `AudioContext.sampleRate` es una **propiedad read-only** disponible después de la creación del contexto. Ninguna parte del código verifica cuál fue la tasa real asignada. Podría loggearse y/o enviarse al servidor con el primer mensaje de audio.

### 4. Sin Jitter Buffer ni Rate Matching en el Servidor

Cada `audio_tx` se procesa y envía inmediatamente como mini frame IAX2. No hay:
- Buffer de alisado (smoothing)
- Rate limiting / metering
- ACKs de vuelta del browser (el servidor recibe UDP voice de Asterisk sin confirmación tampoco)

Si el navegador envía audio más rápido de lo que Asterisk puede consumir, los frames IAX2 se pierden en UDP (Asterisk no responde ACK porque envía ulaw como mini frames sin ACK rápido). Esto puede causar pérdida de audio.

### 5. Audio RX No Tiene el Mismo Problema

El RX path (Asterisk → browser) usa `rx_process()` que va de ulaw 8kHz → s16 8kHz → resample lineal a 16kHz → float32 16kHz. El servidor envía explícitamente `"rate": 16000` en el JSON, y `_playAudioBuffer` usa ese rate en `createBuffer(1, samples.length, sampleRate)`. Web Audio API se encarga de la conversión a la tasa del hardware. **RX está correcto.**

### 6. `_handleAudioBuffer` Hardcodea 16000 (Línea 385)

```javascript
this._playAudioBuffer(floats, 16000);
```
Este camino solo se ejecuta si el servidor envía ArrayBuffer en vez de hex JSON. Actualmente el servidor siempre envía hex, pero si en el futuro se cambia a binario, este hardcodeo podría ser incorrecto.

## Files Affected

| Archivo | Líneas Clave | Por qué |
|---------|-------------|---------|
| `public/assets/js/ptt-widget.js` | 432-438, 452, 502-512, 515-533 | Fix sample rate fallback, hex encoding, ScriptProcessor |
| `app/Services/WebRTCBridge/audio.py` | 133-173, 180-195 | Mejorar resample con anti-aliasing, soporte multi-rate |
| `app/Services/WebRTCBridge/server.py` | 448-462 | Recibir sample rate real, encolar/buffer audio |
| `public/assets/js/dashboard.js` | 1344 | Test tone (no urgente, pero referencia) |

## Solution Options

### Option A: Fix Browser Fallback + Server-side Rate Check

**Descripción:** En el browser, detectar la sample rate real del AudioContext y enviarla al servidor. El servidor usa la tasa real para hacer resample correcto.

**Browser changes:**
```javascript
this._txCtx = new AudioContext({sampleRate: 16000});  // intentar
// catch → lo mismo pero sin sampleRate
const actualRate = this._txCtx.sampleRate;  // leer tasa real
```
Enviar `actualRate` en el primer `audio_tx` o en cada mensaje.

**Server changes:**
- Recibir `rate` en el mensaje `audio_tx`
- Si `rate != 16000`, hacer resample de `rate` → 16000 antes de `tx_process`
- Usar `scipy.signal.resample` o implementar un polyphase filter bank

**Pros:**
- Mínimo cambio en frontend
- Backend fix cubre todos los casos (48kHz, 44.1kHz, etc.)
- Sin dependencias nuevas (scipy/samplerate opcional)

**Cons:**
- Server-side resample introduce latencia adicional
- `scipy` es dependency pesada para el bridge
- Sin anti-aliasing si se sigue usando decimación simple

**Effort:** Medium

---

### Option B: Browser-side Resampling a 16kHz (Robusto)

**Descripción:** El browser siempre crea AudioContext a la tasa por defecto del hardware (48kHz). Dentro de `onaudioprocess`, se hace downsample manual de 48kHz→16kHz usando un filtro FIR simple (decimation con anti-aliasing via running sum / cascaded integrator-comb).

```javascript
// onaudioprocess callback
const input = e.inputBuffer.getChannelData(0);
const actualRate = this._txCtx.sampleRate;
if (actualRate !== 16000) {
    const ratio = actualRate / 16000;
    // Downsample con CIC filter o linear interpolation
    const output = downsample(input, ratio);
    this._sendAudioChunk(output);
} else {
    this._sendAudioChunk(input);
}
```

**Pros:**
- El servidor siempre recibe 16kHz → no requiere cambios en `audio.py`
- Latencia mínima (procesamiento local en el browser)
- Funciona con cualquier browser, cualquier sample rate
- El servidor no necesita lógica de resample

**Cons:**
- Implementar downsample correcto con anti-aliasing no es trivial en JS
- Consume CPU del lado del cliente
- AudioWorklet sería mejor que ScriptProcessor para esto

**Effort:** Medium

---

### Option C: AudioWorklet + WebSocket Binario (Recomendado)

**Descripción:** Reemplazar ScriptProcessorNode con AudioWorkletProcessor para captura de micrófono. El AudioWorklet:
1. Corre en hilo dedicado (sin bloquear UI)
2. Recibe audio a la tasa nativa del hardware
3. Hace downsample a 16kHz con filtro anti-aliasing
4. Envía Float32Array como ArrayBuffer binario por WS (no hex)

Además:
- El servidor recibe ArrayBuffer binario → elimina overhead de hex
- El servidor usa un resample polifásico (con numpy/scipy o implementación manual) como fallback

**Pros:**
- Calidad de audio superior (sin glitches de ScriptProcessor)
- Latencia más baja y predecible
- Sin overhead de hex encoding (~50% menos ancho de banda WS)
- Corrección de sample rate en el origen
- Arquitectura moderna (Web Audio API v2)

**Cons:**
- AudioWorklet requiere archivo .js separado (worklet)
- ScriptProcessor necesita mantenerse como fallback para browsers viejos
- Mayor esfuerzo de implementación

**Effort:** High (pero es la solución correcta a largo plazo)

---

### Option D: Quick Fix — Enviar sample rate + Server Linear Resample

**Descripción:** Mínimo cambio para resolver el bug inmediato:
1. En el browser, leer `this._txCtx.sampleRate` después de crear el AudioContext
2. En cada `audio_tx`, incluir `"rate": actualRate`
3. En el servidor, si `rate != 16000`, hacer resample lineal antes de `tx_process`

```python
# server.py
actual_rate = payload.get("rate", 16000)
pcm_f32 = bytes.fromhex(pcm_f32_hex)
if actual_rate != 16000:
    # Resample lineal: ratio = 16000/actual_rate
    samples = len(pcm_f32) // 4
    out_len = int(samples * 16000 / actual_rate)
    # simple linear interpolation
    pcm_f32 = linear_resample_float32(pcm_f32, out_len)
ulaw = tx_process(pcm_f32)
```

**Pros:**
- Mínimo cambio en frontend (agregar `rate` al JSON)
- Backend fix simple con interpolación lineal
- Soluciona el bug inmediato

**Cons:**
- Interpolación lineal sin anti-aliasing → posible aliasing/artefactos
- No resuelve el overhead de hex ni ScriptProcessor deprecado
- Solución temporal

**Effort:** Low

---

## Recommendation

**Option B (Browser-side resampling) + mantener ScriptProcessor como fallback temporal.** Este enfoque:
1. No requiere cambiar el servidor (`audio.py` sigue recibiendo 16kHz como siempre)
2. Es independiente del browser (funciona con cualquier sample rate)
3. Se puede implementar sin AudioWorklet (que requiere más cambios arquitectónicos)

Para la implementación a corto plazo, también agregar Option D (enviar `rate` al servidor) como fallback de seguridad.

**Plan sugerido:**
1. **Quick fix (urgente):** En ptt-widget.js, leer `sampleRate` real del AudioContext y downsample manual a 16kHz en `onaudioprocess`. Además, enviar `rate` en cada `audio_tx` para que el servidor pueda verificar.
2. **Server-side safety net:** En server.py, si rate != 16000, hacer resample lineal en el servidor como respaldo.
3. **Medio plazo:** Migrar a AudioWorklet + envío binario (ArrayBuffer) para eliminar ScriptProcessor y hex overhead.
4. **Opcional:** Agregar filtro anti-aliasing en `resample_16k_to_8k()` (aunque el efecto es menor para voz).

## Risks

- **Option A** (server-side rate check): Si se usa scipy, agrega dependencia pesada. Sin scipy, implementar resample polifásico correctamente es complejo.
- **Option B** (browser downsample): Si el downsample se hace mal (sin anti-aliasing), puede introducir artefactos de audio. Validar con prueba de tonos.
- **Option C** (AudioWorklet): No disponible en Safari < 14.5 y algunos browsers embedded. ScriptProcessor fallback es necesario.
- **General:** Cualquier cambio en el pipeline de audio debe probarse con audio real (voz) y tonos de prueba para verificar que la frecuencia percibida es correcta.

## Ready for Proposal
Yes. Las opciones están claras. Recomiendo empezar con Option D + Option B como quick fix, y planificar Option C como mejora de mediano plazo.
