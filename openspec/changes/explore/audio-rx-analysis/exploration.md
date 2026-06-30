# Exploration: RX Audio Issues — WebRTC Audio Bridge

**Status**: Success
**Date**: 2026-06-30
**Author**: sdd-explore sub-agent

## Summary

The ChileMon WebRTC Audio Bridge has two distinct RX audio problems: (1) audio cuts out after 30-40 seconds, and (2) received volume is very low. The root cause of the cutout is the client-side playback architecture — each 20ms audio frame creates a separate `AudioBufferSourceNode` in a fire-and-forget pattern without proper scheduling, leading to accumulated timing drift and eventual audible gaps. The low volume is caused by ulaw's limited dynamic range for quiet signals combined with naive linear-interpolation upsampling that attenuates already-quiet content. Supermon-ng does NOT implement audio streaming at all — it only relays node status data via WebSocket.

---

## 1. Current Audio Pipeline (End-to-End)

### RX Path: Asterisk → Browser

```
Asterisk/app_rpt
  │  app_rpt generates ulaw audio (8 kHz, 20ms frames = 160 bytes)
  │  Sends IAX2 mini voice frames via UDP to bridge:9092
  ▼
IAX2Server._on_datagram()
  │  IAX2_UDP → IAX2Call.handle_frame() → call.on_voice(ulaw_payload)
  ▼
WebRTCBridgeApp._on_iax_audio(ulaw_payload) — server.py:255
  │  calls rx_process(ulaw_payload) — audio.py:198
  │    ├─ ulaw_to_pcm() → PCM s16le @ 8 kHz (audioop.ulaw2lin)
  │    ├─ resample_8k_to_16k() → PCM s16le @ 16 kHz (linear interpolation)
  │    ├─ s16_to_float32() → float32 array @ 16 kHz
  │    └─ RX_VOLUME=1.5 gain boost (50%)
  │  Result: hex-encoded float32 PCM bytes
  │  Sends JSON: {"type":"audio", "data":"<hex>", "rate":16000}
  ▼
WebSocket → Browser
  │
  ▼
PTTWidget._handleHexAudio() — ptt-widget.js:369
  │  hex → Float32Array → compute RMS for volume bar
  │  → _playAudioBuffer(samples, 16000) — ptt-widget.js:420
  │    ├─ Lazy-create AudioContext (if first call)
  │    ├─ Create GainNode (_rxGainNode) → connect to destination
  │    ├─ Stop + disconnect previous AudioBufferSourceNode
  │    ├─ Create new AudioBuffer(1, N, 16000) from float32 data
  │    ├─ Create new AudioBufferSourceNode → connect to _rxGainNode
  │    └─ .start(0) — play immediately
  ▼
AudioContext → speakers
```

### TX Path: Browser → Asterisk

```
Microphone → getUserMedia → ScriptProcessorNode(1024, 1, 1) @ ~16 kHz
  │  onaudioprocess → _sendAudioChunk(input, actualRate)
  │  hex-encode float32 → JSON: {"type":"audio_tx", "data":"<hex>", "rate":<rate>}
  ▼
WebSocket → Python Bridge
  │  server.py:_handle_ws_message type="audio_tx" → tx_process(pcm_f32)
  │    ├─ float32_to_s16()
  │    ├─ resample_16k_to_8k() (if needed, fallback resample)
  │    └─ pcm_to_ulaw() → 8-bit ulaw @ 8 kHz
  │  → IAX2Call.send_voice(ulaw) → IAX2 mini frame → Asterisk
  ▼
Asterisk IAX2 → app_rpt → RF transmission
```

### Codec Details

| Stage | Format | Sample Rate | Bit Depth | Bytes/Frame |
|-------|--------|-------------|-----------|-------------|
| Asterisk IAX2 | ulaw (G.711u) | 8 kHz | 8-bit | 160 (20ms) |
| Bridge internal | PCM s16le | 8 kHz → 16 kHz | 16-bit | 320 → 640 |
| Bridge output | float32 | 16 kHz | 32-bit | 1280 (20ms) |
| WebSocket wire | hex(float32) | — | — | 2560 hex chars |
| Browser AudioContext | float32 | 16 kHz | 32-bit | 320 floats/frame |

### IAX2 Call Flow (from AMI perspective)

```
Browser WS connects → server.py:handle_ws()
  → AMI Originate(Channel=IAX2/webrtc-bridge/{ASL_NODE})
  → Asterisk sends IAX2 NEW frame to bridge:9092
  → IAX2Server creates IAX2Call, sends NEWACK, ACCEPT, ANSWER
  → Audio flows bidirectionally via mini voice frames
  → DTMF "*" toggles PTT (simplex mode via rpt phone extension)
```

---

## 2. Root Cause Analysis

