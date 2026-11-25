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
    app.audio.stop.assert_called_once()


def test_audio_start_is_idempotent(monkeypatch) -> None:
    """A reconnect must not open a second pair of streams."""
    import companion.audio as audio_module

    engine = AudioEngine.__new__(AudioEngine)
    engine._running = True
    fake_sd = MagicMock()
    monkeypatch.setattr(audio_module, "sd", fake_sd)

    engine.start()

    fake_sd.InputStream.assert_not_called()
    fake_sd.OutputStream.assert_not_called()


def test_app_place_call_reopens_audio_after_success() -> None:
    """Streams are reopened only when the IAX2 call was actually established."""
    app = CompanionApp.__new__(CompanionApp)
    app.session = MagicMock()
    app.session.place_call = AsyncMock()
    app.session.in_call = True
    app.audio = MagicMock()

    asyncio.run(app._place_call("55553"))

    app.session.place_call.assert_awaited_once_with("55553")
    app.audio.start.assert_called_once()


def test_app_place_call_leaves_audio_closed_after_failure() -> None:
    """A failed call must not reopen mic and speaker and restart the hum."""
    app = CompanionApp.__new__(CompanionApp)
    app.session = MagicMock()
    app.session.place_call = AsyncMock()
    app.session.in_call = False
    app.audio = MagicMock()

    asyncio.run(app._place_call("55553"))

    app.audio.start.assert_not_called()


def test_session_status_without_call_ends_audio() -> None:
    """Idle timeout and remote hangup must close streams too."""
    app = CompanionApp.__new__(CompanionApp)
    app.audio = MagicMock()
    app.audio.is_running = True
    app.ws_server = MagicMock()
    app.ws_server.broadcast_status = AsyncMock()

    asyncio.run(app._on_session_status({"call_active": False}))

    app.audio.stop.assert_called_once()
    app.audio.reset.assert_called_once()


def test_session_status_with_call_reopens_audio() -> None:
    """A call started outside the dashboard still needs live audio."""
    app = CompanionApp.__new__(CompanionApp)
    app.audio = MagicMock()
    app.audio.is_running = False
    app.ws_server = MagicMock()
    app.ws_server.broadcast_status = AsyncMock()

    asyncio.run(app._on_session_status({"call_active": True}))

    app.audio.start.assert_called_once()
