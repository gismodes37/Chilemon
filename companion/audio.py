"""
companion/audio.py -- Native audio capture and playback via sounddevice.

Handles mic input (TX) and speaker output (RX) using PortAudio via sounddevice.
Converts between ulaw (IAX2 codec) and PCM (native audio format).
Extracts RMS and FFT metadata for browser visualizer.

NOTE: audioop was removed in Python 3.13. We implement ulaw encode/decode
manually using the standard G.711 μ-law companding algorithm.
"""

from __future__ import annotations

import logging
import math
import struct
import threading
from typing import Callable, Optional

import numpy as np

try:
    import sounddevice as sd
except ImportError:
    sd = None  # type: ignore[assignment]

from companion.jitter_buffer import JitterBuffer, AudioFrame

logger = logging.getLogger("companion.audio")

# Audio constants
RATE = 8000  # ulaw is 8 kHz
FRAMES_PER_BUFFER = 160  # 20 ms at 8000 Hz
DTYPE = "int16"  # 16-bit PCM
ULAW_INPUT_SCALE = 1 / 32768.0  # s16 -> float for RMS

# ---------------------------------------------------------------------------
# G.711 μ-law encode/decode (replaces deprecated audioop)
# Based on ITU-T G.711 standard for right-justified 14-bit PCM.
# Decode: y = (-1)^s * [(33 + 2*m) * 2^e - 33]
# Encode: biased = abs(sample) + 33, find segment, extract mantissa.
# ---------------------------------------------------------------------------

_BIAS = 33  # Standard G.711 µ-law bias for right-justified 14-bit PCM

# Decode table: 256 entries for all possible µ-law byte values
_ULAW_DECODE_TABLE: list[int] = []
for _ulaw_byte in range(256):
    _val = ~_ulaw_byte & 0xFF  # invert (µ-law ones-complement)
    _sign = (_val >> 7) & 1
    _exponent = (_val >> 4) & 7
    _mantissa = _val & 0x0F
    _decoded = ((_mantissa * 2 + _BIAS) << _exponent) - _BIAS
    if _sign:
        _decoded = -_decoded
    _ULAW_DECODE_TABLE.append(_decoded)


def ulaw2lin(ulaw_data: bytes) -> bytes:
    """Decode μ-law bytes to 16-bit linear PCM (little-endian).

    Replaces audioop.ulaw2lin(data, 2).
    Standard formula: y = (-1)^s * [(33 + 2*m) * 2^e - 33]
    """
    pcm = bytearray(len(ulaw_data) * 2)
    for i, ulaw_byte in enumerate(ulaw_data):
        pcm[i * 2:i * 2 + 2] = struct.pack("<h", _ULAW_DECODE_TABLE[ulaw_byte])
    return bytes(pcm)


