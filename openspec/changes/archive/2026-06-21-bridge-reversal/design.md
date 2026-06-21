# Design: Bridge IAX2 Direction Reversal

## Technical Approach

Flip the bridge from IAX2 client to IAX2 server. Asterisk originates outbound IAX2 calls via AMI `Originate` with `Async: true`, bypassing ASL3's dropped-callno-0 filter. IAX2 frame parsers and audio pipeline are reused — only initiation direction changes.

## Architecture Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| AMI transport | Raw TCP stream | `Async: true` Originate needs async event response. CLI blocks, HTTP unnecessary on localhost. |
| Event correlation | Auto-generated ActionID per request | Correlates `OriginateResponse` events with the originating code path. |
| Connection resilience | Reconnect with exponential backoff (1s→2s→4s→max 30s) | Bridge survives Asterisk restart without manual restart. |
| AMI event dispatch | Background reader task | Separates socket read from frame dispatch; prevents stall on large responses. |
| IAX2 server mode | Separate `IAX2Server` class | Client DTMF/VOICE/HANGUP logic reused via `IAX2Call`. Server uses own `DatagramProtocol` on port 9092. |
| WS ↔ IAX2 correlation | Single-call model: `_active_call: Optional[IAX2Call]` | One WS connection → one IAX2 call at a time. Sufficient for v1. |
| IAX2 keepalive | Protocol-level PING→PONG + LAGRQ→LAGRP | Without PONG, Asterisk marks peer unreachable and drops calls. Handled in `IAX2Server._on_datagram`, not per-call. |
| Audio relay | IAX2 callbacks (`on_voice`, `on_hangup`) | Matches existing pattern; pipeline stays stateless. |

## Data Flow

```
Browser WS ──(auth)──→ bridge:9091
                          │
                    ami.originate(
                      ActionID: "orig-{uuid}",
                      Channel: "IAX2/webrtc-bridge/61916",
                      Context: webrtc, Exten: s, Priority: 1,
                      CallerID: "WebRTC <61916>",
                      Async: true, Timeout: 15000)
                          │
                    Asterisk AMI ──→ Asterisk IAX2 core
                          │
                    IAX2 NEW(called=61916) ──→ bridge:9092
                          │
                    IAX2Server._on_new()
                      → alloc callno → send NEWACK
                      → send ACCEPT → send ANSWER  (CONTROL)
                          │
                    Asterisk dialplan: rpt(61916|P|WebRTC-P)
                          │
                    IAX2 VOICE mini frames ←──→ bridge ←──→ WS audio
                    IAX2 DTMF  full frames  ←── bridge ←── WS keypress
                    IAX2 HANGUP ──→ bridge cleanup → WS peers notified
                    WS disconnect ──→ IAX2 HANGUP → bridge cleanup
```

## WS ↔ IAX2Call Correlation

```
WebRTCBridgeApp
├── self._ws_peers: set[WSResponse]       # existing
├── self._active_call: Optional[IAX2Call] # NEW — current call
├── self.ami: AMIClient                    # NEW
└── self.iax_server: IAX2Server            # NEW
```

- WS auth success → `ami.originate()` → on IAX2 NEW → `_active_call = call`
- WS audio_tx → `_active_call.send_voice()` (skip if no active call)
- IAX2 VOICE → `_on_iax_audio()` → broadcast to all `_ws_peers`
- WS last disconnect → `_active_call.send_hangup()`, `_active_call = None`
- IAX2 HANGUP → `_active_call = None`, broadcast status to WS

## Interfaces

```python
# ami_client.py
class AMIClient:
    async def connect(self, host: str, port: int) -> None
    async def login(self, user: str, secret: str) -> None
    async def originate(self, *, channel: str,
                        context: str = "webrtc", exten: str = "s",
                        priority: int = 1, callerid: str = "",
                        async_: bool = True, timeout: int = 15000,
                        variables: dict[str, str] = {}) -> str
        """Returns ActionID for event correlation. Always sends Async: true."""
    def on_event(self, event_type: str,
                 cb: Callable[[dict], Awaitable[None]]) -> None
    async def monitor_events(self) -> None    # background reader + reconnect
    async def close(self) -> None
    @property def connected(self) -> bool
    @property def logged_in(self) -> bool

# iax2.py — NEW server classes
class IAX2Server:
    async def start(self, host: str = "0.0.0.0", port: int = 9092) -> None
    async def stop(self) -> None
    on_new_call: Optional[Callable[["IAX2Call"], Awaitable[None]]]
    # Keepalive: PING→PONG, LAGRQ→LAGRP handled internally in _on_datagram

class IAX2Call:
    callno: int
    peer_callno: int
    called_num: str
    state: int  # STATE_CALLING, STATE_ACTIVE, STATE_HUNGUP
    async def send_newack(self) -> None
    async def send_accept(self) -> None     # IAX_CMD_ACCEPT
    async def send_answer(self) -> None     # CONTROL ANSWER (0x0B)
    async def send_voice(self, ulaw: bytes) -> None
    async def send_dtmf(self, digit: str) -> None
    async def send_hangup(self) -> None
    async def send_ack(self) -> None
```

