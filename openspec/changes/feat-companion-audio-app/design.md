# Design: Companion Audio App

## Technical Approach

Replace the browser WebSocket ↔ bridge ↔ IAX2 audio pipeline with a native desktop companion app that connects as a direct IAX2 peer to Asterisk. The browser retains the dashboard, PTT control, DTMF, and visualizer — audio bypasses the browser entirely via native OS audio APIs.

```
Browser Dashboard ←──localhost WS(JSON only)──→ Companion App ←──IAX2 direct──→ Asterisk ←──RPT──→ AllStar
                                                 ├── Mic capture → ulaw → IAX2 mini frames
                                                 ├── IAX2 audio → ulaw → native playback
                                                 ├── PTT toggle (spacebar/button)
                                                 └── DTMF digits → IAX2 full frames
```

## Architecture Decisions

| Decision | Choice | Alternatives Considered | Rationale |
|----------|--------|------------------------|-----------|
| Language/Runtime | Python 3.10+ | C/C++ (faster, native IAX2), Rust (safe, fast), Electron (JS ecosystem) | Python can reuse 1613 lines of existing `iax2.py` directly; pyaudio handles mic/speaker across Win/Linux; PyInstaller bundles for distribution |
| IAX2 Protocol | Extract `IAX2Session` class from `iax2.py` into shared module | Standalone C library, pyst2 (SIP/IAX2 library) | `IAX2Session` already proven in production — registration, CallToken MD5, NEW/ACCEPT/ANSWER, DTMF, mini frames. Avoids rewriting & debugging protocol logic |
| Companion Structure | Single `companion/` package: `main.py`, `session.py`, `audio.py`, `ws_server.py` | Monolithic single file, plugin-based | Clean separation: IAX2 session, audio I/O, localhost WS, CLI args. Easy to test each module independently |
| Auth | Pre-shared token in config file | HMAC token via PHP endpoint (current bridge), manual peer secret | Companion runs on same machine — local config file is sufficient. No PHP dependency for auth. Asterisk peer auth uses iax.conf secret |
| Audio Pipeline | pyaudio (PortAudio) — block-based mic/speaker | sounddevice, PyAudio, wave + winmm | pyaudio is most portable across Win/Linux, well tested with IAX2 20ms ulaw frame timing |

## Data Flow

### RX Path (AllStar → Speaker)
```
Asterisk IAX2 → IAX2 mini frames(VOICE, ulaw) → session.py → audio.py(ulaw→pcm_s16le) → pyaudio output stream
                                                                     ↓
                                                              ws_server.py → RMS/FFT metadata → Browser visualizer
```

### TX Path (Mic → AllStar)
```
pyaudio input stream → audio.py(pcm_s16le→ulaw, 20ms chunks) → session.py → IAX2 mini frames → Asterisk → RPT → AllStar
                                                                     ↓
                                                              RMS level → ws_server.py → Browser visualizer
```

### Control Flow (Dashboard ↔ Companion)
```
Browser:
  WS connect → {type:"ptt", action:"key"}       → companion: spacebar held → DTMF * → Asterisk
  WS connect → {type:"ptt", action:"unkey"}     → companion: spacebar released → DTMF * → Asterisk
  WS connect → {type:"dtmf", digit:"1"}         → companion: → IAX2 DTMF frame → Asterisk
  WS connect → {type:"status"}                  → companion returns → {type:"status", connected:true, ...}
  companion → {type:"audio_level", rms:0.42, spectrum:[...]} → visualizer update
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `companion/main.py` | Create | CLI entry point: argparse for config, starts IAX2 session + audio + WS |
| `companion/session.py` | Create | Thin wrapper around IAX2Session: reconnect logic, registration management |
| `companion/audio.py` | Create | pyaudio mic/speaker streams, ulaw↔PCM conversion, 20ms frame timing |
| `companion/ws_server.py` | Create | localhost WS server: forwards PTT/DTMF from browser, broadcasts audio levels |
| `companion/__init__.py` | Create | Package marker |
| `companion/config.toml` | Create | Default config template (peer name, Asterisk host/port, password) |
| `app/Services/WebRTCBridge/iax2.py` | Modify | Extract IAX2Session into importable module; no behavior change |
| `public/assets/js/ptt-widget.js` | Modify | Remove audio WS code (TX/RX audio handlers, AudioContext, mic capture); keep status/PTT/DTMF |
| `public/assets/js/audio-visualizer.js` | Modify | Accept `{rms, spectrum}` metadata from WS instead of raw PCM |
| `public/views/dashboard.php` | Modify | Add companion download button row; fix map button HUB_URL condition |
| `install/companion/install.sh` | Create | Linux install script: pip deps, systemd service, config setup |
| `install/companion/build.bat` | Create | Windows build script: PyInstaller → dist/ directory |

## Interfaces / Contracts

### localhost WS Protocol (JSON only — no binary audio)

**Browser → Companion:**
```json
{"type":"ptt",     "action":"key"}           // key transmitter
{"type":"ptt",     "action":"unkey"}         // unkey transmitter
{"type":"dtmf",    "digit":"1"}              // send DTMF digit
{"type":"status"}                             // request status
```

**Companion → Browser:**
```json
{"type":"status",       "connected":true, "call_active":true, "ptt":false}
{"type":"audio_level",  "rms":0.42, "spectrum":[0.1,0.3,0.5,0.2]}  // visualizer
{"type":"error",        "message":"Registration failed"}
```

### IAX2 Session Reuse

`iax2.py` already exposes `IAX2Session` with this API — reused as-is:
```python
session = IAX2Session(host="127.0.0.1", port=4569, username="companion-app", password="secret")
await session.register()
await session.start_call("61916")    # ASL node number
session.send_dtmf("*")               # PTT toggle
session.send_voice(ulaw_bytes)       # TX audio
# on_audio_frame callback → RX audio
```

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | IAX2Session wrapper | Reuse existing `iax2.py` tests; add companion wrapper tests with mock transport |
| Unit | Audio → ulaw conversion | Known ulaw ref frames; verify encode/decode roundtrip |
| Integration | Companion ↔ Asterisk | Manual: register, place call, verify `iax2 show peers` |
| Integration | Companion ↔ Dashboard | Localhost WS: connect from browser, send PTT/DTMF, verify status messages |
| E2E | Full audio loop | Parrot node test: key PTT, speak, hear playback |

## Migration / Rollout

1. Deploy companion app alongside existing bridge (no changes to bridge)
2. Test IAX2 registration and RX audio from AllStar
3. Test TX audio via parrot node
4. Once verified: update dashboard with download button
5. Remove old bridge audio code from dashboard after migration window

## Open Questions

- [ ] Config format: TOML vs YAML vs INI — TOML proposed (consistent with Python ecosystem)
- [ ] Windows audio device selection: default device vs configurable input/output device IDs
- [ ] Linux audio: pulseaudio vs ALSA directly — pyaudio handles both but device names differ
- [ ] Service management: systemd on Pi, Windows Service or startup shortcut?
- [ ] Logging: file-based vs stdout (systemd captures stdout) — propose both with configurable level
