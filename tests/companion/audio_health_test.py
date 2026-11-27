"""
Tests for stalled PortAudio callback detection and recovery.

A sleeping Bluetooth device can stop sounddevice callbacks without closing
the stream objects. The engine must notice and reopen the streams so audio
and the browser visualizer come back without a manual companion restart.

Run with: python -m pytest tests/companion/audio_health_test.py -v
"""

from __future__ import annotations

import time

from companion.audio import AUDIO_CALLBACK_STALL_S, SILENT_INPUT_S, AudioEngine


class _Stream:
    """Opaque stand-in for a sounddevice stream object."""


def _engine() -> AudioEngine:
    engine = AudioEngine()
    engine._running = True
    engine._input_stream = _Stream()  # type: ignore[assignment]
    engine._output_stream = _Stream()  # type: ignore[assignment]
    return engine


def test_fresh_streams_are_healthy() -> None:
    """Freshly started streams must not be flagged as stalled."""
    engine = _engine()
    now = time.monotonic()
    engine._last_input_callback = now
    engine._last_output_callback = now

    assert engine.check_health() is False


def test_stalled_output_triggers_restart() -> None:
    """A dead playback callback must reopen the streams."""
    engine = _engine()
    now = time.monotonic()
    engine._last_input_callback = now
    engine._last_output_callback = now - AUDIO_CALLBACK_STALL_S - 1

    restarts: list[int] = []
    engine.stop = lambda: restarts.append(1)  # type: ignore[method-assign]
    engine.start = lambda: restarts.append(2)  # type: ignore[method-assign]

    assert engine.check_health() is True
    assert restarts == [1, 2], "expected stop() then start()"


def test_stalled_input_triggers_restart() -> None:
    """A dead mic callback must reopen the streams too."""
    engine = _engine()
    now = time.monotonic()
    engine._last_input_callback = now - AUDIO_CALLBACK_STALL_S - 1
    engine._last_output_callback = now

    calls: list[str] = []
    engine.stop = lambda: calls.append("stop")  # type: ignore[method-assign]
    engine.start = lambda: calls.append("start")  # type: ignore[method-assign]

    assert engine.check_health() is True
    assert calls == ["stop", "start"]


def test_mic_silence_without_ptt_does_not_restart_streams() -> None:
    """An operator listening with PTT up is not a dead microphone."""
    engine = _engine()
    now = time.monotonic()
    engine._last_input_callback = now
    engine._last_output_callback = now
    engine._tx_state = "active"
    engine._input_silent_since = now - SILENT_INPUT_S - 1
    engine.is_ptt_active = lambda: False  # type: ignore[method-assign]

    calls: list[str] = []
    engine.stop = lambda: calls.append("stop")  # type: ignore[method-assign]
    engine.start = lambda: calls.append("start")  # type: ignore[method-assign]

    assert engine.check_health() is False
    assert calls == []


def test_listening_silence_does_not_age_into_a_keyed_restart() -> None:
    """Silence while listening must not restart streams on the next key.

    The health loop runs every 2s. If the silence timer keeps aging while PTT
    is up, the first keyed tick sees 15+ seconds of silence and tears down the
    streams exactly when the operator starts transmitting.
    """
    engine = _engine()
    now = time.monotonic()
    engine._last_input_callback = now
    engine._last_output_callback = now
    engine._tx_state = "active"
    engine._compute_levels = lambda _payload: (0.0, [])  # type: ignore[method-assign]
    engine.on_levels = None

    # Long silence while listening: no PTT, so the timer stays cleared.
    engine.is_ptt_active = lambda: False  # type: ignore[method-assign]
    engine._input_silent_since = now - SILENT_INPUT_S - 1
    engine._emit_levels(b"\xff" * 320)
    assert engine._input_silent_since == 0.0

    # The first keyed silent frame starts a fresh window, not an old one.
    engine.is_ptt_active = lambda: True  # type: ignore[method-assign]
    engine._emit_levels(b"\xff" * 320)
    assert 0.0 < engine._input_silent_since <= time.monotonic()

    calls: list[str] = []
    engine.stop = lambda: calls.append("stop")  # type: ignore[method-assign]
    engine.start = lambda: calls.append("start")  # type: ignore[method-assign]

    assert engine.check_health() is False
    assert calls == []


def test_mic_silence_while_keyed_still_restarts_streams() -> None:
    """A keyed operator with no mic signal still needs device recovery."""
    engine = _engine()
    now = time.monotonic()
    engine._last_input_callback = now
    engine._last_output_callback = now
    engine._tx_state = "active"
    engine._input_silent_since = now - SILENT_INPUT_S - 1
    engine.is_ptt_active = lambda: True  # type: ignore[method-assign]

    calls: list[str] = []
    engine.stop = lambda: calls.append("stop")  # type: ignore[method-assign]
    engine.start = lambda: calls.append("start")  # type: ignore[method-assign]

    assert engine.check_health() is True
    assert calls == ["stop", "start"]


def test_stopped_engine_is_not_restarted() -> None:
    """An intentionally stopped engine must stay stopped."""
    engine = AudioEngine()
    engine._running = False
    calls: list[str] = []
    engine.stop = lambda: calls.append("stop")  # type: ignore[method-assign]
    engine.start = lambda: calls.append("start")  # type: ignore[method-assign]

    assert engine.check_health() is False
    assert calls == []


def test_missing_streams_do_not_count_as_stalled() -> None:
    """A stream that never opened is not a stalled stream."""
    engine = AudioEngine()
    engine._running = True
    engine._input_stream = None
    engine._output_stream = None
    engine._last_input_callback = 0.0
    engine._last_output_callback = 0.0

    assert engine.check_health() is False
