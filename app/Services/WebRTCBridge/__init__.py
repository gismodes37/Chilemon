"""
WebRTC Audio Bridge — Browser-based Push-to-Talk for ChileMon / AllStarLink.

This package implements a standalone Python daemon that bridges browser
WebRTC (OPUS 16 kHz) to Asterisk IAX2 (ulaw 8 kHz) via IAX2 phone registration + AMI.

Architecture
------------
The bridge registers as an IAX2 **phone extension** with Asterisk (type=friend).
When a WebSocket peer connects, the bridge sends an AMI ``Originate`` with
``Async: true`` using ``IAX2/<phone_user>/<node>`` as the target channel.
Asterisk calls the registered phone, sending an IAX2 NEW frame. The bridge
accepts the call, and audio/DTMF flow bidirectionally.

Components
----------
- iax2 : IAX2 protocol handler — client (``IAX2Session``) + legacy server (``IAX2Server``, ``IAX2Call``)
- ami_client : AMI client — connect, login, Originate, event monitor, reconnect
- audio : Audio transcoding pipeline (OPUS↔PCM↔ulaw + 16 kHz↔8 kHz resampling)
- server : aiohttp + aiortc daemon (WebSocket / AMI / IAX2 phone / health endpoint)

Call Flow
---------
    Browser WS ──(auth)──→ bridge:9091
                              │ ami.originate(IAX2/webrtc-bridge/<node>)
                              ↓
    Asterisk AMI ──────────→ Asterisk IAX2 ──(NEW)──→ bridge (phone)
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

__version__ = "0.2.1"
