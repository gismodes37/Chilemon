"""
Audio Transcoding Pipeline — OPUS ↔ PCM ↔ ulaw / 16 kHz ↔ 8 kHz.

WebRTC delivers OPUS at 16 kHz. Asterisk IAX2 trunk expects ulaw at 8 kHz.
This module provides the conversion chain in both directions:

  TX: WebRTC OPUS → aiortc decodes to PCM f32 16 kHz
                  → float32_to_s16()
                  → resample_16k_to_8k()
                  → pcm_to_ulaw()
                  → IAX2 mini voice frame

  RX: IAX2 mini voice frame → ulaw_to_pcm()
                             → resample_8k_to_16k()
                             → s16_to_float32()
                             → aiortc encodes to OPUS → WebRTC track

Uses Python stdlib ``audioop`` (available on Debian 12 / Python 3.11).
aiortc handles OPUS encode/decode natively — this module only handles
the PCM↔ulaw and resampling layers.
"""

from __future__ import annotations

import audioop
import struct
import logging
from typing import Tuple

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

# Sample rates
RATE_8K = 8000
RATE_16K = 16000

# ulaw frames are 8 kHz, 8-bit per sample
# PCM s16le frames are 8 or 16 kHz, 16-bit per sample
ULAW_FRAME_MS = 20  # standard Asterisk frame duration
ULAW_FRAME_8K_SAMPLES = RATE_8K * ULAW_FRAME_MS // 1000  # 160 samples
ULAW_FRAME_8K_BYTES = ULAW_FRAME_8K_SAMPLES  # 1 byte per ulaw sample
PCM_8K_FRAME_BYTES = ULAW_FRAME_8K_SAMPLES * 2  # 320 bytes (s16le)


# ---------------------------------------------------------------------------
# ulaw ↔ PCM s16le (via stdlib audioop)
# ---------------------------------------------------------------------------

def pcm_to_ulaw(pcm_s16le: bytes) -> bytes:
    """Encode PCM s16le audio to 8-bit ulaw.

    Parameters
    ----------
    pcm_s16le : bytes
        Signed 16-bit linear PCM, little-endian, mono, 8 kHz.

    Returns
    -------
    bytes
        8-bit ulaw encoded audio (same number of samples).
    """
    return audioop.lin2ulaw(pcm_s16le, 2)


def ulaw_to_pcm(ulaw_data: bytes) -> bytes:
    """Decode 8-bit ulaw audio to PCM s16le.

    Parameters
    ----------
    ulaw_data : bytes
        8-bit ulaw encoded audio (mono, 8 kHz).

    Returns
    -------
    bytes
        Signed 16-bit linear PCM, little-endian (s16le), 8 kHz.
    """
    return audioop.ulaw2lin(ulaw_data, 2)


# ---------------------------------------------------------------------------
# PCM s16le ↔ float32 (aiortc interface)
# ---------------------------------------------------------------------------

def s16_to_float32(pcm_s16le: bytes) -> bytes:
    """Convert PCM s16le to 32-bit float (-1.0 to 1.0) for aiortc.

    aiortc's AudioFrame uses ``data`` as a float32 array (planar, mono).
    """
    samples = len(pcm_s16le) // 2
    fmt = f"<{samples}h"  # little-endian signed short
    ints = struct.unpack(fmt, pcm_s16le)
    floats = [max(-1.0, min(1.0, s / 32768.0)) for s in ints]
    packed = struct.pack(f"<{samples}f", *floats)
    return packed


def float32_to_s16(float32_data: bytes) -> bytes:
    """Convert 32-bit float audio (-1.0 to 1.0) back to PCM s16le.

    Parameters
    ----------
    float32_data : bytes
        Packed float32 mono audio.

    Returns
    -------
    bytes
        PCM s16le mono audio.
    """
    samples = len(float32_data) // 4
    fmt = f"<{samples}f"
    floats = struct.unpack(fmt, float32_data)
    ints = [max(-32768, min(32767, int(s * 32767.0))) for s in floats]
    return struct.pack(f"<{samples}h", *ints)


# ---------------------------------------------------------------------------
# Resampling 16 kHz ↔ 8 kHz (linear interpolation)
# ---------------------------------------------------------------------------

def resample_16k_to_8k(pcm_16k: bytes) -> bytes:
    """Downsample PCM s16le from 16 kHz to 8 kHz.

    Uses simple linear interpolation (every other sample).
    For production, consider scipy.signal.resample if artifacts appear.

    Parameters
    ----------
    pcm_16k : bytes
        PCM s16le audio at 16 kHz.

    Returns
    -------
    bytes
        PCM s16le audio at 8 kHz (half the length).
    """
    samples = len(pcm_16k) // 2
    fmt = f"<{samples}h"
    data = struct.unpack(fmt, pcm_16k)

    # Take every other sample (simple decimation)
    decimated = data[0::2]

    return struct.pack(f"<{len(decimated)}h", *decimated)


def resample_8k_to_16k(pcm_8k: bytes) -> bytes:
    """Upsample PCM s16le from 8 kHz to 16 kHz.

    Uses linear interpolation between adjacent samples.

    Parameters
    ----------
    pcm_8k : bytes
        PCM s16le audio at 8 kHz.

    Returns
    -------
    bytes
        PCM s16le audio at 16 kHz (twice the length).
    """
    samples = len(pcm_8k) // 2
    fmt = f"<{samples}h"
    data = struct.unpack(fmt, pcm_8k)

    result = []
    for i in range(samples - 1):
        a = data[i]
        b = data[i + 1]
        result.append(a)
        # Linear interpolate midpoint
        result.append((a + b) // 2)

    # Handle last sample (duplicate)
    if samples > 0:
        result.append(data[-1])

    return struct.pack(f"<{len(result)}h", *result)


# ---------------------------------------------------------------------------
# Complete TX / RX pipeline helpers
# ---------------------------------------------------------------------------

def tx_process(pcm_16k_f32: bytes) -> bytes:
    """Full transmit pipeline: float32 16 kHz → ulaw 8 kHz.

    Parameters
    ----------
    pcm_16k_f32 : bytes
        Float32 PCM audio at 16 kHz (from aiortc decoded OPUS).

    Returns
    -------
    bytes
        8-bit ulaw audio at 8 kHz, ready for IAX2 mini voice frame.
    """
    s16 = float32_to_s16(pcm_16k_f32)
    s16_8k = resample_16k_to_8k(s16)
    return pcm_to_ulaw(s16_8k)


def rx_process(ulaw_data: bytes) -> bytes:
    """Full receive pipeline: ulaw 8 kHz → float32 16 kHz.

    Parameters
    ----------
    ulaw_data : bytes
        8-bit ulaw audio at 8 kHz (from IAX2 mini voice frame).

    Returns
    -------
    bytes
        Float32 PCM audio at 16 kHz, ready for aiortc OPUS encode.
    """
    s16_8k = ulaw_to_pcm(ulaw_data)
    s16_16k = resample_8k_to_16k(s16_8k)
    return s16_to_float32(s16_16k)
