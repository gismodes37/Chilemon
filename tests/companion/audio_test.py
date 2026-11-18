"""
Tests for companion/audio.py — AudioEngine level computation and ulaw conversion.

Run with: python -m pytest tests/companion/test_audio.py -v
"""

from __future__ import annotations

import math
import struct

from companion.audio import AudioEngine, lin2ulaw, ulaw2lin


def _reference_g711_encode(sample: int) -> int:
    """CPython 3.12 audioop.c st_14linear2ulaw, with the 16->14 bit shift."""
    pcm14 = sample >> 2
    magnitude = abs(pcm14)
    mask = 0x7F if pcm14 < 0 else 0xFF
    magnitude = min(magnitude, 32635) + 33
    segment_ends = [0x3F, 0x7F, 0xFF, 0x1FF, 0x3FF, 0x7FF, 0xFFF, 0x1FFF]
    segment = next(
        (i for i, end in enumerate(segment_ends) if magnitude <= end), 8
    )
    if segment >= 8:
        return 0x7F ^ mask
    mantissa = (magnitude >> (segment + 1)) & 0x0F
    return ((segment << 4) | mantissa) ^ mask


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
    # G.711 mu-law decodes to +/-32124 in 16-bit PCM, normalized ~0.98.
    assert rms > 0.9
    assert len(spectrum) == 4


def test_lin2ulaw_matches_g711_standard() -> None:
    """Encoding int16 must use the 14-bit G.711 scale, not the raw int16 scale."""
    for sample in (100, 1000, 4000, 8000, 16000, 32000, -1000, -32000):
        encoded = lin2ulaw(struct.pack("<h", sample))[0]
        assert encoded == _reference_g711_encode(sample), (
            f"sample {sample}: got 0x{encoded:02X}, "
            f"expected 0x{_reference_g711_encode(sample):02X}"
        )


def test_lin2ulaw_does_not_clip_at_quarter_scale() -> None:
    """An 8000 int16 sample is ~25% of full scale and must not saturate."""
    quarter = lin2ulaw(struct.pack("<h", 8000))[0]
    half = lin2ulaw(struct.pack("<h", 16000))[0]
    full = lin2ulaw(struct.pack("<h", 32000))[0]
    assert quarter != half != full


def test_ulaw2lin_uses_full_int16_scale() -> None:
    """Standard mu-law bytes must decode to +/-32124, not +/-8031."""
    positive = struct.unpack("<h", ulaw2lin(bytes([0x80])))[0]
    negative = struct.unpack("<h", ulaw2lin(bytes([0x00])))[0]
    assert positive == 32124
    assert negative == -32124


def test_ulaw_roundtrip_preserves_amplitude() -> None:
    """Encoding then decoding a 16-bit sample must keep its scale."""
    for sample in (100, 1000, 4000, 8000, 16000, 32000, -1000, -16000):
        decoded = struct.unpack("<h", ulaw2lin(lin2ulaw(struct.pack("<h", sample))))[0]
        assert abs(decoded - sample) <= 0.05 * abs(sample) + 4, (
            f"sample {sample} decoded as {decoded}"
        )


def test_compute_levels_spectrum_sum() -> None:
    """Spectrum bins should all be in valid range."""
    ulaw_data = bytes([0x7F, 0x00, 0xFF, 0x80] * 40)
    rms, spectrum = AudioEngine._compute_levels(ulaw_data)
    assert len(spectrum) == 4
    for bin_val in spectrum:
        assert 0.0 <= bin_val <= 1.0
    assert 0.0 <= rms <= 1.0
