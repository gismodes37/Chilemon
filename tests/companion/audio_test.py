"""
Tests for companion/audio.py — AudioEngine level computation and ulaw conversion.

Run with: python -m pytest tests/companion/test_audio.py -v
"""

from __future__ import annotations

import math
import struct

from companion.audio import AudioEngine


def test_compute_levels_empty() -> None:
    """Empty input should return zero levels."""
    rms, spectrum = AudioEngine._compute_levels(b"")
    assert rms == 0.0
    assert spectrum == [0.0, 0.0, 0.0, 0.0]


def test_compute_levels_silence() -> None:
    """Ulaw silence (0x7F) should produce very low RMS."""
    ulaw_silence = bytes([0x7F] * 160)
    rms, spectrum = AudioEngine._compute_levels(ulaw_silence)
    assert 0.0 <= rms <= 0.1  # silence is near-zero
    assert len(spectrum) == 4
    for bin_val in spectrum:
        assert 0.0 <= bin_val <= 1.0


def test_compute_levels_max_volume() -> None:
    """Maximum ulaw (0x00) should produce high RMS."""
    ulaw_max = bytes([0x00] * 160)
    rms, spectrum = AudioEngine._compute_levels(ulaw_max)
    assert rms > 0.5  # max volume should be clearly audible
    assert len(spectrum) == 4


def test_compute_levels_spectrum_sum() -> None:
    """Spectrum bins should all be in valid range."""
    ulaw_data = bytes([0x7F, 0x00, 0xFF, 0x80] * 40)
    rms, spectrum = AudioEngine._compute_levels(ulaw_data)
    assert len(spectrum) == 4
    for bin_val in spectrum:
        assert 0.0 <= bin_val <= 1.0
    assert 0.0 <= rms <= 1.0
