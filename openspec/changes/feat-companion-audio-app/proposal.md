# Proposal: Companion Audio App

## Intent

Replace the browser-based audio pipeline (WebSocket → bridge → IAX2) with a native desktop companion app that connects directly to Asterisk as an IAX2 peer. The browser keeps the dashboard, status, and controls — audio goes through native APIs, eliminating AudioContext restrictions, throttling, and the invisible-dialplan-channel bug that blocks TX.

## Scope

### In Scope
- Native desktop app companion (connects as IAX2 peer directly to Asterisk)
- Localhost WebSocket between browser and companion app for state/visualizer data
- Dashboard: add companion download button, fix map button HUB_URL condition
- Visualizer adaptation: receive level metadata from companion app instead of raw PCM
- Infrastructure: build script, installer integration, service management

### Out of Scope
- Mobile app (deferred — revisit when desktop companion is stable)
- Multiple simultaneous node connections from one app instance (future enhancement)
- Replacing Supermon or other external tools

## Capabilities

### New Capabilities
- `companion-audio-app`: Desktop app that registers as IAX2 peer with Asterisk, handles mic/speaker via native audio API, and communicates with the dashboard via localhost WS

### Modified Capabilities
- None (the existing web dashboard capabilities stay as-is; only the audio pipeline changes)

## Approach

Two-component architecture:

```
Browser Dashboard ←──localhost WS──→ Companion App ←──IAX2 direct──→ Asterisk ←──RPT──→ AllStar
                                     ├── Audio in (mic) → IAX2
                                     ├── Audio out (speaker) ← IAX2
                                     ├── PTT toggle (spacebar/button)
                                     └── DTMF digits → IAX2
```

The companion app registers with Asterisk as `type=friend` peer (same pattern iaxrpt uses). The dialplan in `extensions.conf` routes calls directly through RPT — no bridge, no WS, no intermediate channel. The app sends audio level metadata (RMS, spectrum bins) to the dashboard via localhost WS for the visualizer.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Services/WebRTCBridge/iax2.py` | Reused | IAX2 protocol logic extracted/repackaged for companion app |
| `public/assets/js/ptt-widget.js` | Simplified | Remove audio WS streaming, keep Connect button/status |
| `public/assets/js/audio-visualizer.js` | Adapted | Accept level metadata instead of raw PCM |
| `public/views/dashboard.php` | Modified | Add companion download button, fix map HUB_URL |
| New: companion app directory | New | IAX2 client + native audio + localhost WS |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| IAX2 registration/auth differences between ASL3 versions | Medium | Already solved in current bridge (CallToken MD5) |
| Platform compatibility (Windows + Pi Linux) | Medium | Python + pyaudio runs on both; bundle with PyInstaller for Windows |
| Visualizer loses fidelity without raw PCM | Low | Send pre-computed FFT bins — visualizer just draws |

## Rollback Plan

The current bridge (`server.py`) remains deployed and functional. The companion app is ADDITIVE — uninstall it and the browser dashboard works as before (with current TX limitations). No existing configs or data are modified.

## Dependencies

- Asterisk: `iax.conf` peer stanza for companion app (type=friend, context=radio-companion)
- Asterisk: `extensions.conf` RPT context (radio-companion → Rpt)
- Python 3.8+ with pyaudio for the companion app

## Success Criteria

- [ ] Companion app connects to Asterisk and appears in `iax2 show peers` as REACHABLE
- [ ] RX audio: AllStar audio plays through native speakers (no browser dependency)
- [ ] TX audio: Mic audio reaches AllStar network confirmed by parrot node or other operator
- [ ] DTMF commands reach RPT (confirmed by `*81` time response)
- [ ] Visualizer shows RX/TX activity via localhost metadata
- [ ] Dashboard download button links to companion app installer
