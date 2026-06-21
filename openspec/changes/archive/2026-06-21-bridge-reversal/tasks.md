# Tasks: Bridge IAX2 Direction Reversal

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~460 (240 new + 220 modified) |
| 800-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: pending
800-line budget risk: Low

> **Open question from design**: `IAX_CMD_ACCEPT` value 0x0E conflicts with existing `IAX_CMD_PONG = 0x0E`. Resolve during implementation by verifying against ASL3 IAX2 headers.

## Phase 1: Foundation

- [x] 1.1 Add `IAX_CMD_ACCEPT` constant to `iax2.py` (resolve 0x0E conflict with `IAX_CMD_PONG` against ASL3 headers)
- [x] 1.2 Add call state constants (`CALL_STATE_CALLING`, `CALL_STATE_ACTIVE`, `CALL_STATE_HUNGUP`, `CALL_STATE_IDLE`) to `iax2.py`
- [x] 1.3 Create `ami_client.py` with `AMIClient` — full implementation: connect, login, originate, monitor_events, reconnect loop

## Phase 2: Core — IAX2 Server Mode

- [x] 2.1 Implement `IAX2Server` class: `start()`/`stop()` UDP `DatagramProtocol` on port 9092, `_on_datagram` dispatch, PING→PONG + LAGRQ→LAGRP keepalive
- [x] 2.2 Implement `IAX2Call` class: `callno`/`peer_callno`/`called_num`/`state`, `send_newack()`/`send_accept()`/`send_answer()`/`send_voice()`/`send_dtmf()`/`send_hangup()`/`send_ack()`
- [x] 2.3 Wire `IAX2Server._on_new()`: allocate local callno, invoke `on_new_call` callback, send NEWACK→ACCEPT→ANSWER sequence
- [x] 2.4 Add `IAX2Call` state machine: `CALL_STATE_CALLING`→`CALL_STATE_ACTIVE` on ANSWER, `CALL_STATE_HUNGUP` on HANGUP, cleanup on disconnect

## Phase 3: Integration — AMI Client + server.py Wiring

- [x] 3.1 Implement `AMIClient.connect()` + `login()` + `close()`: TCP to `127.0.0.1:5038`, Asterisk banner read, `Action: Login`, reconnect with exponential backoff (1s→2s→4s→max 30s)
- [x] 3.2 Implement `AMIClient.originate()` with `Async: true`, auto-generated `ActionID`, and `monitor_events()` background reader task dispatching events to registered callbacks
- [x] 3.3 Modify `server.py`: replace `IAX2Session` with `AMIClient` + `IAX2Server`; WS auth success calls `ami.originate()`; `on_new_call` sets `_active_call`
- [x] 3.4 Modify `server.py`: update `_on_startup`/`_on_shutdown` for new modules; health endpoint fields: `ami_connected`, `iax_server_running`, `active_call`; WS last-peer-disconnect → `_active_call.send_hangup()`

## Phase 4: Asterisk Configuration (documented — apply manually)

- [x] 4.1 Document `/etc/asterisk/iax.conf`: `[webrtc-bridge]` peer with `port=9092`, `type=peer`, `host=127.0.0.1`, `secret`, `context=webrtc`, `qualify=yes`
- [x] 4.2 Document `/etc/asterisk/extensions.conf`: `[webrtc]` context with `exten => 61916,1,rpt(61916|P|WebRTC-P)`
- [x] 4.3 Document `/etc/asterisk/manager.conf`: `[chilemon-bridge]` AMI user with `read = system,call,originate` and `write = system,call,originate`
- [x] 4.4 Document `/etc/default/chilemon-webrtc`: add `AMI_HOST`, `AMI_PORT`, `AMI_USER`, `AMI_PASS`, `IAX_LISTEN_PORT`
