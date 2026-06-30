# Design: Fix RX Audio — WebRTC Bridge

## Technical Approach

Three independent, revertible client+server fixes eliminating the two blocking RX defects: (1) fire-and-forget `AudioBufferSourceNode` per frame replaced by a lookahead scheduling queue to stop the 30–40s dropout, (2) server-side AGC + 3.0x gain floor + native 8 kHz AudioContext playback for audible volume, and (3) eager AudioContext creation with exponential backoff resume to eliminate silent autoplay failures. Each fix is a separately testable commit.

## Architecture Decisions

### Decision 1: Lookahead Scheduling Queue

| Option | Tradeoff | Decision |
|--------|----------|----------|
| **Lookahead queue** (chosen) | +100ms latency, no dropouts, handles GC pressure | Fixes the root cause — stop/start race on every frame |
| AudioWorklet | Lower latency, but more code, async init, Chrome requires separate file | Deferred to post-v0.5.0 |
| Fire-and-forget (current) | — | Replaced — proved unreliable past 30s |

**Implementation**: Maintain a queue of 5 pre-scheduled buffers at `audioCtx.currentTime + i × 20ms`. A `scheduleNext()` function creates `AudioBufferSourceNode`, sets `buffer`, connects to `_rxGainNode`, calls `start(currentTime + offset)`, and pushes to `_scheduledSources[]`. On `onended`, remove from the queue and release. If a frame arrives late (scheduled time already passed), skip it and log a warning. On buffer underrun, continue silently (next frame fills the gap).

**Cleanup**: `_scheduledSources` holds `AudioBufferSourceNode[]`. On call drop, stop all, disconnect, and clear.

### Decision 2: Server-Side AGC + Native 8 kHz Playback

| Option | Tradeoff | Decision |
|--------|----------|----------|
| **RX_VOLUME=3.0 + per-frame RMS AGC + 8 kHz native** (chosen) | No new deps, ~20 lines, browser handles resampling | Eliminates server-side interpolation artifacts entirely |
| Polyphase FIR in Python | Better quality, but adds complexity, no existing dep for windowed sinc | Unnecessary — AudioContext handles 8→48 kHz natively |
| Linear interpolation (current) | — | Replaced — quality loss + volume too low |

**AGC parameters**: target level –12 dBFS RMS per frame after gain. Attack 50ms (boost at 2×/frame if below –18 dBFS), release 200ms (decay 0.5×/frame if above –12 dBFS), gate floor –40 dBFS (skip frame entirely if RMS below threshold).

**8 kHz native**: Modify `rx_process()` to skip the `resample_8k_to_16k()` call. The resulting `s16_to_float32()` output stays at 8 kHz. The server sends `"rate": 8000` in the JSON message. The client already accepts arbitrary `sampleRate` in `audioCtx.createBuffer(1, len, rate)` — no JS changes needed for resampling, only for the rate field.

### Decision 3: AudioContext Hardening

| Option | Tradeoff | Decision |
|--------|----------|----------|
| **Eager creation + backoff resume** (chosen) | Simple, handles 99% of autoplay edge cases | Build on existing TX pattern |
| One-shot resume with user gesture listener | Misses cases where context suspends mid-session | Not enough |

**Implementation**: Create `audioCtx` in `init()` (after user gesture from `_bindGlobalEvents` → `keydown`/`mousedown`). Before first `_playAudioBuffer` call, ensure `audioCtx` exists. Resume schedule: immediate retry, then 200ms, 1s, 5s. Log each attempt and state transition via `console.warn('[RX-AUDIO]', state)`. Match the existing TX pattern at lines 486–496 of `ptt-widget.js`.

## Data Flow

```
BEFORE (per-frame stop/start, 16 kHz, 1.5x gain):
  IAX2 → ulaw → PCM 8k → linear upsample 16k → float32 → hex → WS
    → JS: AudioBufferSourceNode.stop() → create new → start(0)
    → GC collects old node → repeat every 20ms → DROPOUT AFTER ~30s

AFTER (lookahead queue, 8 kHz native, 3.0x + AGC):
  IAX2 → ulaw → PCM 8k → AGC(gate -40dBFS, target -12dBFS) → gain 3.0x
    → float32 → hex → WS {"rate":8000}
    → JS: scheduleNext(currentTime + N*20ms) → push queue[5]
    → onended → pop → GC is amortized, no stop/start race
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Services/WebRTCBridge/audio.py` | Modify | AGC constants + `rx_process()`: skip resample, add RMS AGC loop, RX_VOLUME→3.0 |
| `public/assets/js/ptt-widget.js` | Modify | Replace `_playAudioBuffer()` with lookahead queue; eager AudioContext; backoff resume; accept `rate` for 8 kHz native |
| `public/assets/js/dashboard.js` | Modify | Update `setupAudioSettings()` default slider or test tone if needed (minor) |

## Interfaces / Contracts

```python
# audio.py — new AGC constants
RX_VOLUME: float = 3.0
AGC_TARGET_DBFS: float = -12.0    # target RMS level after gain
AGC_ATTACK_FACTOR: float = 2.0     # multiplier when below gate threshold
AGC_RELEASE_FACTOR: float = 0.5    # decay multiplier when above target
AGC_GATE_DBFS: float = -40.0       # silence gate (discard below this)
```

```javascript
// ptt-widget.js — new scheduling state
/** @type {{ source: AudioBufferSourceNode, endTime: number }[]} */
this._scheduledSources = [];
this._lookaheadDepth = 5;           // 5 frames = 100ms buffer
this._audioCtxRetries = [200, 1000, 5000];  // ms delay per retry
```

```javascript
// ptt-widget.js — new method signatures
_scheduleFrame(samples: Float32Array, sampleRate: number, offset: number): void
_advanceQueue(): void                   // called from _handleHexAudio
_resumeAudioCtx(attempt: number): void  // recursive with backoff
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `rx_process()` AGC and gain | Python: inject known ulaw frames at –30, –20, –50 dBFS; assert output RMS in expected range; assert silence gate discards –50 dBFS |
| Unit | `_scheduleFrame()` timing | Browser: mock `audioCtx.currentTime`; assert `source.start()` called with expected `currentTime + offset`; assert queue depth ≤ 5 |
| Integration | 5+ min sustained playback | Manual: connect to test node, transmit 1 kHz tone from Asterisk, monitor RX audio for gaps. Log `audioCtx.currentTime` drift on each frame |
| Integration | AGC convergence | Manual: feed low-volume speech recording, verify volume slider shows >20% fill after AGC |
| E2E | Full bridge cycle | Existing `test_verbose.py` pattern: AMI Originate → WebSocket connect → verify status → TX PTT → verify IAX2 voice frames |

## Migration / Rollout

Three independent commits, each revertible:

1. **Server AGC**: `audio.py` — `RX_VOLUME=3.0`, RMS AGC, skip 8→16 kHz resample. Send `rate:8000` in JSON. Client unaffected at this point (existing code accepts any sample rate in `createBuffer`).
2. **Client lookahead**: `ptt-widget.js` — replace `_playAudioBuffer()`. Without commit 1, still works (16 kHz frames). Without commit 3, still works (lazy AudioContext).
3. **AudioContext hardening**: `ptt-widget.js` — eager creation, backoff resume. Pure additive — no regression path.

Rollback: `git revert <hash>` per commit. No DB or config migration required.

## Open Questions

- None. All three decisions are fully scoped from spec scenarios.
