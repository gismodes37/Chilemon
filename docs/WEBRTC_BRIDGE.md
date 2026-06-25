# WebRTC Audio Bridge — Browser Push-to-Talk for ChileMon

> **Status**: Cycles 1 & 2 — Core Bridge + IAX2 Direction Reversal (v0.2.0)
> **SDD Changes**: `webrtc-audio-bridge`, `bridge-reversal`

## Overview

The WebRTC Audio Bridge brings browser-based Push-to-Talk (PTT) to ChileMon,
enabling radio communication from the dashboard without a physical radio. It
recovers the SuperMon PTT experience lost when migrating to ASL3.

### Architecture

```
┌─────────┐     WebSocket      ┌──────────────────┐     IAX2 (UDP 4569)    ┌──────────┐
│ Browser ├───────────────────►│  Python Bridge    ├───────────────────────►│ Asterisk │
│  PTT.js │◄───────────────────│ (aiortc+aiohttp)  │◄──────────────────────│ app_rpt  │
└─────────┘     OPUS ↔ PCM     └────────┬─────────┘       ulaw mini-frames └──────────┘
                      │                  │
                      │ HTTP /health     │ AMI (TCP 5038)
                      ▼                  ▼
               ┌──────────────┐  ┌──────────────────┐
               │  PHP API     │  │  AMI Originate   │
               │ ptt-status   │  │  (call bridge)   │
               │ ptt-ws-token │  └──────────────────┘
               └──────────────┘
```

- **Browser** captures OPUS audio (WebRTC) or receives decoded PCM for playback
- **Python Bridge** (`server.py`) runs on port 9091, translates between WebRTC and IAX2 protocols
- **IAX2** is used instead of PJSIP because ASL3 does not compile SRTP/PJSIP modules
- **Asterisk app_rpt** handles the actual radio PTT via phone mode

### Why IAX2 instead of SIP/WebRTC directly?

ASL3 ships without PJSIP/SRTP support. The modules (`res_http_websocket`,
`res_srtp`, `chan_pjsip`) are available but cannot be loaded because PJSIP
wasn't compiled. IAX2 is always enabled in ASL3 and supports phone-mode
registration, making it the simplest integration path.

### IAX2 Direction Reversal (v0.2.0)

The initial Cycle 1 design had the bridge register as an IAX2 **client** (phone
extension) to Asterisk. This failed because ASL3 drops inbound IAX2 registration
packets at the kernel level — specifically, any packet with `callno=0` (new
calls) is silently filtered by the ASL3 iptables rules.

**Solution**: The bridge now acts as an IAX2 **server**, and Asterisk calls *it*
via AMI `Originate` with `Async: true`. This reversal avoids the ASL3 filter
entirely.

Key changes:
- **New file `ami_client.py`**: Async TCP AMI client with automatic reconnection
  and exponential backoff. Connects to Asterisk Manager Interface, logs in, and
  issues `Originate` commands to initiate bridge calls.
- **Modified `iax2.py`**: Added `IAX2Server` and `IAX2Call` classes. The server
  listens for incoming IAX2 calls from Asterisk; `IAX2Call` handles the full
  call lifecycle (ACCEPT, voice frames, DTMF, HANGUP).
- **Modified `server.py`**: Replaced direct `IAX2Session` with the server-based
  flow. The bridge now waits for calls instead of initiating them.
- **Key discovery**: `IAX_CMD_ACCEPT = 0x0E` — same opcode as `PONG` per RFC
  5456, distinguished by call context (PONG is only valid in a `callno=0`
  register exchange; ACCEPT is valid in an active call).

## System Requirements

| Component | Minimum | Recommended |
|-----------|---------|-------------|
| RAM | 1 GB | 2 GB (RPi 4/5) |
| OS | Debian 12 / Raspberry Pi OS | Same |
| Python | 3.10+ | 3.11 (Debian 12 default) |
| Asterisk | ASL3 (Asterisk 22+) | ASL3 |
| Free disk | 100 MB | 500 MB |

