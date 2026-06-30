# Tasks: Fix RX Audio — WebRTC Audio Bridge

## Review Workload Forecast

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

| Field | Value |
|-------|-------|
| Estimated changed lines | 200-300 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | auto-forecast |
| Chain strategy | pending |

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | All three fixes + tests | PR 1 | Single PR — under 400 lines, no chain needed |

## Phase 1: Server-Side AGC + Native 8kHz (audio.py)

- [x] 1.1 Increase `RX_VOLUME` from 1.5 to 3.0 in `app/Services/WebRTCBridge/audio.py`
- [x] 1.2 Add AGC constants: `AGC_TARGET_DBFS`, `AGC_ATTACK_FACTOR`, `AGC_RELEASE_FACTOR`, `AGC_GATE_DBFS`
- [x] 1.3 Implement per-frame RMS computation and AGC loop in `rx_process()`: boost frames below -18dBFS, gate silence below -40dBFS
- [x] 1.4 Skip 8→16 kHz resample call and include `"rate":8000` in WS JSON payload

## Phase 2: Client-Side Lookahead Queue (ptt-widget.js)

- [x] 2.1 Add `_scheduledSources: AudioBufferSourceNode[]` and `_lookaheadDepth = 5` to widget state
- [x] 2.2 Implement `_scheduleFrame(samples, sampleRate, offset)` — create `AudioBufferSourceNode`, schedule at `currentTime + offset`
- [x] 2.3 Implement `_advanceQueue()` — manage queue depth, skip late frames, handle buffer underrun
- [x] 2.4 Handle call-drop cleanup: stop all sources, disconnect, clear queue in `_stopCall()`
- [x] 2.5 Accept `rate` field from WS `audio` message, pass as sampleRate to `createBuffer`
- [x] 2.6 Remove old `_playAudioBuffer()` stop/start pattern

## Phase 3: AudioContext Hardening (ptt-widget.js)

- [x] 3.1 Create AudioContext eagerly in `init()` after user gesture (keydown/mousedown)
- [x] 3.2 Implement `_resumeAudioCtx(attempt)` with `[200, 1000, 5000]` ms exponential backoff
- [x] 3.3 Log all AudioContext state transitions via `console.warn('[RX-AUDIO]', state)`
- [x] 3.4 Replace silent `.catch(() => {})` with proper error logging

## Phase 4: Tests + Cleanup

- [x] 4.1 Write PHPUnit tests in `tests/Services/`: AGC boost, gain floor (3.0x), silence gate (-40dBFS)
- [x] 4.2 Adjust gain slider default in `public/assets/js/dashboard.js`
- [ ] 4.3 Manual verification: 5+ min sustained playback with 1 kHz tone from Asterisk
- [x] 4.4 Update documentation: add comment block at top of `audio.py` describing AGC parameters and 8kHz native output decision
