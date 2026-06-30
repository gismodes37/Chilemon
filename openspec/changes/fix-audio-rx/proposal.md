# Proposal: Fix RX Audio — WebRTC Audio Bridge

## Intent

Two blocking defects in RX audio: (1) cuts out after 30–40s due to per-frame `AudioBufferSourceNode` stop/start race, (2) critically low volume from insufficient gain (1.5x) and naive linear-interpolation resampling. TX (PTT/mic) works fine. This blocks v0.5.0 release.

## Scope

### In Scope
- Client playback: lookahead queue of pre-scheduled buffers at `audioCtx.currentTime + N×20ms`
- Volume: increase `RX_VOLUME` to 3.0, add per-frame RMS-based AGC, polyphase FIR or AudioContext-native 8 kHz resampling
- AudioContext: eager creation on gesture, resume retry with exponential backoff

### Out of Scope
- AudioWorklet rewrite (approach B, deferred to post-v0.5.0)
- Opus or SIP/IAX2 in browser (approaches C–E)
- Server changes beyond `audio.py`

## Capabilities

**New**: None — all fixes within existing WebRTC bridge pipeline.

**Modified**: `webrtc-bridge` — BRIDGE-AUDIO-01 expands to include gapless playback + minimum volume. Delta spec required.

## Approach

Three independent, revertible fixes:

1. **Lookahead queue** (`ptt-widget.js:420–447`): Buffer 5–10 frames, schedule at `audioCtx.currentTime + i×20ms`. Eliminates stop/start race and GC churn. ~1–2d.
2. **Volume + resampling** (`audio.py:130–220`): `RX_VOLUME` → 3.0. Per-frame RMS AGC (boost frames below –18dBFS, gate at –40dBFS). Polyphase FIR resampler or native 8 kHz AudioContext playback. ~1–2d.
3. **AudioContext hardening** (`ptt-widget.js`): Eager creation on init gesture. Retry resume with 200ms/1s/5s backoff. Log state transitions. ~0.5d.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Services/WebRTCBridge/audio.py` | Modified | RX_VOLUME, AGC, resampler |
| `public/assets/js/ptt-widget.js` | Modified | Scheduling, AudioContext mgmt |
| `public/assets/js/dashboard.js` | Minor | Gain slider default |
| `openspec/specs/webrtc-bridge/spec.md` | Modified | BRIDGE-AUDIO-01 delta |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Lookahead adds latency | Low | Depth configurable: 5–10 frames = 100–200ms |
| AGC amplifies silence noise | Low | Gate below –40dBFS RMS |
| Autoplay policy edge cases | Med | Gesture-based creation + retry handles 99% |

## Rollback Plan

Each fix is a separate commit, independently revertible via `git revert`. Prior fire-and-forget playback is the fallback. No DB migration or config change needed.

## Dependencies

None. Code-only changes within the repository.

## Success Criteria

- [ ] RX audio plays >5 min without dropout (1 kHz tone from Asterisk)
- [ ] RX volume intelligible at defaults: peak RMS > –12 dBFS at 50% slider
- [ ] AudioContext resume failures logged and retried (no silent failures)
- [ ] Existing TX path unaffected — PTT mic capture produces same results