> **RPi note**: RPi 3 B+ (1 GB RAM) is fine for a single user. For multi-user
> or heavy use, RPi 4/5 (2 GB+) is recommended.

### Dependencies

Installed by the main installer (`install/install_chilemon.sh`, Step 12) or standalone via:

| Package | Purpose |
|---------|---------|
| `python3-aiohttp` | HTTP/WebSocket server framework |
| `python3-aiohttp-cors` | CORS headers (future multi-origin use) |
| `python3-aiortc` | WebRTC peer connection (OPUS codec) |
| `python3-websockets` | WS transport (fallback/utility) |

## Files

### Python Bridge (`app/Services/WebRTCBridge/`)

| File | Purpose |
|------|---------|
| `__init__.py` | Package init, version 0.1.0 |
| `iax2.py` | IAX2 protocol handler — REGREQ/NEW/DTMF/HANGUP + mini voice frames |
| `audio.py` | Audio transcoding — OPUS↔PCM↔ulaw, 16kHz↔8kHz resampling |
| `server.py` | aiohttp daemon — WebSocket `/ws`, health `/health`, IAX2 lifecycle |
| `ami_client.py` | AMI async TCP client — connect, login, originate, call monitoring |

### Asterisk Config (`install/asterisk/`)

| File | Purpose |
|------|---------|
| `iax.conf` | IAX2 phone extension template for bridge registration |
| `rpt.conf` | Phone mode configuration guide for app_rpt PTT binding |

### System Integration

| File | Purpose |
|------|---------|
| `install/chilemon-webrtc.service` | systemd unit — auto-start, Restart=always |
| `install/install_chilemon.sh` (Step 12) | Main installer — includes bridge automatically |
| `install/install_webrtc.sh` | Standalone installer — apt deps + service enable |
| `config/app.php` | Bridge constants (`WEBRTC_PORT`, `IAX_PHONE_USER`, etc.) |
| `config/local.php` | Local overrides (secrets, per-instance config) |

### Dashboard

| File | Purpose |
|------|---------|
| `public/assets/js/ptt-widget.js` | Vanilla JS PTT widget — WS connect, key/unkey, volume bar |
| `public/assets/css/dashboard.css` | PTT widget styles (floating button, status dot) |
| `public/views/dashboard.php` | PTT container anchor |

### API

| File | Purpose |
|------|---------|
| `public/api/ptt-status.php` | Proxies bridge `/health` → JSON status for dashboard |
| `public/api/ptt-ws-token.php` | Generates HMAC token for WebSocket auth |

## Installation

### 1. Install dependencies on the RPi

```bash
# Copy the install script and run it
  sudo bash install/install_chilemon.sh

Or standalone:

  sudo bash install/install_webrtc.sh
```

This installs Python packages, creates the bridge directory, and enables the
systemd service.

### 2. Configure Asterisk

Copy and adapt the template configs:

```bash
# IAX2 phone extension — add to /etc/asterisk/iax.conf
# See install/asterisk/iax.conf for the template

# Phone mode — verify /etc/asterisk/rpt.conf
# See install/asterisk/rpt.conf for the required settings
```

Key settings in `iax.conf`:
```
[webrtc-bridge]
type=friend
host=dynamic
context=radio-ptt
secret=YOUR_SECRET_HERE
disallow=all
allow=ulaw
```

Key settings in `rpt.conf`:
```
phonelogin=yes
phonecontext=radio-ptt
```

### 3. Configure the bridge

Edit `config/local.php`:
```php
'webrtc_port' => 9091,
'webrtc_secret' => 'your-hmac-secret-here',
'iax_phone_user' => 'webrtc-bridge',
'iax_phone_pass' => 'your-iax-secret-here',
```

### 4. Restart and verify

```bash
sudo systemctl restart chilemon-webrtc
sudo systemctl status chilemon-webrtc

# Check the health endpoint
curl http://127.0.0.1:9091/health
# → {"status":"ok"}
```

## Configuration Reference

### Environment Variables (bridge daemon)