### Issue A: Audio Cuts Out After 30-40 Seconds

**Root cause: Per-frame AudioBufferSourceNode scheduling model without proper timing management.**

The client playback in `_playAudioBuffer()` (ptt-widget.js:420-447) has these problems:

1. **No streaming architecture**: Each 20ms audio frame creates a brand-new `AudioBufferSourceNode`. This is fundamentally a "play this blob now" approach, not a streaming approach. With frames arriving at ~50/second (every 20ms), the browser creates and destroys 50+ short-lived audio nodes per second.

2. **Race condition in stop/start**: When a new frame arrives, the previous source is force-stopped with `.stop()` before the new one starts with `.start(0)`. This requires precise timing — if the stop() happens in the middle of a buffer playback, there's a small dropout. Over 30-40 seconds, accumulated scheduling jitter creates audible gaps.

3. **AudioContext state management**: The code resumes suspended contexts but `resume()` returns a promise that is caught silently: `.catch(() => {})`. Chrome aggressively suspends AudioContexts on non-user-initiated audio. If the context suspends and resume fails (autoplay policy), audio stops entirely and silently.

4. **Garbage collection pressure**: Creating ~50 `AudioBufferSourceNode` objects per second = 1500-2000 objects in 30-40 seconds. Chrome's GC may introduce periodic hiccups that disrupt the fragile stop/start chain.

5. **No scheduling queue**: The proper Web Audio API approach for streaming is to schedule multiple `AudioBufferSourceNode` instances with precise start times using `audioCtx.currentTime + offset` ahead of time, creating a lookahead queue. The current approach always uses `.start(0)` ("play NOW"), which creates tight coupling between network delivery timing and audio playback timing.

### Issue B: Very Low Volume

**Root cause: ulaw's limited dynamic range for quiet signals + naive linear-interpolation upsampling.**

1. **ulaw companding**: G.711 ulaw is a companding codec designed for telephone voice. It provides good SNR for loud signals (~38dB) but quantizes quiet signals more coarsely. If the received RF signal is weak or the talker is far from the repeater, ulaw encodes at very low levels.

2. **Linear interpolation upsampling (8→16 kHz)**: The `resample_8k_to_16k` function (audio.py:146-173) uses linear interpolation:
   ```python
   result.append(int((a + b) / 2))
   ```
   For low-amplitude signals where consecutive samples have very small values, the interpolated midpoints are even smaller, reducing perceived loudness. A proper upsampler should use a low-pass interpolation filter (sinc or polyphase) that preserves signal energy.

3. **Server volume boost is insufficient**: `RX_VOLUME = 1.5` (50% boost). The client slider allows 0-200% (default 100%). Even at maximum (2.0 on client × 1.5 on server = 3.0x total), if the original ulaw signal is very quiet, amplifying noise along with signal creates a poor experience.

4. **Float32 precision is NOT the issue**: The float32 ↔ s16 conversion in `s16_to_float32()` divides by 32768.0 and `float32_to_s16()` multiplies by 32767.0, which is correct. No precision loss here.

5. **No input normalization**: There is no AGC (automatic gain control) or RMS normalization on the RX path. A quiet signal stays quiet throughout the pipeline.

---

## 3. Supermon-ng Comparison

**Key finding: Supermon-ng does NOT implement audio streaming or WebRTC at all.**

Supermon-ng's frontend (`laboratorio/supermon/frontend/src/services/WebSocketService.ts`) uses WebSocket exclusively for **node status data** (node state, activity, connected peers, system metrics). It does not:
- Capture microphone audio
- Play received audio from nodes
- Use WebRTC or AudioContext
- Implement PTT functionality

The WebSocket message types for supermon-ng are structured data only:
```typescript
interface WebSocketMessage {
  node: string; timestamp: number; status?: string;
  cos_keyed?: number; tx_keyed?: number;
  remote_nodes?: Array<{node, info, ip, direction, ...}>;
  // ... metadata fields only
}
```

ChileMon's WebRTC Audio Bridge is a **novel feature** not present in supermon-ng. There is no existing reference implementation in the ASL dashboard ecosystem for browser-based audio streaming.

---

## 4. Alternative ASL Audio Approaches

| Approach | Pros | Cons | Complexity |
|----------|------|------|------------|
| **A) Fix current architecture** | Minimal code change, uses existing IAX2/AMI bridge | Architecture is fundamentally fragile — stop/start race is inherent | Medium |
| **B) AudioWorklet streaming** | Proper sample-level scheduling, no GC pressure, gapless | Requires rewriting AudioContext management, AudioWorklet thread | High |
| **C) Opus over WebSocket** | Eliminates ulaw transcoding, better quality at lower bitrate | Requires opus.js or WASM decoder in browser, changes server pipeline | Medium |
| **D) app_rpt RTCP direct** | Could stream raw PCM directly from app_rpt | Complex, non-standard, requires Asterisk module development | Very High |
| **E) SIP/IAX2 in browser** | Direct protocol in-browser, no bridge server needed | Requires JS IAX2/SIP stack, complex state machine, no existing library | Very High |

