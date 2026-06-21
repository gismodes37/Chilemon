"""
WebRTC Audio Bridge — Browser-based Push-to-Talk for ChileMon / AllStarLink.

This package implements a standalone Python daemon that bridges browser
WebRTC (OPUS 16 kHz) to Asterisk IAX2 (ulaw 8 kHz) via a minimal IAX2
protocol handler.

The bridge registers as an IAX2 phone extension on the local Asterisk server,
keys/unkeys the ASL node via DTMF (*99 / #) through app_rpt phone mode,
and transcodes audio bidirectionally.

Components
----------
- iax2 : Minimal IAX2 protocol handler (REGREQ, NEW, DTMF, voice mini-frames, HANGUP)
- audio : Audio transcoding pipeline (OPUS↔PCM↔ulaw + 16 kHz↔8 kHz resampling)
- server : aiohttp + aiortc daemon (WebSocket / WebRTC / health endpoint)

Requirements
------------
- Python 3.11+ (Debian 12 / Bookworm)
- aiohttp >= 3.9
- aortc >= 1.4 (optional: enables WebRTC; without it, only health/WS endpoint starts)
- astral (stdlib) — no extra deps for IAX2 protocol
"""

__version__ = "0.1.0"
__all__ = ["__version__"]
