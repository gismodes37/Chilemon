"""Tests for companion audio-level routing, throttling and WS payload shape.

Covers S6/S7 from the companion spectrum visualizer task: raw RMS still gates
TX, RX playback emits levels, the shared on_levels hook stays backwards
compatible, broadcasts are throttled per direction, and the additive ``dir``
field reaches the browser without changing existing fields.

Run with: python -m pytest tests/companion/level_routing_test.py -v
"""

from __future__ import annotations

import asyncio
import json
from types import SimpleNamespace
from typing import Any

import numpy as np

import companion.main as main_module
from companion.audio import AudioEngine, lin2ulaw, ulaw2lin
from companion.main import CompanionApp
from companion.ws_server import WSServer


class _Peer:
    """Minimal websocket peer that records sent strings."""

    def __init__(self) -> None:
        self.sent: list[str] = []

    async def send_str(self, msg: str) -> None:
        self.sent.append(msg)


class _RecorderWS:
    """Minimal WSServer stand-in for throttle tests."""

    def __init__(self) -> None:
        self.calls: list[tuple[float, list[float], str]] = []

    async def broadcast_audio_level(
        self, rms: float, spectrum: list[float], direction: str
    ) -> None:
        self.calls.append((rms, spectrum, direction))


def _tone_payload() -> bytes:
    """A loud 1 kHz tone so decoded levels are unambiguous."""
    t = np.arange(160, dtype=np.float64) / 8000.0
    pcm = np.rint(12000.0 * np.sin(2.0 * np.pi * 1000.0 * t)).astype("<i2")
    return lin2ulaw(pcm.tobytes())


def test_output_callback_emits_rx_levels() -> None:
    """RX playback must feed the visualizer from the decoded ulaw payload."""
    engine = AudioEngine()
    engine._running = True
    events: list[tuple[float, list[float], str]] = []
    engine.on_levels = (  # type: ignore[assignment]
        lambda rms, spectrum, direction: events.append((rms, spectrum, direction))
    )
    frame = _tone_payload()
    engine._jitter_buffer = SimpleNamespace(  # type: ignore[assignment]
        pop=lambda: frame
    )
    outdata = np.zeros((160, 1), dtype=np.int16)

    engine._output_callback(outdata, 160, None, None)  # type: ignore[arg-type]

    assert len(events) == 1
    rms, spectrum, direction = events[0]
    assert direction == "rx"
    assert rms > 0.0
    assert len(spectrum) == 16
    # Playback still happened: the buffer was not consumed by the level probe.
    assert not np.array_equal(outdata, np.zeros((160, 1), dtype=np.int16))


def test_on_levels_stays_backwards_compatible() -> None:
    """Existing one- and two-argument hooks must keep working unchanged."""
    engine = AudioEngine()
    two_arg: list[tuple[float, list[float]]] = []
    engine.on_levels = lambda rms, spectrum: two_arg.append((rms, spectrum))  # type: ignore[assignment]
    rms = engine._emit_levels(bytes([0x00] * 160))
    assert two_arg[0][0] == rms
    assert len(two_arg[0][1]) == 16

    one_arg: list[float] = []
    engine.on_levels = lambda rms: one_arg.append(rms)  # type: ignore[assignment]
    rms = engine._emit_levels(bytes([0x00] * 160))
    assert one_arg == [rms]

    three_arg: list[str] = []
    engine.on_levels = (  # type: ignore[assignment]
        lambda rms, spectrum, direction: three_arg.append(direction)
    )
    engine._emit_levels(bytes([0x00] * 160), direction="rx")
    assert three_arg == ["rx"]


def test_input_callback_still_feeds_raw_rms_to_tx_gate() -> None:
    """S6: raw RMS from _compute_levels must keep driving _handle_tx."""
    engine = AudioEngine()
    engine._running = True
    engine.on_tx_audio = lambda payload: None
    seen: list[float] = []
    engine._handle_tx = (  # type: ignore[method-assign]
        lambda payload, rms: seen.append(rms)
    )
    indata = np.frombuffer(ulaw2lin(_tone_payload()), dtype=np.int16).reshape(-1, 1)

    engine._input_callback(indata, 160, None, None)  # type: ignore[arg-type]

    payload = lin2ulaw(indata.tobytes())
    expected, spectrum = AudioEngine._compute_levels(payload)
    assert seen == [expected]
    assert len(spectrum) == 16


def test_level_broadcast_throttled_per_direction(
    monkeypatch: Any,
) -> None:
    """A burst of 20 ms callbacks must not flood the WS; tx/rx are independent."""
    app = CompanionApp.__new__(CompanionApp)
    app._last_level_broadcast = {}
    app.ws_server = _RecorderWS()
    clock = {"now": 100.0}
    monkeypatch.setattr(
        main_module, "time", SimpleNamespace(monotonic=lambda: clock["now"]),
        raising=False,
    )

    async def scenario() -> None:
        app._loop = asyncio.get_running_loop()
        for _ in range(5):
            app._on_audio_levels(0.5, [0.1] * 16, "tx")
            clock["now"] += 0.005
        app._on_audio_levels(0.9, [0.8] * 16, "rx")
        clock["now"] += 0.05
        app._on_audio_levels(0.5, [0.1] * 16, "tx")
        await asyncio.sleep(0)
        await asyncio.sleep(0)

    asyncio.run(scenario())

    assert app.ws_server.calls == [
        (0.5, [0.1] * 16, "tx"),
        (0.9, [0.8] * 16, "rx"),
        (0.5, [0.1] * 16, "tx"),
    ]


def test_ws_payload_carries_dir_for_both_directions() -> None:
    """S7: dir is additive; every existing field keeps its name and type."""
    server = WSServer()
    peer = _Peer()
    server._peers.add(peer)  # type: ignore[arg-type]

    async def scenario() -> None:
        await server.broadcast_audio_level(0.25, [0.1] * 16, "tx")
        await server.broadcast_audio_level(0.75, [0.9] * 16, "rx")
        # Two-argument callers still work and default to TX.
        await server.broadcast_audio_level(0.5, [0.0] * 16)

    asyncio.run(scenario())

    payloads = [json.loads(msg) for msg in peer.sent]
    assert payloads[0] == {
        "type": "audio_level",
        "rms": 0.25,
        "spectrum": [0.1] * 16,
        "dir": "tx",
    }
    assert payloads[1] == {
        "type": "audio_level",
        "rms": 0.75,
        "spectrum": [0.9] * 16,
        "dir": "rx",
    }
    assert payloads[2]["dir"] == "tx"
    assert set(payloads[2]) == {"type", "rms", "spectrum", "dir"}