| Variable | Default | Description |
|----------|---------|-------------|
| `WEBRTC_PORT` | `9091` | Bridge HTTP/WS port |
| `IAX_HOST` | `127.0.0.1` | Asterisk IAX2 bind address |
| `IAX_PORT` | `4569` | Asterisk IAX2 UDP port |
| `IAX_PHONE_USER` | `webrtc-bridge` | IAX2 phone extension username |
| `IAX_PHONE_PASS` | *(required)* | IAX2 phone extension secret |
| `WEBRTC_SECRET` | *(required)* | HMAC signing secret for WS tokens |
| `ASL_NODE` | `61916` | Target ASL node number for PTT calls |

### PHP Constants (`config/app.php`)

| Constant | Default | Description |
|----------|---------|-------------|
| `WEBRTC_PORT` | `9091` | Bridge listening port |
| `IAX_PHONE_USER` | `'webrtc-bridge'` | IAX2 phone extension user |
| `IAX_PHONE_PASS` | *(required)* | IAX2 phone extension secret |
| `WEBRTC_SECRET` | *(required)* | HMAC signing secret |

## Usage

### Dashboard Widget

Once the bridge is running, the dashboard shows a floating PTT widget at the
bottom-right corner:

- **Click and hold** (or press and hold **Space**) to transmit
- **Release** to stop transmitting
- Status dot: 🟢 connected, 🟡 transmitting, 🔴 unreachable
- Volume bar shows received audio level

### WebSocket Protocol

Messages are JSON:

```json
// Client → Bridge
{"type": "ptt", "action": "key"}      // Key transmitter
{"type": "ptt", "action": "unkey"}    // Unkey transmitter

// Bridge → Client
{"type": "status", "registered": true, "in_call": true, "ptt_active": false}
{"type": "audio", "data": "hex_float32_audio", "rate": 16000}
```

### Auth Flow

1. Dashboard loads `GET /api/ptt-ws-token.php` (session-authenticated)
2. PHP returns `{"token": "username:a1b2c3...hex..."}`
3. JS connects to `ws://host:9091/ws?token=username:a1b2c3...hex...`
4. Bridge validates HMAC signature of username using shared `WEBRTC_SECRET`

## Known Limitations (Cycle 1)

| Issue | Impact | Workaround |
|-------|--------|------------|
| ~~No Asterisk restart recovery~~ | **Resolved**: bridge-reversal (v0.2.0) | AMI client auto-reconnects and re-originates on restart |
| DTMF sent fire-and-forget | No retry on missed DTMF | Generally reliable on localhost |
| No hardware check | Won't warn if <1GB RAM | Monitor with `htop` |
| `audioop` deprecated in Python 3.13+ | Currently on Python 3.11 (Debian 12) | Plan migration before OS upgrade |
| No TURN/STUN | LAN-only WebRTC | Requires tailscale/VPN for remote access |
| WebSocket no keepalive | Silent drop on idle | Bridge sends periodic pings (planned) |

## Roadmap

### Cycle 2 — Production Readiness
- TURN/STUN server for external access
- Let's Encrypt for HTTPS/WSS
- Multi-user authentication and session management
- WebSocket keepalive and ping/pong

### Cycle 3 — Hardening
- Prometheus metrics endpoint
- Structured logging (JSON)
- Docker containerization
- CI/CD pipeline with GitHub Actions
- System tests against real Asterisk

## Security Notes

- **IAX2 credentials** are stored in `config/local.php` — keep this file out of
  version control (already in `.gitignore`)
- **HMAC tokens** are short-lived (one-time use recommended) and scoped to a
  single WebSocket session
- The bridge runs on a separate port (9091) — firewall rules should restrict
  access to the web server only
- PHP API endpoints use existing session auth + rate limiting

## Debugging

```bash
# View bridge logs
sudo journalctl -u chilemon-webrtc -f

# Test IAX2 registration manually
sudo tcpdump -i lo -nn port 4569

# Check if Asterisk sees the bridge
sudo asterisk -rx "iax2 show peers"

# Check health
curl http://127.0.0.1:9091/health

# Restart bridge
sudo systemctl restart chilemon-webrtc
```
