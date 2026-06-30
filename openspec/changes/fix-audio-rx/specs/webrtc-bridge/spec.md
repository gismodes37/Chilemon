# Delta for webrtc-bridge

**Change**: fix-audio-rx
**Type**: Delta — modifies BRIDGE-AUDIO-01, adds BRIDGE-AUDIO-04, adds BRIDGE-AUDIO-05

## MODIFIED Requirements

### BRIDGE-AUDIO-01: Audio Relay with Gapless Playback and Volume Normalization
**Priority**: HIGH

The bridge MUST forward ulaw audio payloads from inbound IAX2 mini frames to all connected WS peers. The server MUST apply volume normalization (BRIDGE-AUDIO-05) before transmission. The client MUST use a lookahead scheduling queue (BRIDGE-AUDIO-04) for dropout-free playback. Audio MUST play continuously for 5+ minutes without gaps. Audio MUST be audible at default gain settings.
(Previously: forwarded ulaw to WS peers — no client-side guarantees or volume normalization)

**Scenario**: Audio from Asterisk forwarded to browser
  Given a call is active (STATE_ACTIVE)
  When the bridge receives an IAX2 mini voice frame
  Then it decodes ulaw via `rx_process()` → float32 PCM 16 kHz
  And sends JSON `{"type":"audio","data":"<hex>"}` to all WS peers

**Scenario**: Sustained playback without dropout
  Given a call is active
  When the bridge receives continuous IAX2 mini frames for 5+ minutes
  Then the client plays audio without gaps or buffer collisions

**Scenario**: Minimum audible volume
  Given a call is active with a low-volume source
  When frames are below -18dBFS RMS
  Then the server normalizes volume via AGC and gain
  And the client plays audio at minimum audible level

## ADDED Requirements

### BRIDGE-AUDIO-04: Client-Side Gapless Audio Playback
**Priority**: HIGH

The client MUST maintain a lookahead scheduling queue of at least 5 pre-buffered audio frames (100ms at 20ms/frame) before starting playback. Each frame MUST be scheduled at `audioCtx.currentTime + i × 0.02s`. Sources MUST be released after `onended` to prevent stop/start races. The AudioContext MUST be created eagerly on user gesture. If `AudioContext.state === 'suspended'`, the client MUST retry `resume()` with exponential backoff (200ms, 1s, 5s) and MUST log every state transition.

**Scenario**: Lookahead queue prevents gaps
  Given a call is active and WS receives audio frames
  When the client receives 5+ consecutive frames
  Then each frame is scheduled at `currentTime + offset`
  And playback is gapless with no stutter

**Scenario**: AudioContext resume with backoff
  Given the AudioContext is in `suspended` state
  When the client attempts playback
  Then it retries `resume()` at 200ms, 1s, 5s intervals
  And logs state transitions

**Scenario**: AudioContext created on user gesture
  Given the user interacts with the page
  When the PTT widget initializes
  Then the AudioContext is created immediately
  And no autoplay policy violation blocks playback

### BRIDGE-AUDIO-05: RX Volume Normalization
**Priority**: HIGH

The server MUST apply a minimum gain of 3.0× to decoded ulaw PCM frames before transmission. The server MUST apply per-frame RMS-based AGC: boost frames below -18dBFS RMS, gate frames at -40dBFS RMS (discard as silence). The server SHOULD use polyphase FIR resampling instead of linear interpolation for resampling to 16kHz.
(Previously: fixed RX_VOLUME gain only — no AGC or silence gate)

**Scenario**: Minimum gain floor applied
  Given a decoded ulaw frame at low amplitude
  When the server applies volume normalization
  Then gain of 3.0× is applied as minimum floor
  And the frame is not clipped

**Scenario**: RMS AGC boost for quiet frames
  Given a decoded ulaw frame at -25dBFS RMS
  When the server computes per-frame RMS
  Then it boosts the frame above -18dBFS threshold
  And applies proportional gain without clipping

**Scenario**: Silence gate at -40dBFS
  Given a decoded ulaw frame at -50dBFS RMS
  When the server computes per-frame RMS
  Then the frame is gated and discarded as silence
  And no audio is forwarded for that frame
