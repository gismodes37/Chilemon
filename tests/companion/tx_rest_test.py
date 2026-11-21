"""
Tests for idle TX suppression and wake-on-voice in the companion.

The microphone must stop sending IAX2 frames after a period without voice,
then resume without clipping the first syllable when the operator speaks or
presses PTT. PTT keying itself stays an explicit operator action.

Run with: python -m pytest tests/companion/tx_rest_test.py -v
"""

from __future__ import annotations

import time

from companion.audio import (
    SILENT_INPUT_S,
    TX_PREROLL_FRAMES,
    TX_REST_S,
    TX_WAKE_FRAMES,
    TX_WAKE_RMS,
    AudioEngine,
)


def _engine() -> tuple[AudioEngine, list[bytes]]:
    engine = AudioEngine()
    engine._running = True
    sent: list[bytes] = []
    engine.on_tx_audio = sent.append
    return engine, sent


def test_tx_is_active_on_startup() -> None:
    """A fresh engine must transmit immediately — no silent startup window."""
    engine, sent = _engine()
    assert engine.tx_state == "active"
    engine._handle_tx(b"a", 0.02)
    assert sent == [b"a"]


def test_idle_silence_puts_tx_to_rest() -> None:
    """No voice and no PTT for the idle timeout stops TX frames."""
    engine, sent = _engine()
    engine._tx_last_voice = time.monotonic() - TX_REST_S - 1
    engine._handle_tx(b"s", 0.0)

    assert engine.tx_state == "resting"
    assert sent == []


def test_voice_activity_keeps_tx_active() -> None:
    """A speaking operator must never be put to rest."""
    engine, sent = _engine()
    for i in range(TX_WAKE_FRAMES + 2):
        engine._handle_tx(bytes([i]), TX_WAKE_RMS + 0.01)

    assert engine.tx_state == "active"
    assert len(sent) == TX_WAKE_FRAMES + 2


def test_sustained_voice_wakes_resting_tx_with_preroll() -> None:
    """Wake flushes the pre-roll before live audio so no syllable is clipped."""
    engine, sent = _engine()
    engine._tx_state = "resting"
    engine._handle_tx(b"0", 0.0)
    engine._handle_tx(b"1", 0.0)
    assert engine.tx_state == "resting"
    assert sent == []

    for i in range(TX_WAKE_FRAMES):
        engine._handle_tx(bytes([ord("a") + i]), TX_WAKE_RMS + 0.01)

    assert engine.tx_state == "active"
    assert sent == [b"0", b"1", b"a", b"b", b"c"]


def test_single_frame_of_noise_does_not_wake_tx() -> None:
    """A transient bump must not wake the microphone."""
    engine, sent = _engine()
    engine._tx_state = "resting"
    engine._handle_tx(b"x", TX_WAKE_RMS + 0.01)

    assert engine.tx_state == "resting"
    assert sent == []


def test_ptt_wakes_resting_tx_immediately() -> None:
    """An explicit PTT press is an immediate wake signal."""
    engine, sent = _engine()
    engine._tx_state = "resting"
    engine._handle_tx(b"0", 0.0)
    engine._handle_tx(b"1", 0.0)
    engine.is_ptt_active = lambda: True  # type: ignore[method-assign]

    engine._handle_tx(b"p", 0.0)

    assert engine.tx_state == "active"
    assert sent == [b"0", b"1", b"p"]


def test_preroll_buffer_is_bounded() -> None:
    """The pre-roll must never grow without limit while resting."""
    engine, sent = _engine()
    engine._tx_state = "resting"
    for i in range(TX_PREROLL_FRAMES + 5):
        engine._handle_tx(bytes([i]), 0.0)

    assert len(engine._tx_preroll) == TX_PREROLL_FRAMES
    assert sent == []


def test_resting_silence_does_not_trigger_device_restart() -> None:
    """Silence is intentional while resting; health must not restart devices."""
    engine, sent = _engine()
    engine._input_stream = object()  # type: ignore[assignment]
    engine._output_stream = object()  # type: ignore[assignment]
    engine._tx_state = "resting"
    now = time.monotonic()
    engine._input_silent_since = now - SILENT_INPUT_S - 1
    engine._last_input_callback = now
    engine._last_output_callback = now
    restarts: list[str] = []
    engine.stop = lambda: restarts.append("stop")  # type: ignore[method-assign]
    engine.start = lambda: restarts.append("start")  # type: ignore[method-assign]

    assert engine.check_health() is False
    assert restarts == []


def test_active_silence_still_triggers_device_restart() -> None:
    """The original dead-device detection must keep working while active."""
    engine, sent = _engine()
    engine._input_stream = object()  # type: ignore[assignment]
    engine._output_stream = object()  # type: ignore[assignment]
    now = time.monotonic()
    engine._input_silent_since = now - SILENT_INPUT_S - 1
    engine._last_input_callback = now
    engine._last_output_callback = now
    restarts: list[str] = []
    engine.stop = lambda: restarts.append("stop")  # type: ignore[method-assign]
    engine.start = lambda: restarts.append("start")  # type: ignore[method-assign]

    assert engine.check_health() is True
    assert restarts == ["stop", "start"]


def test_tx_state_is_exposed_in_status() -> None:
    """The dashboard needs the state to show a friendly message."""
    engine, sent = _engine()
    engine._tx_state = "resting"
    assert engine.get_audio_stats()["tx_state"] == "resting"