## IAX2 Keepalive Handling

Added to `IAX2Server._on_datagram` (protocol level, no call state required):

| Received | Response |
|----------|----------|
| PING (`IAX_CMD_PING = 0x0D`) | PONG (`IAX_CMD_PONG = 0x0E`) |
| LAGRQ | LAGRP |

## Call Cleanup

| Trigger | Action |
|---------|--------|
| IAX2 HANGUP from Asterisk | `_active_call = None`, `_call_active = False`, broadcast status→WS |
| WS last peer disconnect | `_active_call.send_hangup()`, `_active_call = None` |
| AMI Originate timeout (15s) | Report failure to WS, no call state |
| AMI connection lost | Background reconnect loop; active calls drop naturally |

## Health Endpoint — New Fields

```python
{
    "status": "ok",
    "ami_connected": true,          # NEW
    "iax_server_running": true,     # NEW
    "active_call": true,            # NEW (was "in_call")
    "ptt_active": false,
    "peers": 1
}
```

## Asterisk Configuration

```
; /etc/asterisk/iax.conf — peer section
[webrtc-bridge]
type=peer
host=127.0.0.1
port=9092
secret=<generated>
context=webrtc
qualify=yes                     # enables PING/PONG keepalive

; /etc/asterisk/extensions.conf — answer context
[webrtc]
exten => 61916,1,rpt(61916|P|WebRTC-P)

; /etc/asterisk/manager.conf — AMI user
[chilemon-bridge]
secret = <from-config>
read = system,call,originate
write = system,call,originate
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Services/WebRTCBridge/ami_client.py` | Create | AMI TCP: connect, login, Originate (Async:true, ActionID), event dispatch, reconnect loop |
| `app/Services/WebRTCBridge/iax2.py` | Modify | Add `IAX2Server` + `IAX2Call`, NEW/NEWACK/ACCEPT/ANSWER, PING/PONG keepalive, call pool. Add `IAX_CMD_ACCEPT` constant. |
| `app/Services/WebRTCBridge/server.py` | Modify | Replace IAX2 client with AMI client + IAX2 server. WS→`_active_call` correlation. Add health fields. |
| `/etc/default/chilemon-webrtc` | Modify | Add `AMI_HOST`, `AMI_PORT`, `AMI_USER`, `AMI_PASS`, `IAX_LISTEN_PORT` |
| `/etc/asterisk/iax.conf` | Modify | Add full peer config with `port=9092`, `type=peer`, `host=127.0.0.1`, `secret`, `context=webrtc`, `qualify=yes` |
| `/etc/asterisk/extensions.conf` | Modify | Add `[webrtc]` context |
| `/etc/asterisk/manager.conf` | Modify | Add manager user with AMI credentials |

## Testing Strategy

Manual verification on ASL3 node:

| Layer | What | How |
|-------|------|-----|
| AMI | Login + Originate Async | Bridge logs show AMI auth and `Action: Originate, Async: true` |
| IAX2 | Server receives NEW | `tcpdump -i lo port 9092` — NEW arrives after Originate |
| IAX2 | NEWACK + ACCEPT + ANSWER | Bridge debug logs show frame sequence |
| Audio | Bidirectional | Browser PTT → audio loopback |
| DTMF | Keypress relay | IAX2 DTMF frames visible in bridge debug |
| Cleanup | WS disconnect → HANGUP | Bridge logs show HANGUP sent after last peer leaves |
| Reconnect | Asterisk restart | Bridge reconnects AMI within 30s |

## Migration / Rollout

1. Apply Asterisk config (iax.conf, extensions.conf, manager.conf) — `asterisk -rx "core reload"`
2. Add AMI vars to `/etc/default/chilemon-webrtc`
3. Deploy new bridge code
4. `systemctl restart chilemon-webrtc`
5. Verify health: `curl http://localhost:9091/health` — `ami_connected=true`, `iax_server_running=true`
6. Connect WS → verify `ami.originate()` fires in logs

Rollback: revert configs, restore old code via git, reload Asterisk, restart bridge.

## Open Questions

- `IAX_CMD_ACCEPT` value: `0x0E` per requirement; verify against ASL3's IAX2 headers in implementation (existing `IAX_CMD_PONG = 0x0E` may need resolution).
- Should bridge service run as `stg` instead of `root`?
