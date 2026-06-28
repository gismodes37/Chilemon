# Apply Progress: fix-audio-tx-lento

**Estado**: COMPLETADO — 5/5 tareas implementadas

## Resumen

Se implementó la corrección de velocidad TX (slow-motion 3×) en el WebRTC Audio Bridge. La causa raíz era que el browser captura micrófono a 48kHz pero el servidor asumía 16kHz, decimando incorrectamente. Se aplicó un fix dual-layer: downsample CIC en browser + resample lineal de safety net en servidor.

## Tareas Completadas

### ✅ T1: `_downsampleCIC()` en ptt-widget.js
- Nuevo método `_downsampleCIC(samples, actualRate)` en clase `PTTWidget`
- Running-sum CIC stage-1: M = round(actualRate/16000), decimate + average
- Passthrough cuando M <= 1 (ya estamos a 16kHz)
- `console.warn` informativo cuando downsample está activo
- Archivo: `public/assets/js/ptt-widget.js:520-537`

### ✅ T3: Campo `rate` en `audio_tx` y firma de `_sendAudioChunk`
- Firma cambiada a `_sendAudioChunk(samples, rate = 16000)`
- JSON del mensaje incluye `"rate": rate`
- Default 16000 asegura backward compatibility
- Archivo: `public/assets/js/ptt-widget.js:548-569`

### ✅ T2: Modificar `onaudioprocess`
- Lee `this._txCtx.sampleRate` en cada callback
- Si 16000: passthrough directo
- Si ≠ 16000: intenta `_downsampleCIC()` dentro de try/catch
- Si CIC falla: envía raw data con rate real (server fallback)
- Archivo: `public/assets/js/ptt-widget.js:454-473`

### ✅ T4: `linear_resample_float32()` en server.py
- Función a nivel de módulo (junto a `_env_str`/`_env_int`)
- Interpolación lineal pura con `struct` de la stdlib
- Passthrough cuando in_rate == out_rate
- Boundary check para último sample
- Archivo: `app/Services/WebRTCBridge/server.py:92-117`

### ✅ T5: Modificar handler `audio_tx` en server.py
- Lee campo `rate` con default 16000 (backward compat)
- Validación: int en rango 8000-96000; si inválido, warning + asume 16000
- Si rate ≠ 16000: aplica `linear_resample_float32()` + warning log
- Todo dentro del try/except existente (ValueError, KeyError)
- Log de debug actualizado para incluir rate
- Archivo: `app/Services/WebRTCBridge/server.py:475-506`

## Desviaciones del Design

- **Ninguna**. La implementación sigue exactamente el diseño, las firmas de funciones y el flujo de datos especificados.

## Detalles Técnicos

### Browser-side (ptt-widget.js)
- `_downsampleCIC()` usa `for` loops nativos sin `Array.map`/`reduce`
- Running-sum + división por M normaliza ganancia (sin clipping)
- El `console.warn` del CIC downsample usa formato `"[ChileMon] CIC downsample: %d->16000 Hz, M=%d"`
- El `console.warn` de fallback usa formato `"[ChileMon] CIC downsample failed, raw data sent:"`

### Server-side (server.py)
- `linear_resample_float32()` es puro Python, sin dependencias externas
- Import de `struct` es local dentro de la función (no contamina imports de módulo)
- El log de warning del fallback es: `"Fallback resample activated: rate=%d"`
- El log de debug actualizado: `"audio_tx sent %d bytes ulaw (rate=%d)"`

## Scope del Cambio
- ~60 líneas nuevas / modificadas en `ptt-widget.js`
- ~40 líneas nuevas / modificadas en `server.py`
- **Total: ~100 líneas** — muy por debajo del budget de 400 líneas
