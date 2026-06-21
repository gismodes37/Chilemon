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

### BRIDGE-AUDIO-02: Audio Relay (WS → IAX2)
**Priority**: HIGH

The bridge MUST forward audio from WS `audio_tx` messages as IAX2 mini voice frames.

**Scenario**: Audio from browser forwarded to Asterisk
  Given a call is active
  When the bridge receives WS message `{"type":"audio_tx","data":"<hex>"}`
  Then it encodes via `tx_process()` → ulaw
  And sends the ulaw payload as an IAX2 mini frame to Asterisk

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
