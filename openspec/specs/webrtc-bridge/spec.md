# WebRTC Bridge — Full Spec (Replaces IAX2 Client)

**Domain**: `webrtc-bridge`
**Change**: `bridge-reversal`
**Type**: Full spec (no baseline — replaces legacy IAX2-client behavior)

## ADDED Requirements

### BRIDGE-SERVER-01: IAX2 Server Mode
**Priority**: HIGH

The bridge MUST listen on UDP port 9092 for inbound IAX2 frames. On receiving a `NEW` frame (callno=0), the bridge MUST allocate a local call number and respond with `NEWACK`.

**Scenario**: Incoming IAX2 NEW from Asterisk
  Given the bridge is listening on UDP 9092
  When Asterisk sends an IAX2 NEW frame (dest callno=0, called number = node)
  Then the bridge allocates a new source call number
  And responds with NEWACK containing the allocated call number
  And transitions to `STATE_CALLING`

**Scenario**: Invalid frame on listener port
  Given the bridge is listening on UDP 9092
  When a malformed frame is received
  Then the bridge silently drops it and logs a warning
  And continues listening

### BRIDGE-SERVER-02: Call Establishment
**Priority**: HIGH

After NEWACK, the bridge MUST wait for `ANSWER` control frame from Asterisk. On ANSWER, the bridge transitions to `STATE_ACTIVE` and enables bidirectional audio relay.

**Scenario**: Call answered
  Given the bridge has responded with NEWACK to an incoming NEW
  When Asterisk sends a CONTROL ANSWER frame
  Then the bridge transitions to STATE_ACTIVE
  And broadcasts "in_call: true" status to all WS peers
  And audio relay begins

### BRIDGE-ORIGINATE-01: WS Auth Triggers AMI Originate
**Priority**: HIGH

When a WebSocket client successfully authenticates, the bridge MUST call `ami.originate()` instead of the legacy IAX2 `register()` + `start_call()` flow.

**Scenario**: WS connect initiates call
  Given a WebSocket client connects with a valid HMAC token
  When the bridge's WS handler acknowledges the connection
  Then the bridge calls `ami.originate()` to the configured ASL node
  And the bridge enters a waiting state for the incoming IAX2 NEW

### BRIDGE-AUDIO-01: Audio Relay (IAX2 → WS)
**Priority**: HIGH

The bridge MUST forward ulaw audio payloads from inbound IAX2 mini frames to all connected WS peers.

**Scenario**: Audio from Asterisk forwarded to browser
  Given a call is active (STATE_ACTIVE)
  When the bridge receives an IAX2 mini voice frame
  Then it decodes ulaw via `rx_process()` → float32 PCM 16 kHz
  And sends JSON `{"type":"audio","data":"<hex>"}` to all WS peers

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

### BRIDGE-DTMF-01: DTMF Relay
**Priority**: MEDIUM

The bridge MUST forward DTMF digits from WS messages as IAX2 DTMF full frames.

**Scenario**: DTMF from browser
  Given a call is active
  When the bridge receives WS message `{"type":"dtmf","digit":"*"}`
  Then it sends an IAX2 DTMF frame with the corresponding digit subclass

### BRIDGE-HANGUP-01: Remote HANGUP
**Priority**: HIGH

When Asterisk sends an IAX2 HANGUP frame, the bridge MUST reset call state and notify WS peers.

**Scenario**: Asterisk hangs up
  Given a call is active
  When the bridge receives an IAX2 HANGUP frame
  Then it resets `_call_active = False` and `_ptt_active = False`
  And broadcasts status to WS peers

### BRIDGE-HANGUP-02: WS Client Disconnect
**Priority**: MEDIUM

When the last WS client disconnects, the bridge MUST send IAX2 HANGUP to Asterisk.

**Scenario**: Last peer disconnects
  Given a call is active with one WS peer
  When that peer disconnects
  Then the bridge sends an IAX2 HANGUP frame
  And resets call state

## REMOVED Requirements (Legacy IAX2 Client)

### BRIDGE-LEGACY-01: IAX2 Registration (Reason: ASL3 drops inbound callno=0)
- Bridge no longer sends REGREQ or manages registration state
- Bridge no longer calls `iax.register()` on startup
- Bridge no longer maintains `is_registered` state

### BRIDGE-LEGACY-02: IAX2 Outbound NEW (Reason: ASL3 drops inbound callno=0)
- Bridge no longer sends `start_call()` with outbound NEW
- Bridge no longer waits for NEWACK as initiator