def lin2ulaw(pcm_data: bytes) -> bytes:
    """Encode 16-bit linear PCM (little-endian) to μ-law bytes.

    Replaces audioop.lin2ulaw(data, 2).
    Uses bit_length for segment detection — correct for all 14-bit values.
    """
    ulaw = bytearray(len(pcm_data) // 2)
    for i in range(0, len(pcm_data), 2):
        sample = struct.unpack("<h", pcm_data[i:i + 2])[0]

        # Get sign and absolute value
        if sample >= 0:
            sign = 0x00
            biased = sample + _BIAS
        else:
            sign = 0x80
            biased = -sample + _BIAS

        # Clip to max biased value for segment 7 (4096-8191)
        if biased > 8191:
            biased = 8191

        # Segment = bit_length(biased) - 6, clamped to 0..7
        seg = max(0, min(7, biased.bit_length() - 6))

        # Mantissa = 4 bits just below the leading 1
        mant = (biased >> (seg + 1)) & 0x0F

        # Combine: sign | segment | mantissa, then invert (µ-law ones-complement)
        ulaw_val = sign | (seg << 4) | mant
        ulaw[i // 2] = ~ulaw_val & 0xFF

    return bytes(ulaw)


# ---------------------------------------------------------------------------
# Audio Engine
# ---------------------------------------------------------------------------


class AudioEngine:
    """Manages mic capture and speaker playback via sounddevice.

    Provides callbacks for:
    - TX: captured ulaw frames ready to send
    - RX: received ulaw frames to play
    - Levels: periodic RMS/spectrum metadata for visualizer
    """

    def __init__(
        self,
        input_device: str = "",
        output_device: str = "",
    ) -> None:
        self._input_device = input_device
        self._output_device = output_device

        self._running = False
        self._input_stream: Optional[sd.InputStream] = None
        self._output_stream: Optional[sd.OutputStream] = None

        # Thread safety for RX queue
        self._lock = threading.Lock()
        self._rx_queue: list[bytes] = []

        # Jitter buffer for RX
        self._jitter_buffer = JitterBuffer(
            target_delay_ms=60,   # 60ms target delay
            max_delay_ms=200,     # Max 200ms buffer
            min_delay_ms=20,      # Min 20ms
            plc_enabled=True,     # Enable Packet Loss Concealment
            max_plc_frames=3,     # Max 3 consecutive PLC frames
        )
        self._frame_counter: int = 0

        # Callbacks
        self.on_tx_audio: Optional[Callable[[bytes], None]] = None
        self.on_levels: Optional[Callable[[float, list[float]], None]] = None

    # -- Lifecycle --

    def start(self) -> None:
        """Open audio streams and start capture/playback."""
        if sd is None:
            logger.warning("sounddevice not installed — audio disabled")
            self._running = False
            return

        self._running = True

        # Resolve device IDs
        input_id = self._resolve_device(self._input_device, is_input=True)
        output_id = self._resolve_device(self._output_device, is_input=False)

        logger.info(
            "Starting audio: input=%s (%s), output=%s (%s)",
            self._input_device or "default",
            input_id,
            self._output_device or "default",
            output_id,
        )

        # Input stream (mic) — callback pushes TX audio
        try:
            self._input_stream = sd.InputStream(
                samplerate=RATE,
                blocksize=FRAMES_PER_BUFFER,
                device=input_id,
                channels=1,
                dtype=DTYPE,
                callback=self._input_callback,
            )
            self._input_stream.start()
        except Exception as exc:
            logger.warning("Failed to open input stream: %s", exc)
            self._input_stream = None

        # Output stream (speaker) — callback pulls RX audio
        try:
            # Get device info to determine channel count
            device_info = sd.query_devices(output_id)
            output_channels = min(device_info['max_output_channels'], 2)  # Max 2 (stereo)
            
            self._output_stream = sd.OutputStream(
                samplerate=RATE,
                blocksize=FRAMES_PER_BUFFER,
                device=output_id,
                channels=output_channels,
                dtype=DTYPE,
                callback=self._output_callback,
            )
            self._output_stream.start()
            logger.info("Output stream opened: %d channels", output_channels)
        except Exception as exc:
            logger.warning("Failed to open output stream: %s", exc)
            self._output_stream = None

        if self._input_stream is None and self._output_stream is None:
            logger.warning("No audio streams opened — audio is disabled")
            self._running = False

    def stop(self) -> None:
        """Stop and close audio streams."""
        self._running = False

        if self._input_stream is not None:
            try:
                self._input_stream.stop()
                self._input_stream.close()
            except Exception:
                pass
            self._input_stream = None

        if self._output_stream is not None:
            try:
                self._output_stream.stop()
                self._output_stream.close()
            except Exception:
                pass
            self._output_stream = None

        logger.info("Audio stopped")

    @property
    def is_running(self) -> bool:
        """Whether audio streams are active."""
        return self._running

    # -- RX: playback (called from session when audio arrives) --

    def play_ulaw(self, ulaw_payload: bytes) -> None:
        """Queue received ulaw audio for speaker playback.
        
        Now uses jitter buffer for reordering, PLC, and adaptive delay.
        Uses arrival time for jitter estimation (simpler than reconstructing
        IAX2 timestamps from 16-bit mini frame values).
        
        Parameters
        ----------
        ulaw_payload : bytes
            ulaw-encoded audio frame (typically 160 bytes = 20ms)
        """
        if not self._running or self._output_stream is None:
            return
        
        # Create audio frame with arrival time as timestamp
        import time
        self._frame_counter += 1
        arrival_ms = int(time.monotonic() * 1000)
        frame = AudioFrame(
            timestamp_ms=arrival_ms,
            payload=ulaw_payload,
            received_at=time.monotonic(),
            sequence=self._frame_counter,
        )
        
        # Add to jitter buffer
        self._jitter_buffer.push(frame)

    # -- TX: capture (called from input callback) --

    def send_audio(self, ulaw_payload: bytes) -> None:
        """Forward mic audio to IAX2 session (called from input callback).

        This is typically called by the input_callback, not directly.
        """
        if self.on_tx_audio and ulaw_payload:
            self.on_tx_audio(ulaw_payload)

    # -- Audio callbacks (sounddevice) --

    def _input_callback(
        self,
        indata: np.ndarray,
        frames: int,
        time_info: object,
        status: sd.CallbackFlags,
    ) -> None:
        """Called by sounddevice when mic data is available.

        Converts PCM int16 -> ulaw and fires on_tx_audio.
        """
        if status:
            logger.debug("Input callback status: %s", status)

        if not self._running or self.on_tx_audio is None:
            return

        # Convert PCM int16 -> ulaw
        pcm_bytes = indata.tobytes()
        ulaw_payload = lin2ulaw(pcm_bytes)

        # Fire TX callback (hand off to IAX2 session)
        self.on_tx_audio(ulaw_payload)

        # Emit audio levels for visualizer
        self._emit_levels(ulaw_payload)

    def _output_callback(
        self,
        outdata: np.ndarray,
        frames: int,
        time_info: object,
        status: sd.CallbackFlags,
    ) -> None:
        """Called by sounddevice when speaker needs data.
        
        Pulls ulaw from jitter buffer, converts to PCM int16, fills outdata.
        Handles both mono and stereo output devices.
        """
        if status:
            logger.debug("Output callback status: %s", status)

        # Get next frame from jitter buffer
        ulaw_payload: Optional[bytes] = self._jitter_buffer.pop()

        if ulaw_payload is not None and len(ulaw_payload) == frames:
            # Convert ulaw -> PCM int16 (mono)
            pcm_bytes = ulaw2lin(ulaw_payload)
            mono_data = np.frombuffer(pcm_bytes, dtype=np.int16).reshape(-1, 1)
            
            # Duplicate to stereo if output has 2 channels
            if outdata.shape[1] == 2:
                outdata[:, 0] = mono_data[:, 0]  # Left
                outdata[:, 1] = mono_data[:, 0]  # Right
            else:
                outdata[:] = mono_data
        else:
            # No data — silent frame
            outdata.fill(0)

    # -- Level computation --

    def _emit_levels(self, ulaw_payload: bytes) -> None:
        """Compute RMS and spectrum from ulaw payload and fire callback."""
        if self.on_levels is None:
            return

        rms, spectrum = self._compute_levels(ulaw_payload)
        try:
            self.on_levels(rms, spectrum)
        except Exception:
            pass  # Don't crash audio thread on callback errors

    @staticmethod
    def _compute_levels(ulaw_payload: bytes) -> tuple[float, list[float]]:
        """Compute RMS and 4-bin spectrum from ulaw audio data.

        Returns (rms, [bin1, bin2, bin3, bin4]) where each bin is 0..1.
        """
        if not ulaw_payload:
            return 0.0, [0.0, 0.0, 0.0, 0.0]

        # Decode ulaw -> s16 PCM
        try:
            pcm = ulaw2lin(ulaw_payload)
        except Exception:
            return 0.0, [0.0, 0.0, 0.0, 0.0]

        # Compute RMS
        samples = struct.unpack(f"<{len(pcm) // 2}h", pcm)
        if not samples:
            return 0.0, [0.0, 0.0, 0.0, 0.0]

        sum_sq = sum(s * s for s in samples)
        rms = math.sqrt(sum_sq / len(samples)) / 32768.0
        rms = min(rms, 1.0)

        # Simple 4-bin spectrum via fixed decimation
        bin_size = len(samples) // 4
        spectrum: list[float] = []
        for i in range(4):
            if bin_size == 0:
                spectrum.append(0.0)
                continue
            chunk = samples[i * bin_size : (i + 1) * bin_size]
            if chunk:
                energy = sum(abs(s) for s in chunk) / len(chunk)
                spectrum.append(min(energy / 32768.0, 1.0))
            else:
                spectrum.append(0.0)

        return rms, spectrum

    # -- Helpers --

    @staticmethod
    def _resolve_device(name: str, is_input: bool) -> int:
        """Resolve device name to sounddevice device ID (0 = default)."""
        if sd is None or not name:
            return 0

        try:
            device_id = int(name)
            return device_id
        except ValueError:
            pass

        # Search by name substring
        for i, dev in enumerate(sd.query_devices()):
            if name.lower() in dev["name"].lower():
                if is_input and dev["max_input_channels"] > 0:
                    return i
                if not is_input and dev["max_output_channels"] > 0:
                    return i

        logger.warning("Device '%s' not found — using default", name)
        return 0

    def get_jitter_stats(self) -> dict:
        """Return jitter buffer statistics for monitoring."""
        return self._jitter_buffer.get_stats()

    def get_audio_stats(self) -> dict:
        """Return audio engine statistics."""
        return {
            "running": self._running,
            "input_stream": self._input_stream is not None,
            "output_stream": self._output_stream is not None,
            "jitter_buffer": self.get_jitter_stats(),
        }

    def log_jitter_stats(self) -> None:
        """Log jitter buffer statistics for debugging."""
        stats = self.get_jitter_stats()
        logger.info(
            "JitterBuffer stats: received=%d, played=%d, dropped=%d, plc=%d, "
            "buffer_size=%d, target_delay=%dms, jitter=%.1fms",
            stats["frames_received"],
            stats["frames_played"],
            stats["frames_dropped"],
            stats["frames_plc"],
            stats["buffer_size"],
            stats["target_delay_ms"],
            stats["current_jitter_ms"],
        )
