"""
companion/audio.py -- Native audio capture and playback via pyaudio.

Handles mic input (TX) and speaker output (RX) using PortAudio.
Converts between ulaw (IAX2 codec) and PCM (native audio format).
Extracts RMS and FFT metadata for browser visualizer.
"""

from __future__ import annotations

import audioop
import logging
import math
import struct
import threading
from typing import Callable, Optional

try:
    import pyaudio
except ImportError:
    pyaudio = None  # type: ignore[assignment]

logger = logging.getLogger("companion.audio")

# Audio constants
FORMAT = pyaudio.paInt16 if pyaudio else 8  # 16-bit PCM
CHANNELS = 1
RATE = 8000  # ulaw is 8 kHz
FRAMES_PER_BUFFER = 160  # 20 ms at 8000 Hz
ULAW_INPUT_SCALE = 1 / 32768.0  # s16 → float for RMS


class AudioEngine:
    """Manages mic capture and speaker playback via pyaudio.

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

        self._pa: Optional[pyaudio.PyAudio] = None
        self._input_stream: Optional["pyaudio.Stream"] = None
        self._output_stream: Optional["pyaudio.Stream"] = None
        self._running = False
        self._rx_buffer: list[bytes] = []
        self._lock = threading.Lock()

        # Callbacks
        self.on_tx_audio: Optional[Callable[[bytes], None]] = None
        self.on_levels: Optional[Callable[[float, list[float]], None]] = None

    # -- Lifecycle --

    def start(self) -> None:
        """Initialize PyAudio and open input/output streams."""
        if pyaudio is None:
            logger.error("pyaudio not installed — audio disabled")
            return

        self._pa = pyaudio.PyAudio()
        self._running = True

        # Open input stream (mic)
        try:
            input_dev_idx = self._resolve_device(self._input_device, is_input=True)
            self._input_stream = self._pa.open(
                format=FORMAT,
                channels=CHANNELS,
                rate=RATE,
                input=True,
                input_device_index=input_dev_idx,
                frames_per_buffer=FRAMES_PER_BUFFER,
                stream_callback=self._input_callback,
            )
            logger.info(
                "Audio input opened (device=%s)", input_dev_idx if input_dev_idx is not None else "default"
            )
        except Exception as exc:
            logger.error("Failed to open audio input: %s", exc)

        # Open output stream (speaker)
        try:
            output_dev_idx = self._resolve_device(self._output_device, is_input=False)
            self._output_stream = self._pa.open(
                format=FORMAT,
                channels=CHANNELS,
                rate=RATE,
                output=True,
                output_device_index=output_dev_idx,
                frames_per_buffer=FRAMES_PER_BUFFER,
                stream_callback=self._output_callback,
            )
            logger.info(
                "Audio output opened (device=%s)", output_dev_idx if output_dev_idx is not None else "default"
            )
        except Exception as exc:
            logger.error("Failed to open audio output: %s", exc)

    def stop(self) -> None:
        """Close audio streams and terminate PyAudio."""
        self._running = False

        if self._input_stream is not None:
            try:
                self._input_stream.stop_stream()
                self._input_stream.close()
            except Exception:
                pass
            self._input_stream = None

        if self._output_stream is not None:
            try:
                self._output_stream.stop_stream()
                self._output_stream.close()
            except Exception:
                pass
            self._output_stream = None

        if self._pa is not None:
            self._pa.terminate()
            self._pa = None

        logger.info("Audio engine stopped")

    # -- RX: receive ulaw from IAX2 and play --

    def play_ulaw(self, ulaw_payload: bytes) -> None:
        """Queue received ulaw audio for playback.

        Called from session.py when audio frames arrive from Asterisk.
        Converts ulaw to PCM s16 and writes to a thread-safe buffer
        consumed by the output stream callback.
        """
        if not self._running:
            return
        try:
            # ulaw → PCM s16le
            pcm_s16 = audioop.ulaw2lin(ulaw_payload, 2)
            with self._lock:
                self._rx_buffer.append(pcm_s16)

            # Compute RMS for visualizer
            rms, spectrum = self._compute_levels(ulaw_payload)
            if self.on_levels:
                self.on_levels(rms, spectrum)
        except Exception as exc:
            logger.warning("play_ulaw error: %s", exc)

    # -- TX: capture mic and convert to ulaw --

    def _input_callback(
        self,
        in_data: bytes,
        frame_count: int,
        time_info: dict,
        status_flags: int,
    ) -> tuple[Optional[bytes], int]:
        """Callback from pyaudio input stream — mic data ready.

        Converts PCM s16 to ulaw and fires on_tx_audio callback.
        """
        if not self._running or in_data is None:
            return (None, pyaudio.paAbort if pyaudio else 0)

        try:
            # PCM s16le → ulaw
            ulaw = audioop.lin2ulaw(in_data, 2)

            if self.on_tx_audio:
                self.on_tx_audio(ulaw)
        except Exception as exc:
            logger.warning("Input callback error: %s", exc)

        return (None, pyaudio.paContinue if pyaudio else 0)

    def _output_callback(
        self,
        in_data: bytes,
        frame_count: int,
        time_info: dict,
        status_flags: int,
    ) -> tuple[Optional[bytes], int]:
        """Callback from pyaudio output stream — speaker needs data.

        Reads from the RX buffer. Returns silence if buffer is empty.
        """
        with self._lock:
            if self._rx_buffer:
                data = self._rx_buffer.pop(0)
            else:
                data = b"\x00" * (frame_count * 2)

        return (data, pyaudio.paContinue if pyaudio else 0)

    # -- Level computation (for visualizer) --

    @staticmethod
    def _compute_levels(ulaw_data: bytes) -> tuple[float, list[float]]:
        """Compute RMS and simple frequency bins from ulaw audio.

        Returns (rms: float, spectrum: list of 4 bin levels 0..1).
        Uses fast approximations suitable for real-time visualizer.
        """
        try:
            pcm_s16 = audioop.ulaw2lin(ulaw_data, 2)
            samples = struct.unpack(f"<{len(pcm_s16) // 2}h", pcm_s16)
        except Exception:
            return (0.0, [0.0, 0.0, 0.0, 0.0])

        n = len(samples)
        if n == 0:
            return (0.0, [0.0, 0.0, 0.0, 0.0])

        # RMS (normalized 0..1)
        sum_sq = sum(s * s for s in samples)
        rms = math.sqrt(sum_sq / n) * ULAW_INPUT_SCALE
        rms = min(rms, 1.0)

        # Simple 4-bin spectrum: compute average magnitude in
        # 4 frequency bands using a rough FFT-free approach
        # (energy in each quarter of the sample window)
        quarter = n // 4
        bins = [0.0, 0.0, 0.0, 0.0]
        for i in range(4):
            start = i * quarter
            end = start + quarter if i < 3 else n
            if end > start:
                energy = sum(abs(samples[j]) for j in range(start, end))
                bins[i] = min(energy / (end - start) * ULAW_INPUT_SCALE * 2, 1.0)

        return (rms, bins)

    # -- Device resolution --

    def _resolve_device(self, name: str, is_input: bool) -> Optional[int]:
        """Resolve a device name (or substring) to a PyAudio device index.

        Returns None for default device.
        """
        if not name or self._pa is None:
            return None

        for i in range(self._pa.get_device_count()):
            try:
                dev_info = self._pa.get_device_info_by_index(i)
                dev_name: str = dev_info.get("name", "") or ""
                max_inputs: int = dev_info.get("maxInputChannels", 0) or 0
                max_outputs: int = dev_info.get("maxOutputChannels", 0) or 0

                if is_input and max_inputs > 0 and name.lower() in dev_name.lower():
                    return i
                if not is_input and max_outputs > 0 and name.lower() in dev_name.lower():
                    return i
            except Exception:
                continue

        logger.warning("Device '%s' not found — using default", name)
        return None