**Recommendation**: Option A (fix current architecture) for immediate relief, then Option B (AudioWorklet) as a medium-term rewrite of the client playback path.

---

## 5. Top 3 Things to Fix (Ordered by Impact)

### 1. Fix the client-side audio scheduling (HIGHEST IMPACT — fixes cutout)

**Current**: Create/stop/replace `AudioBufferSourceNode` per frame.
**Fix**: Use a lookahead scheduling approach:
- Create multiple scheduled `AudioBufferSourceNode` buffers, each started at `audioCtx.currentTime + (i * frameDuration)`
- Use a queue of 5-10 buffered frames ahead of current playback position
- When buffers finish, recycle or discard
- This eliminates the stop/start race and provides gapless playback

Alternative simpler fix:
- Instead of stopping the previous source, let it play to completion
- Queue the new source to start immediately after via `stop(0)` on prev + `start(0)` on new
- This still has a race but eliminates the mid-buffer cutoff

**Files**: `public/assets/js/ptt-widget.js` - `_playAudioBuffer()` method
**Effort**: 1-2 days

### 2. Improve server-side volume handling (HIGH IMPACT — fixes low volume)

**Current**: Static `RX_VOLUME = 1.5` with no normalization.
**Fix**:
- Increase `RX_VOLUME` to 2.0-3.0 (safe since float32 is [-1.0, 1.0] clamped)
- Implement simple RMS-based AGC: measure frame RMS, boost quiet frames more, loud frames less
- Replace linear-interpolation upsampling with a proper polyphase or sinc resampler (e.g., scipy.signal.resample or a simple 4-tap FIR, or even better — just keep audio at 8 kHz and let the AudioContext handle resampling)
- BONUS: Keep audio at 8 kHz on the wire (smaller frames) and resample on the client side where AudioContext handles it natively

**Files**: `app/Services/WebRTCBridge/audio.py` - `rx_process()` and `RX_VOLUME`
**Effort**: 1-2 days

### 3. Make client AudioContext robust against suspension (MEDIUM IMPACT — prevents silent failures)

**Current**: `.resume()` promise is caught silently.
**Fix**:
- Log failures to console for debugging
- Retry resume with exponential backoff
- Consider creating AudioContext on user gesture (PTT button first click) to satisfy autoplay policy before any audio playback
- Use `AudioContext.state` change listener to auto-resume
- Create the AudioContext eagerly at widget init (during user gesture in init flow) rather than lazily at first audio frame arrival

**Files**: `public/assets/js/ptt-widget.js` - `_playAudioBuffer()` and `init()`
**Effort**: 0.5 days

---

## Files Examined

| File | Role |
|------|------|
| `app/Services/WebRTCBridge/server.py` | Python bridge server — WebSocket, IAX2 server, AMI client orchestration |
| `app/Services/WebRTCBridge/audio.py` | Audio transcoding: ulaw ↔ PCM s16le ↔ float32, resampling 8↔16 kHz |
| `app/Services/WebRTCBridge/iax2.py` | IAX2 protocol handler — mini frames, full frames, DTMF, call state machine |
| `app/Services/WebRTCBridge/ami_client.py` | Async AMI client — Originate, event monitoring, reconnect |
| `public/assets/js/ptt-widget.js` | Client-side PTTWidget: WebSocket, AudioContext, mic capture, audio playback |
| `public/assets/js/audio-visualizer.js` | FFT-based spectrum visualizer (canvas rendering) |
| `public/assets/js/dashboard.js` | Dashboard main logic — polling, favorites, audio settings modal |
| `public/assets/css/dashboard.css` | PTT widget styles, visualizer styles, audio slider styles |
| `public/views/dashboard.php` | Dashboard PHP view — Audio Settings modal, PTT button, canvas element |
| `install/asterisk/iax.conf` | IAX2 phone extension config ([webrtc-bridge] peer) |
| `install/asterisk/rpt.conf` | rpt.conf phone mode config (phonelogin=yes, phonecontext=radio-ptt) |
| `install/install_webrtc.sh` | Python bridge installer (aiohttp, aiortc, systemd service) |
| `install/chilemon-webrtc.service` | systemd unit for the WebRTC bridge daemon |
| `laboratorio/supermon/frontend/src/services/WebSocketService.ts` | Supermon-ng WebSocket service — data-only, no audio |
| `install/sql/create_tables_sqlite.sql` | SQLite schema (for reference, audio bypasses DB) |

## Ready for Proposal
Yes — the two most impactful fixes are well-understood and bounded.
