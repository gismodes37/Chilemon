"""Verifier regression probes for S1: off-loop audio, shared lock, live /health."""

import asyncio
import threading
import time
from unittest.mock import AsyncMock, MagicMock

import aiohttp
import pytest

from companion.main import AUDIO_OP_TIMEOUT_S, CompanionApp
from companion.ws_server import WSServer


def _app():
    app = CompanionApp.__new__(CompanionApp)
    app.session = MagicMock()
    for name in ("hangup_call", "place_call", "stop"):
        setattr(app.session, name, AsyncMock())
    app.session.in_call = True
    app.audio = MagicMock()
    app.audio.is_running = False
    app.ws_server = MagicMock()
    app.ws_server.broadcast_status = AsyncMock()
    app.ws_server.stop = AsyncMock()
    return app


def _block(release, seen):
    seen.append(threading.get_ident())
    release.wait(1)


@pytest.mark.parametrize(
    "site",
    ["hangup", "place_call", "status_start", "status_close", "stop", "health"],
)
def test_blocking_audio_never_runs_on_loop_thread(site):
    release, seen = threading.Event(), []
    app = _app()
    for name in ("start", "stop", "reset", "check_health"):
        getattr(app.audio, name).side_effect = lambda: _block(release, seen)
    loop_tid = threading.get_ident()

    async def run():
        ticks, done = 0, False

        async def heartbeat():
            nonlocal ticks
            while not done:
                await asyncio.sleep(0.01)
                ticks += 1

        hb = asyncio.create_task(heartbeat())
        if site == "hangup":
            task, wait = asyncio.create_task(app._hangup()), 0.15
        elif site == "place_call":
            task, wait = asyncio.create_task(app._place_call("55553")), 0.15
        elif site == "status_start":
            task, wait = asyncio.create_task(app._on_session_status({"call_active": True})), 0.15
        elif site == "status_close":
            app.audio.is_running = True
            task, wait = asyncio.create_task(app._on_session_status({"call_active": False})), 0.15
        elif site == "stop":
            task, wait = asyncio.create_task(app.stop()), 0.15
        else:
            task, wait = asyncio.create_task(app._audio_health_loop()), 2.15
        await asyncio.sleep(wait)
        assert ticks >= 5, f"loop froze at {site}: ticks={ticks}"
        if site == "health":
            task.cancel()
            await task
        else:
            release.set()
            await asyncio.wait_for(task, 2)
        done = True
        release.set()
        await hb

    asyncio.run(run())
    assert seen and all(tid != loop_tid for tid in seen)


def test_loopback_health_and_ws_stay_responsive_during_stuck_audio():
    release = threading.Event()
    app = _app()
    app.audio.start.side_effect = lambda: release.wait(1)

    async def run():
        server = WSServer(host="127.0.0.1", port=0)
        await server.start()
        port = server._site._server.sockets[0].getsockname()[1]
        op = asyncio.create_task(app._run_audio(app.audio.start, timeout=0.2))
        started = time.monotonic()
        async with aiohttp.ClientSession(timeout=aiohttp.ClientTimeout(total=1)) as session:
            async with session.get(f"http://127.0.0.1:{port}/health") as response:
                assert response.status == 200
                assert (await response.json())["status"] == "ok"
            async with session.ws_connect(f"http://127.0.0.1:{port}/ws") as ws:
                assert not ws.closed
        assert time.monotonic() - started < 1
        release.set()
        await op
        await server.stop()

    asyncio.run(run())


def test_audio_lock_shared_across_call_sites():
    app = _app()
    app.audio.is_running = True
    active = peak = 0
    guard = threading.Lock()

    def tracked():
        nonlocal active, peak
        with guard:
            active += 1
            peak = max(peak, active)
        time.sleep(0.05)
        with guard:
            active -= 1

    for name in ("start", "stop", "reset"):
        getattr(app.audio, name).side_effect = tracked

    async def run():
        await asyncio.gather(
            app._place_call("55553"),
            app._hangup(),
            app._on_session_status({"call_active": False}),
        )

    asyncio.run(run())
    assert peak == 1
    assert AUDIO_OP_TIMEOUT_S == 5.0