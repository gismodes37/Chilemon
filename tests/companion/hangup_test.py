"""
Regression tests for the disconnect path.

A disconnect while the transmitter is keyed must force app_rpt to unkey
before closing the IAX2 channel, and buffered RX audio must be dropped so
no tone keeps playing after the call ends.
"""

from __future__ import annotations

import asyncio
from unittest.mock import AsyncMock, MagicMock

from companion.audio import AudioEngine
from companion.main import CompanionApp
from companion.session import CompanionSession


def _in_call_session() -> tuple[CompanionSession, MagicMock]:
    session = CompanionSession.__new__(CompanionSession)
    iax = MagicMock()
    iax.send_dtmf.return_value = True
    iax.hangup_call = AsyncMock()
    session._session = iax
    session._in_call = True
    session.ptt_active = True
    session._emit_status = AsyncMock()
    return session, iax


def test_hangup_sends_force_unkey_before_closing_channel() -> None:
    """'#' must reach app_rpt before the IAX2 channel disappears."""
    session, iax = _in_call_session()

    asyncio.run(session.hangup_call())

    iax.send_dtmf.assert_called_once_with("#")
    iax.hangup_call.assert_awaited_once()
    assert session._in_call is False
    assert session.ptt_active is False


def test_hangup_without_active_call_is_noop() -> None:
    """No DTMF and no hangup when there is no active call."""
    session = CompanionSession.__new__(CompanionSession)
    iax = MagicMock()
    iax.send_dtmf.return_value = True
    iax.hangup_call = AsyncMock()
    session._session = iax
    session._in_call = False
    session._emit_status = AsyncMock()

    asyncio.run(session.hangup_call())

    iax.send_dtmf.assert_not_called()
    iax.hangup_call.assert_not_awaited()


def test_audio_reset_clears_jitter_buffer() -> None:
    """reset() must drop buffered RX frames."""
    audio = AudioEngine.__new__(AudioEngine)
    audio._jitter_buffer = MagicMock()

    audio.reset()

    audio._jitter_buffer.reset.assert_called_once()


def test_app_hangup_resets_audio_after_session_hangup() -> None:
    """The companion wires hangup through a handler that also resets audio."""
    app = CompanionApp.__new__(CompanionApp)
    app.session = MagicMock()
    app.session.hangup_call = AsyncMock()
    app.audio = MagicMock()

    asyncio.run(app._hangup())

    app.session.hangup_call.assert_awaited_once()
    app.audio.reset.assert_called_once()
