"""
WebRTC Audio Bridge — Browser-based Push-to-Talk for ChileMon / AllStarLink.

This package implements a standalone Python daemon that bridges browser
WebRTC (OPUS 16 kHz) to Asterisk IAX2 (ulaw 8 kHz) via AMI Originate (bridge-reversal).

Architecture
------------
The bridge operates as an IAX2 **server** (not a client). Asterisk originates
outbound IAX2 calls via AMI ``Originate`` with ``Async: true``, bypassing
ASL3's dropped-callno-0 filter. The bridge accepts inbound NEW frames on
UDP port 9092, responds with NEWACK/ACCEPT/ANSWER, and relays audio.

Components
----------
- iax2 : IAX2 protocol handler — client mode (legacy) + server mode (``IAX2Server``, ``IAX2Call``)
- ami_client : AMI client — connect, login, Originate, event monitor, reconnect
- audio : Audio transcoding pipeline (OPUS↔PCM↔ulaw + 16 kHz↔8 kHz resampling)
- server : aiohttp + aiortc daemon (WebSocket / AMI / IAX2 / health endpoint)

Call Flow
---------
    Browser WS ──(auth)──→ bridge:9091
                              │ ami.originate(Async:true)
                              ↓
    Asterisk AMI ──────────→ Asterisk IAX2 ──(NEW)──→ bridge:9092
                              │ NEWACK + ACCEPT + ANSWER
                              ↓
                      IAX2 VOICE ←──→ bridge ←──→ WS audio
                      IAX2 HANGUP ──→ bridge → WS cleanup

Requirements
------------
- Python 3.11+ (Debian 12 / Bookworm)
- aiohttp >= 3.9
- aortc >= 1.4 (optional: enables WebRTC; without it, only health/WS endpoint starts)
- astral (stdlib) — no extra deps for IAX2 protocol
"""

__version__ = "0.2.0"
