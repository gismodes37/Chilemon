# Proposal: Bridge IAX2 Direction Reversal

## Intent

ASL3's patched IAX2 silently drops inbound packets with destination callno=0 (REGREQ, NEW). The WebRTC bridge currently acts as IAX2 client — it sends REGREQ/NEW with callno=0 and gets no response, making registration and call setup impossible. Reverse direction: Asterisk calls the bridge via AMI `Originate` + outbound IAX2, which works because ASL3 only blocked inbound callno=0.

## Scope

### In Scope
- AMI client module (`ami_client.py`) — connect, login, Originate, event monitor
- IAX2 server mode in `iax2.py` — listen on UDP port 9092, accept incoming NEW, respond NEWACK/ACK/VOICE/HANGUP
- `server.py` — replace IAX2 client flow with: WS auth → AMI Originate → bridge receives inbound IAX2 NEW → audio relay
- Asterisk config changes: `iax.conf` (port=9092), `extensions.conf` ([webrtc] context with rpt()), `manager.conf` (bridge AMI user)
- Existing audio pipeline (`audio.py`) unchanged — only direction of initiation flips

### Out of Scope
- WebRTC/aiortc peer connection (future phase — bridge operates as WS-only for now)
- DTMF RFC 2833 handling (browser sends DTMF via WS → bridge sends IAX2 DTMF frames)
- Deployment automation (manual config diff documented)
- Unit tests (Python, no test framework in project)

## Capabilities

### New Capabilities
- `ami-integration`: AMI client for bridge — connect, login, Originate calls, monitor events

### Modified Capabilities
- `webrtc-bridge`: Call flow changes from IAX2-client-outbound to IAX2-server-inbound. New AMI dependency. Existing IAX2 frame parsing reused.

## Approach

1. Add `ami_client.py` — async AMI client using asyncio TCP socket. Connect on bridge start, login, wait for `FullyBooted`. Expose `originate(channel, context, extension, priority)` and event callback.
2. Convert `iax2.py` to dual-mode: keep existing client methods for outbound frames (DTMF, VOICE, HANGUP), add `start_server(host, port)` — UDP listener that accepts inbound NEW, allocates callno, sends NEWACK. Wire audio relay callbacks.
3. Update `server.py`: on WS connect/auth, call `ami.originate()` instead of `iax.register()`. Remove IAX2 client startup from `_on_startup`. New flow: WS auth → AMI Originate → Asterisk sends IAX2 NEW → bridge responds → call active → audio relay.
4. Asterisk config: add `port=9092` to `[webrtc-bridge]` peer in `iax.conf`, create `[webrtc]` context with `exten => 61916,1,rpt(61916|P|WebRTC-P)` in `extensions.conf`, add AMI manager user in `manager.conf`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Services/WebRTCBridge/ami_client.py` | New | AMI client module |
| `app/Services/WebRTCBridge/iax2.py` | Modified | Add server mode (listen + accept NEW) |
| `app/Services/WebRTCBridge/server.py` | Modified | Replace IAX2 client flow with AMI Originate |
| `/etc/asterisk/iax.conf` | Modified | Add `port=9092` to [webrtc-bridge] |
| `/etc/asterisk/extensions.conf` | Modified | Add [webrtc] context |
| `/etc/asterisk/manager.conf` | Modified | Add AMI manager credentials |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| ASL3 patched IAX2 outbound too | Low | Testable: run bridge listener first, then verify Asterisk can reach it |
| IAX2 frame parsing dual-role bugs | Med | Refactor parsers to be stateless; session state managed at app level |
| AMI `Originate` async response timing | Med | Use event-driven wait with timeout (15s default) |
| Audio latency over loopback | Low | Loopback UDP on same host — negligible |

## Rollback Plan

1. Revert `iax.conf` — remove `port=9092` from the peer
2. Revert `extensions.conf` — remove `[webrtc]` context
3. Revert `manager.conf` — remove bridge AMI user
4. Restore `server.py` and `iax2.py` to previous versions from git
5. Remove `ami_client.py`
6. Restart bridge service and Asterisk

## Dependencies

- AMI must be enabled in `/etc/asterisk/manager.conf`
- AMI credentials in `config/local.php` (already exists)

## Success Criteria

- [ ] Bridge receives IAX2 NEW after AMI Originate and responds NEWACK
- [ ] Bidirectional audio flows: browser ↔ WS ↔ bridge ↔ IAX2 ↔ Asterisk
- [ ] DTMF from browser reaches Asterisk via IAX2 DTMF frames
- [ ] HANGUP from either side tears down call cleanly
