# Delta: webrtc-bridge

**Change**: fix-audio-tx-lento
**Baseline**: `openspec/specs/webrtc-bridge/spec.md`

## ADDED Requirements

### BRIDGE-AUDIO-03: Browser-Side Sample Rate Detection
**Priority**: HIGH

El browser MUST leer `AudioContext.sampleRate` después de crear el contexto de audio. Si `sampleRate != 16000`, el browser MUST hacer downsample del PCM capturado a 16kHz usando un filtro running-sum CIC con anti-aliasing antes de transmitir. Cada mensaje `audio_tx` MUST incluir el campo `"rate"` con la tasa de los datos PCM transmitidos.

**Scenario**: Browser a 16kHz — ruta directa
  GIVEN el AudioContext se creó exitosamente a 16000 Hz
  WHEN se capturan samples via onaudioprocess
  THEN el browser envía los samples crudos sin downsample
  AND el mensaje `audio_tx` incluye `"rate":16000`

**Scenario**: Browser a 48kHz — downsample exitoso
  GIVEN el AudioContext opera a 48000 Hz (fallback por constraint `{sampleRate:16000}` no soportado)
  WHEN se capturan samples via onaudioprocess
  THEN el browser aplica running-sum CIC downsample a 16kHz con anti-aliasing
  AND envía los datos PCM resultantes con `"rate":16000`

**Scenario**: Browser a 48kHz — downsample falla
  GIVEN el AudioContext opera a 48000 Hz
  WHEN el downsample lanza una excepción o no está disponible
  THEN el browser envía los samples crudos a la tasa original
  AND el mensaje `audio_tx` incluye `"rate":48000` para trigger server resample

## MODIFIED Requirements

### BRIDGE-AUDIO-02: Rate-Aware Audio TX (WS → IAX2)
**Priority**: HIGH

El bridge MUST reenviar audio desde mensajes WS `audio_tx` como IAX2 mini voice frames. Cada mensaje `audio_tx` MUST incluir un campo `"rate"` con la tasa de muestreo de los datos. Si `rate != 16000`, el servidor MUST aplicar resample lineal para convertir los datos a 16kHz antes de pasarlos a `tx_process()`. El servidor MUST loggear un warning cuando el fallback resample se activa.
(Previously: Audio Relay (WS → IAX2) — sin campo rate, servidor asumía 16kHz incondicionalmente)

**Scenario**: Rate coincide (16000) — ruta directa
  GIVEN la llamada está activa
  WHEN el bridge recibe `{"type":"audio_tx","data":"<hex>","rate":16000}`
  THEN los datos ya están a 16kHz
  AND pasan directamente a `tx_process()` → ulaw → IAX2 mini frame

**Scenario**: Rate mismatch — server resample
  GIVEN la llamada está activa
  WHEN el bridge recibe `{"type":"audio_tx","data":"<hex>","rate":48000}`
  THEN el servidor detecta `rate != 16000`
  AND aplica `linear_resample_float32()` para convertir de 48kHz a 16kHz
  AND pasa los datos resampleados a `tx_process()` → ulaw → IAX2 mini frame

**Scenario**: Server fallback se activa
  GIVEN la llamada está activa
  WHEN el servidor recibe un `audio_tx` con `rate != 16000`
  AND aplica resample como fallback
  THEN se registra un warning en el log del servidor con la tasa real (ej: "Fallback resample activated: rate=48000")

**Scenario**: Múltiples rates en misma llamada
  GIVEN la llamada está activa
  WHEN el bridge recibe mensajes `audio_tx` consecutivos con diferentes valores de rate (ej: 16000 y 48000)
  THEN cada mensaje se procesa independientemente según su propio campo `rate`

## Unchanged Requirements

Los siguientes requirements de `openspec/specs/webrtc-bridge/spec.md` permanecen sin cambios:
- BRIDGE-AUDIO-01 (Audio Relay IAX2 → WS)
- BRIDGE-DTMF-01 (DTMF Relay)
- BRIDGE-SERVER-01 (IAX2 Server Mode)
- BRIDGE-SERVER-02 (Call Establishment)
- BRIDGE-ORIGINATE-01 (WS Auth Triggers AMI Originate)
- BRIDGE-HANGUP-01 (Remote HANGUP)
- BRIDGE-HANGUP-02 (WS Client Disconnect)
