"""Regression tests: blocking PortAudio work must never freeze the event loop.

A mic probe can block indefinitely on Windows MME when a device is still held
by a previous stream. Companion's WS server and /health share the same asyncio
loop, so a synchronous device open there surfaces to the operator as
"Companion no detectado" even while the process is alive.
"""

from __future__ import annotations

import asyncio
import threading
from unittest.mock import AsyncMock, MagicMock

from companion.main import CompanionApp


def _app_with_audio() -> CompanionApp:
    """Build an app instance without startup side effects."""
    app = CompanionApp.__new__(CompanionApp)
    app.session = MagicMock()
    app.session.place_call = AsyncMock()
    app.session.in_call = True
    app.audio = MagicMock()
    return app


def test_blocking_audio_open_does_not_freeze_event_loop() -> None:
    """A stuck mic probe must not stop the loop from servicing other work."""
    release = threading.Event()
    done = threading.Event()

    def blocking_start() -> None:
        release.wait(3)

    app = _app_with_audio()
    app.audio.start.side_effect = blocking_start

    async def scenario() -> None:
        ticks = 0

        async def heartbeat() -> None:
            nonlocal ticks
            while not done.is_set():
                await asyncio.sleep(0.01)
                ticks += 1

        hb = asyncio.create_task(heartbeat())
        task = asyncio.create_task(app._place_call("55553"))
        # While PortAudio is blocked, unrelated loop work must still advance.
        await asyncio.sleep(0.2)
        assert ticks >= 5, f"event loop froze during audio open (ticks={ticks})"
        release.set()
        await asyncio.wait_for(task, timeout=2)
        done.set()
        await hb

    asyncio.run(scenario())
    app.audio.start.assert_called_once()


def test_audio_operation_timeout_returns_control() -> None:
    """A permanently stuck device open is bounded, not fatal to the loop."""
    release = threading.Event()

    def blocking_start() -> None:
        release.wait(3)

    app = _app_with_audio()
    app.audio.start.side_effect = blocking_start

    async def scenario() -> None:
        await asyncio.wait_for(
            app._run_audio(app.audio.start, timeout=0.1), timeout=1
        )
        release.set()

    asyncio.run(scenario())


def test_serialized_audio_operations_do_not_overlap() -> None:
    """start/stop/reset keep their previous serialized ordering."""
    app = _app_with_audio()
    active = 0
    peak = 0
    lock = threading.Lock()

    def tracked() -> None:
        nonlocal active, peak
        with lock:
            active += 1
            peak = max(peak, active)
        threading.Event().wait(0.02)
        with lock:
            active -= 1

    app.audio.start.side_effect = tracked
    app.audio.reset.side_effect = tracked
    app.audio.stop.side_effect = tracked

    async def scenario() -> None:
        await asyncio.gather(
            app._run_audio(app.audio.start),
            app._run_audio(app._close_audio),
        )

    asyncio.run(scenario())
    assert peak == 1, "audio device operations overlapped"
