# Tasks: Companion Audio App

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~610 (mostly new companion/ package) |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | Single PR (under user's 800-line budget) |
| Delivery strategy | single-pr-default |
| Chain strategy | size-exception |

```
Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium
```

### Suggested Work Units

| Unit | Goal | Notes |
|------|------|-------|
| 1 | Companion app core (session + audio + WS) | Foundation: iax2.py extraction + companion/ package |
| 2 | Dashboard integration | ptt-widget cleanup, visualizer adaptation, download button |

## Phase 1: Core IAX2 Library Extraction

- [x] 1.1 Extract `IAX2Session` class from `app/Services/WebRTCBridge/iax2.py` as a standalone importable module — no behavior change, just packaging (IAX2Session is already standalone, no extraction needed)
- [x] 1.2 Create `companion/__init__.py` package marker
- [x] 1.3 Create `companion/config.toml` — default config template (peer name, Asterisk host/port, password, log level)

## Phase 2: Companion Application

- [x] 2.1 Create `companion/session.py` — `IAX2Session` wrapper with reconnect logic, registration retry, call management
- [x] 2.2 Create `companion/audio.py` — pyaudio mic capture (ulaw, 20ms chunks) + speaker playback (ulaw→PCM), with RMS/FFT metadata extraction
- [x] 2.3 Create `companion/ws_server.py` — localhost WS server for JSON messaging (PTT/DTMF from browser, audio_level metadata to browser)
- [x] 2.4 Create `companion/main.py` — CLI entry point: arg parser, wires session + audio + WS, sigterm handler

## Phase 3: Dashboard Integration

- [x] 3.1 Modify `public/assets/js/ptt-widget.js` — strip RX/TX audio (AudioContext, mic capture, WS binary audio); keep PTT key/unkey, DTMF, status polling, Connect button
- [x] 3.2 Modify `public/assets/js/audio-visualizer.js` — add `feedMetadata(rms, spectrum)` method for companion metadata
- [x] 3.3 Modify `public/views/dashboard.php` — add companion download button in quick-access section

## Phase 4: Build & Deploy Infrastructure

- [x] 4.1 Create `install/companion/install.sh` — Linux install: pip deps, systemd service unit, config template copy
- [x] 4.2 Create `install/companion/build.bat` — Windows PyInstaller one-dir build script

## Phase 5: Testing

- [x] 5.1 Unit test: `tests/companion/test_audio.py` — AudioEngine level computation (RMS, spectrum bins) and edge cases
- [ ] 5.2 Unit test: audio ulaw encode/decode roundtrip against known ref frames
- [ ] 5.3 Integration: verify localhost WS connect/send/receive loop
- [ ] 5.4 Manual smoke test checklist: register peer, place call, speak, verify via parrot node
