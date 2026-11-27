"""Regression: idle auto-hangup still works after the call-start timestamp reset."""

from __future__ import annotations

import asyncio
import time
from unittest.mock import AsyncMock, MagicMock

from companion.session import CALL_IDLE_TIMEOUT, CompanionSession


def _in_call_session_with_age(age: float) -> tuple[CompanionSession, MagicMock]:
    session = CompanionSession.__new__(CompanionSession)
    session._registered = True
    session._in_call = True
    session._reconnecting = False
    session.ptt_active = False
    session._last_audio_time = time.monotonic() - age
    session._emit_status = AsyncMock()
    iax = MagicMock()
    iax.send_dtmf.return_value = True
    iax.hangup_call = AsyncMock()
    session._session = iax
    return session, iax


def test_idle_timeout_still_hangs_up_after_grace_period() -> None:
    session, iax = _in_call_session_with_age(CALL_IDLE_TIMEOUT + 1)

    asyncio.run(session._check_health())

    iax.hangup_call.assert_awaited_once()
    assert session._in_call is False
    assert session._last_audio_time == 0.0


def test_idle_timeout_does_not_hang_up_within_grace_period() -> None:
    session, iax = _in_call_session_with_age(CALL_IDLE_TIMEOUT - 1)

    asyncio.run(session._check_health())

    iax.hangup_call.assert_not_awaited()
    assert session._in_call is True
