"""Tests for dynamic audio device re-resolution.

The companion must follow the OS default device (or the configured name) while
it runs, instead of holding onto the device that happened to be default at
startup. Run with:

    python -m pytest tests/companion/audio_device_test.py -v
"""

from __future__ import annotations

import time
from types import SimpleNamespace

import numpy as np

import companion.audio as audio
from companion.audio import AudioEngine, SILENT_INPUT_S


def _engine() -> AudioEngine:
    engine = AudioEngine()
    engine._running = True
    engine._input_stream = object()  # type: ignore[assignment]
    engine._output_stream = object()  # type: ignore[assignment]
    engine._input_device_id = 0
    engine._output_device_id = 0
    engine._default_device_ids = (0, 0)
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
    """A keyed mic delivering silence still needs device recovery."""
    engine = _engine()
    engine.is_ptt_active = lambda: True  # type: ignore[method-assign]
    engine._input_silent_since = time.monotonic() - SILENT_INPUT_S - 1
    calls = _spy_restart(engine)

    assert engine.check_health() is True
    assert calls == ["stop", "start"]
    assert engine._input_silent_since == 0.0, "silence timer must reset on restart"


class _ProbeSoundDevice:
    """Minimal sounddevice stand-in with generic device names and known levels."""

    def __init__(self, levels: dict[int, int]) -> None:
        self.levels = levels
        self.default = SimpleNamespace(device=(0, 3))
        self.probed: list[int] = []

    def query_devices(self) -> list[dict]:
        common = {
            "hostapi": 0,
            "default_samplerate": 44100.0,
            "max_output_channels": 0,
        }
        return [
            {**common, "name": "System Capture", "max_input_channels": 2},
            {**common, "name": "Generic Headset", "max_input_channels": 1},
            {**common, "name": "Generic Camera", "max_input_channels": 1},
            {
                "name": "Generic Speakers",
                "hostapi": 0,
                "default_samplerate": 44100.0,
                "max_input_channels": 0,
                "max_output_channels": 2,
            },
        ]

    def query_hostapis(self) -> list[dict]:
        return [{"name": "Generic Audio"}]

    def rec(self, frames: int, samplerate: int, channels: int, dtype: str, device: int) -> np.ndarray:
        self.probed.append(device)
        return np.full((frames, channels), self.levels[device], dtype=np.int16)

    def wait(self) -> None:
        return None


def test_auto_input_selection_skips_dead_default(monkeypatch) -> None:
    """Auto-detection must pick a live mic, not follow a dead OS default."""
    fake = _ProbeSoundDevice({0: 1, 1: 900, 2: 300})
    monkeypatch.setattr(audio, "sd", fake)

    assert AudioEngine()._pick_input_device() == 1
    assert 0 in fake.probed and 1 in fake.probed


def test_auto_input_selection_keeps_default_when_all_mics_are_dead(monkeypatch) -> None:
    """A device that reads digital zero cannot be rescued by switching."""
    fake = _ProbeSoundDevice({0: 0, 1: 0, 2: 0})
    monkeypatch.setattr(audio, "sd", fake)

    assert AudioEngine()._pick_input_device() == 0


def test_configured_input_device_skips_auto_probe(monkeypatch) -> None:
    """An explicit device is an override; the automatic probe must not run."""
    fake = _ProbeSoundDevice({0: 1, 1: 900, 2: 300})
    monkeypatch.setattr(audio, "sd", fake)

    assert AudioEngine(input_device="Generic Camera")._pick_input_device() == 2
    assert fake.probed == []


def test_probed_device_is_not_a_default_change() -> None:
    """Choosing a live mic must not look like the OS default changed."""
    engine = _engine()
    engine._input_device_id = 1  # probed live mic
    engine._default_device_ids = (0, 0)
    calls = _spy_restart(engine)

    assert engine.check_health() is False
    assert calls == []


def test_active_input_does_not_reopen_streams() -> None:
    """Normal mic levels must not cause needless stream churn."""
    engine = _engine()
    calls = _spy_restart(engine)

    assert engine.check_health() is False
    assert calls == []


def test_restart_cooldown_prevents_a_loop() -> None:
    """Repeated silent checks must not restart on every health tick."""
    engine = _engine()
    engine.is_ptt_active = lambda: True  # type: ignore[method-assign]
    engine._input_silent_since = time.monotonic() - SILENT_INPUT_S - 1
    engine._last_restart = time.monotonic()
    calls = _spy_restart(engine)

    assert engine.check_health() is False
    assert calls == []


def test_emit_levels_tracks_silence_and_activity() -> None:
    """While keyed, the silence timer starts on quiet frames and resets on audio."""
    engine = _engine()
    engine.on_levels = lambda rms, spectrum: None  # type: ignore[assignment]
    engine.is_ptt_active = lambda: True  # type: ignore[method-assign]

    engine._emit_levels(b"\xff" * 160)  # ulaw 0xFF decodes to silence
    assert engine._input_silent_since > 0.0

    engine._emit_levels(b"\x00" * 160)  # ulaw 0x00 decodes to full scale
    assert engine._input_silent_since == 0.0

    engine._emit_levels(b"\xff" * 160)  # silence again while keyed
    assert engine._input_silent_since > 0.0
    engine.is_ptt_active = lambda: False  # type: ignore[method-assign]
    engine._emit_levels(b"\xff" * 160)  # listening again clears the timer
    assert engine._input_silent_since == 0.0
