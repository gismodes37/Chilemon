"""Tests for dynamic audio device re-resolution.

The companion must follow the OS default device (or the configured name) while
it runs, instead of holding onto the device that happened to be default at
startup. Run with:

    python -m pytest tests/companion/audio_device_test.py -v
"""

from __future__ import annotations

import time

from companion.audio import AudioEngine, SILENT_INPUT_S


def _engine() -> AudioEngine:
    engine = AudioEngine()
    engine._running = True
    engine._input_stream = object()  # type: ignore[assignment]
    engine._output_stream = object()  # type: ignore[assignment]
    engine._input_device_id = 0
    engine._output_device_id = 0
    engine._current_device_ids = lambda: (0, 0)  # type: ignore[method-assign]
    now = time.monotonic()
    engine._last_input_callback = now
    engine._last_output_callback = now
    return engine


def _spy_restart(engine: AudioEngine) -> list[str]:
    calls: list[str] = []
    engine.stop = lambda: calls.append("stop")  # type: ignore[method-assign]
    engine.start = lambda: calls.append("start")  # type: ignore[method-assign]
    return calls


def test_changed_default_device_reopens_streams() -> None:
    """A new OS default input must be picked up without a manual restart."""
    engine = _engine()
    engine._current_device_ids = lambda: (1, 0)  # type: ignore[method-assign]
    calls = _spy_restart(engine)

    assert engine.check_health() is True
    assert calls == ["stop", "start"]


def test_silent_input_reopens_streams() -> None:
    """Callbacks can keep firing while the opened device delivers silence."""
    engine = _engine()
    engine._input_silent_since = time.monotonic() - SILENT_INPUT_S - 1
    calls = _spy_restart(engine)

    assert engine.check_health() is True
    assert calls == ["stop", "start"]
    assert engine._input_silent_since == 0.0, "silence timer must reset on restart"


def test_active_input_does_not_reopen_streams() -> None:
    """Normal mic levels must not cause needless stream churn."""
    engine = _engine()
    calls = _spy_restart(engine)

    assert engine.check_health() is False
    assert calls == []


def test_restart_cooldown_prevents_a_loop() -> None:
    """Repeated silent checks must not restart on every health tick."""
    engine = _engine()
    engine._input_silent_since = time.monotonic() - SILENT_INPUT_S - 1
    engine._last_restart = time.monotonic()
    calls = _spy_restart(engine)

    assert engine.check_health() is False
    assert calls == []


def test_emit_levels_tracks_silence_and_activity() -> None:
    """The silence timer must start on quiet frames and reset on real audio."""
    engine = _engine()
    engine.on_levels = lambda rms, spectrum: None  # type: ignore[assignment]

    engine._emit_levels(b"\xff" * 160)  # ulaw 0xFF decodes to silence
    assert engine._input_silent_since > 0.0

    engine._emit_levels(b"\x00" * 160)  # ulaw 0x00 decodes to full scale
    assert engine._input_silent_since == 0.0
