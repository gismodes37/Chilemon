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
                             → rms_agc()  [RMS-based AGC with silence gate]
                             → s16_to_float32()  [native 8 kHz, no upsampling]
                             → volume gain (RX_VOLUME = 3.0)
                             → JSON hex → WS peers

AGC parameters (fix-audio-rx):
  - Target level: -12 dBFS RMS per frame after gain.
  - Boost frames below -18 dBFS up to target using RMS ratio.
  - Gate (silence) frames below -40 dBFS — discard entirely.
  - Per-frame AGC — no attack/release state across frames.

8 kHz native output decision:
  - The server no longer upsamples 8→16 kHz via linear interpolation.
  - Float32 output stays at 8 kHz; the WS message includes ``"rate": 8000``.
  - The browser's AudioContext.createBuffer(1, N, 8000) handles sample rate
    conversion to the output device natively, eliminating interpolation
    artifacts and reducing server-side CPU.

Uses ``audioop-lts`` (backport of stdlib ``audioop`` removed in Python 3.13).
aiortc handles OPUS encode/decode natively — this module only handles
the PCM↔ulaw, AGC, and resampling layers.
"""

from __future__ import annotations

try:
    import audioop
except ImportError:
    import audioop_lts as audioop  # type: ignore[no-redef]
import math
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

# RX volume boost applied before sending to WebSocket peers.
# 3.0 = 3× amplification.  Increased from 1.5 in fix-audio-rx.
# Applied AFTER RMS AGC (rms_agc) in rx_process().
RX_VOLUME: float = 3.0

# AGC (Automatic Gain Control) — per-frame RMS-based, no inter-frame state.
# Target level: frames below AGC_BOOST_THRESHOLD_DBFS are boosted toward
# AGC_TARGET_DBFS.  Frames below AGC_GATE_DBFS are discarded as silence.
AGC_TARGET_DBFS: float = -12.0       # target RMS level after AGC boost
AGC_BOOST_THRESHOLD_DBFS: float = -18.0  # frames quieter than this get boosted
AGC_GATE_DBFS: float = -40.0          # silence gate — discard frames below this
AGC_MAX_BOOST: float = 10.0           # cap at +20 dB to prevent extreme clipping


def resample_16k_to_8k(pcm_16k: bytes) -> bytes:
    """Downsample PCM s16le from 16 kHz to 8 kHz via decimation.

    Takes every other sample — simple, deterministic, artifact-free.
    For 16→8 kHz this is safe because the input has already been
    bandlimited to 4 kHz by the ulaw codec.
    """
    samples = len(pcm_16k) // 2
    data = struct.unpack(f"<{samples}h", pcm_16k)
    decimated = data[0::2]
    return struct.pack(f"<{len(decimated)}h", *decimated)


def resample_8k_to_16k(pcm_8k: bytes) -> bytes:
    """Upsample PCM s16le from 8 kHz to 16 kHz via linear interpolation.

    Produces exactly 2x the input sample count.  Each pair of original
    samples generates two output samples: the first original and the
    interpolated midpoint.

    Uses ``int(x / 2)`` instead of ``x // 2`` because floor division
    rounds toward -inf for negatives, introducing DC offset distortion.
    ``int()`` truncates toward zero, which is correct for audio midpoints.
    """
    samples = len(pcm_8k) // 2
    data = struct.unpack(f"<{samples}h", pcm_8k)

    result: list[int] = []
    for i in range(samples - 1):
        a = data[i]
        b = data[i + 1]
        result.append(a)
        # Linear interpolate midpoint — int() truncates toward zero,
        # which is the correct rounding for signed audio interpolation.
        result.append(int((a + b) / 2))

    # Duplicate last sample
    if samples > 0:
        result.append(data[-1])

    return struct.pack(f"<{len(result)}h", *result)


# ---------------------------------------------------------------------------
# RMS-based AGC (Automatic Gain Control)
# ---------------------------------------------------------------------------

def rms_agc(pcm_s16le: bytes) -> bytes:
    """Apply per-frame RMS-based AGC and silence gate on PCM s16le.

    Measures the RMS power of the frame.  Frames below ``AGC_GATE_DBFS``
    are replaced with silence.  Frames below ``AGC_BOOST_THRESHOLD_DBFS``
    are boosted toward ``AGC_TARGET_DBFS`` using a proportional RMS ratio,
    capped at ``AGC_MAX_BOOST`` to prevent extreme clipping.

    Parameters
    ----------
    pcm_s16le : bytes
        Signed 16-bit linear PCM, little-endian, mono, 8 kHz.

    Returns
    -------
    bytes
        Gain-adjusted PCM s16le (same byte count as input), or zero-filled
        if the frame was gated as silence.
    """
    samples = len(pcm_s16le) // 2
    data = struct.unpack(f"<{samples}h", pcm_s16le)

    # Compute RMS in signed int16 domain
    sum_sq = 0
    for s in data:
        sum_sq += s * s
    rms = math.sqrt(sum_sq / samples)

    # Convert to dBFS (full scale for s16 is 32768)
    if rms > 0:
        rms_db = 20.0 * math.log10(rms / 32768.0)
    else:
        rms_db = -100.0  # effectively silent

    # Silence gate — discard frames below gate threshold
    if rms_db < AGC_GATE_DBFS:
        return struct.pack(f"<{samples}h", [0] * samples)

    # Apply AGC boost if frame is below boost threshold
    if rms_db < AGC_BOOST_THRESHOLD_DBFS:
        target_rms = 32768.0 * (10.0 ** (AGC_TARGET_DBFS / 20.0))
        gain = target_rms / max(rms, 1.0)
        gain = min(gain, AGC_MAX_BOOST)  # cap boost

        result = [max(-32768, min(32767, int(s * gain))) for s in data]
        return struct.pack(f"<{samples}h", *result)

    return pcm_s16le  # no change if already above boost threshold


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
    """Full receive pipeline: ulaw 8 kHz → float32 8 kHz + AGC + volume gain.

    Pipeline order:
      1. ulaw → PCM s16le
      2. RMS AGC (boost quiet frames, gate silence)
      3. s16 → float32 (native 8 kHz — no upsampling)
      4. RX_VOLUME gain with hard clip to [-1.0, 1.0]

    The output is float32 at 8 kHz.  The WebSocket message includes
    ``"rate": 8000`` so the browser's AudioContext handles 8→device
    sample rate conversion natively.

    Parameters
    ----------
    ulaw_data : bytes
        8-bit ulaw audio at 8 kHz (from IAX2 mini voice frame).

    Returns
    -------
    bytes
        Float32 PCM audio at 8 kHz, AGC+gain-adjusted.
    """
    s16_8k = ulaw_to_pcm(ulaw_data)

    # Per-frame RMS AGC — boost quiet frames, gate silence
    s16_8k = rms_agc(s16_8k)

    # Native 8 kHz — skip 8→16 kHz resample.
    # Browser AudioContext handles sample rate conversion natively.
    f32 = s16_to_float32(s16_8k)

    # Apply volume gain (clamped to [-1.0, 1.0] to prevent clipping)
    if RX_VOLUME != 1.0:
        samples = len(f32) // 4
        floats = struct.unpack(f"<{samples}f", f32)
        boosted = [max(-1.0, min(1.0, s * RX_VOLUME)) for s in floats]
        return struct.pack(f"<{samples}f", *boosted)

    return f32
