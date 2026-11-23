"""
PTT must be an actual transmit gate, not just a status flag.

AllStar nodes in duplex=0 (VOX) key the transmitter from any audio arriving
on the IAX2 channel. If the companion or the browser bridge forwards mic
audio while PTT is released, the node keys up by VOX and a feedback loop
(speaker -> mic -> node) produces a constant tone on the remote end. The
sender must drop every frame unless PTT is explicitly active.

Run with: python -m pytest tests/companion/ptt_audio_gate_test.py -v
"""

from __future__ import annotations

import re
from pathlib import Path
from unittest.mock import AsyncMock, MagicMock

from companion.session import CompanionSession

ROOT = Path(__file__).resolve().parents[2]
BRIDGE_SERVER = ROOT / "app" / "Services" / "WebRTCBridge" / "server.py"


def _companion(ptt_active: bool) -> tuple[CompanionSession, MagicMock]:
    session = CompanionSession.__new__(CompanionSession)
    iax = MagicMock()
    iax.send_voice.return_value = True
    iax.send_dtmf.return_value = True
    session._session = iax
    session._in_call = True
    session.ptt_active = ptt_active
    session._emit_status = AsyncMock()
    return session, iax


def test_companion_drops_audio_when_ptt_released() -> None:
    """Mic frames must not reach IAX2 while PTT is up."""
    session, iax = _companion(ptt_active=False)

    assert session.send_audio(bytes(160)) is False
    iax.send_voice.assert_not_called()


def test_companion_forwards_audio_while_ptt_held() -> None:
    """The same frames must flow once the operator keys PTT."""
    session, iax = _companion(ptt_active=True)

    assert session.send_audio(bytes(160)) is True
    iax.send_voice.assert_called_once()


def test_companion_drops_audio_when_no_call() -> None:
    """PTT alone cannot transmit without an established IAX2 call."""
    session, iax = _companion(ptt_active=True)
    session._in_call = False

    assert session.send_audio(bytes(160)) is False
    iax.send_voice.assert_not_called()


def test_unkey_forces_transmitter_off_with_hash() -> None:
    """Unkey must send '#', which forces app_rpt TX off regardless of toggle state."""
    session, iax = _companion(ptt_active=True)

    import asyncio

    asyncio.run(session.ptt_unkey())

    iax.send_dtmf.assert_called_once_with("#")
    assert session.ptt_active is False


def test_ws_server_unkeys_when_last_peer_disconnects() -> None:
    """Losing the dashboard (tab close / network drop) must release PTT."""
    source = (ROOT / "companion" / "ws_server.py").read_text(
        encoding="utf-8", errors="replace"
    )
    # The disconnect `finally` block must call back into PTT unkey, not just
    # drop the peer from the set.
    assert re.search(
        r'finally:.*?self\._peers\.discard\(ws\).*?(await self\.on_ptt_unkey\(\))',
        source,
        re.DOTALL,
    ), "last WS disconnect must force PTT unkey"


def test_bridge_drops_audio_when_ptt_released() -> None:
    """The browser path must gate audio_tx on _ptt_active before send_voice."""
    source = BRIDGE_SERVER.read_text(encoding="utf-8", errors="replace")

    assert re.search(
        r'elif msg_type == "audio_tx":.*?if not self\._ptt_active:.*?return.*?sent = self\._active_call\.send_voice\(ulaw\)',
        source,
        re.DOTALL,
    ), "audio_tx must be dropped unless PTT is active"


def test_bridge_unkey_forces_transmitter_off_with_hash() -> None:
    """Browser PTT unkey must also force TX off with '#'."""
    source = BRIDGE_SERVER.read_text(encoding="utf-8", errors="replace")

    assert re.search(
        r'async def _ptt_unkey\(self\).*?send_dtmf\("#"\)',
        source,
        re.DOTALL,
    ), "browser unkey must send '#' to force the transmitter off"


def test_bridge_unkeys_before_grace_period_on_last_ws_disconnect() -> None:
    """A tab close with PTT held must release TX before keeping the call."""
    source = BRIDGE_SERVER.read_text(encoding="utf-8", errors="replace")

    assert re.search(
        r'if not self\._ws_peers:.*?await self\._ptt_unkey\(\).*?if self\._active_call',
        source,
        re.DOTALL,
    ), "last WS disconnect must unkey before the grace-period branch"
