"""
companion/audio.py -- Native audio capture and playback via sounddevice.

Handles mic input (TX) and speaker output (RX) using PortAudio via sounddevice.
Converts between ulaw (IAX2 codec) and PCM (native audio format).
Extracts RMS and FFT metadata for browser visualizer.

NOTE: audioop was removed in Python 3.13. We implement ulaw encode/decode
manually using the standard G.711 μ-law companding algorithm.
"""

from __future__ import annotations

import inspect
import logging
import math
import struct
import threading
import time
from collections import deque
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

# PortAudio can silently stop delivering callbacks on Windows when a Bluetooth
# device sleeps. The stream objects remain non-None, so is_running cannot
# detect it. A stalled callback also stops the other one: both share the same
# callback thread, which is why mic levels and playback die together.
AUDIO_CALLBACK_STALL_S = 3.0
# A device can stay open and keep firing callbacks while delivering silence
# (Windows switches the default input to a sleeping Bluetooth headset). Treat
# sustained near-silence as a dead input and re-resolve the available devices.
SILENT_INPUT_S = 15.0
SILENT_INPUT_RMS = 0.0005
RESTART_COOLDOWN_S = 30.0

# Idle TX suppression. The mic stream stays open so voice can be detected,
# but IAX2 frames stop flowing after a period without voice. Keeping the
# stream open avoids a device restart and lets a short pre-roll cover the
# first syllable when the operator starts speaking.
TX_REST_S = 120.0
TX_WAKE_RMS = 0.01
TX_SLEEP_RMS = 0.005
TX_WAKE_FRAMES = 3
TX_PREROLL_FRAMES = 10

# Visualizer spectrum (S7). Bands are log-spaced across the audible range and
# mapped from a fixed dB full-scale window, so weak input stays visible (S2)
# without touching the raw RMS that still gates TX (S6).
SPECTRUM_BANDS = 16
SPECTRUM_DB_MIN = -82.0
SPECTRUM_DB_MAX = -24.0

# ---------------------------------------------------------------------------
# G.711 μ-law encode/decode (replaces deprecated audioop)
# Based on ITU-T G.711 standard for right-justified 14-bit PCM.
# Decode: y = (-1)^s * [(33 + 2*m) * 2^e - 33]
# Encode: biased = abs(sample) + 33, find segment, extract mantissa.
# ---------------------------------------------------------------------------

_BIAS = 33  # Standard G.711 µ-law bias for right-justified 14-bit PCM
_SEGMENT_ENDS = (0x3F, 0x7F, 0xFF, 0x1FF, 0x3FF, 0x7FF, 0xFFF, 0x1FFF)

# Decode table: 256 entries for all possible µ-law byte values.
# G.711 produces 14-bit right-justified PCM; 16-bit linear audio requires a
# two-bit left shift (CPython audioop.c _st_ulaw2linear16: +/-32124 at full
# scale). Emitting the 14-bit value directly made playback 4x too quiet.
_ULAW_DECODE_TABLE: list[int] = []
for _ulaw_byte in range(256):
    _val = ~_ulaw_byte & 0xFF  # invert (µ-law ones-complement)
    _sign = (_val >> 7) & 1
    _exponent = (_val >> 4) & 7
    _mantissa = _val & 0x0F
    _decoded = ((_mantissa * 2 + _BIAS) << _exponent) - _BIAS
    if _sign:
        _decoded = -_decoded
    _ULAW_DECODE_TABLE.append(_decoded << 2)


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
    """Encode 16-bit linear PCM (little-endian) to standard G.711 μ-law.

    CPython audioop.c converts a 16-bit sample with ``val >> 18`` (the
    GETSAMPLE32 scale factor is 1<<16) before applying the 14-bit encoder.
    Skipping that shift made every sample ~4x too loud on the wire and
    saturated at 25% of full scale, which is audible as clipping/distortion.
    """
    ulaw = bytearray(len(pcm_data) // 2)
    for i in range(0, len(pcm_data), 2):
        sample = struct.unpack("<h", pcm_data[i:i + 2])[0]

        pcm14 = sample >> 2  # 16-bit int16 -> right-justified 14-bit G.711
        magnitude = abs(pcm14)
        mask = 0x7F if pcm14 < 0 else 0xFF

        magnitude = min(magnitude, 32635) + _BIAS
        seg = next(
            (idx for idx, end in enumerate(_SEGMENT_ENDS) if magnitude <= end),
            len(_SEGMENT_ENDS),
        )
        if seg >= len(_SEGMENT_ENDS):
            ulaw[i // 2] = 0x7F ^ mask
            continue

        mantissa = (magnitude >> (seg + 1)) & 0x0F
        ulaw[i // 2] = ((seg << 4) | mantissa) ^ mask

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
        tx_rest_s: float = TX_REST_S,
        tx_wake_rms: float = TX_WAKE_RMS,
        tx_sleep_rms: float = TX_SLEEP_RMS,
        tx_wake_frames: int = TX_WAKE_FRAMES,
        tx_preroll_frames: int = TX_PREROLL_FRAMES,
    ) -> None:
        self._input_device = input_device
        self._output_device = output_device

        self._tx_rest_s = tx_rest_s
        self._tx_wake_rms = tx_wake_rms
        self._tx_sleep_rms = tx_sleep_rms
        self._tx_wake_frames = tx_wake_frames
        self._tx_preroll_frames = tx_preroll_frames

        self._running = False
        self._input_stream: Optional[sd.InputStream] = None
        self._output_stream: Optional[sd.OutputStream] = None
        self._last_input_callback = 0.0
        self._last_output_callback = 0.0

        # Device identity captured when the streams were opened. Compared
        # against a fresh resolution so a changed OS default is picked up.
        self._input_device_id = -1
        self._output_device_id = -1
        self._input_silent_since = 0.0
        self._last_restart = 0.0

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

        # Idle TX state machine. All mutation happens on the PortAudio input
        # callback thread; is_ptt_active and on_tx_state are the only bridges
        # to the asyncio side and follow the same pattern used for levels.
        self._tx_state = "active"
        self._tx_voice_frames = 0
        self._tx_last_voice = time.monotonic()
        self._tx_preroll: deque[bytes] = deque(maxlen=max(1, tx_preroll_frames))
        self.is_ptt_active: Optional[Callable[[], bool]] = None

        # Callbacks
        self.on_tx_audio: Optional[Callable[[bytes], None]] = None
        # Receives (rms, spectrum) or (rms, spectrum, direction). The extra
        # argument is optional so pre-existing two-argument hooks keep working.
        self.on_levels: Optional[Callable[..., None]] = None
        self.on_tx_state: Optional[Callable[[str], None]] = None

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
        self._input_device_id = input_id
        self._output_device_id = output_id

        logger.info(
            "Starting audio: input=%s -> %s, output=%s -> %s",
            self._input_device or "system default",
            self._describe_device(input_id),
            self._output_device or "system default",
            self._describe_device(output_id),
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

        # Seed liveness timestamps so the health check does not flag a fresh
        # start as stalled before the first callback fires.
        now = time.monotonic()
        self._last_input_callback = now
        self._last_output_callback = now
        self._input_silent_since = 0.0
        self._last_restart = now
        self._reset_tx_state()

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

    def reset(self) -> None:
        """Discard buffered RX audio so nothing plays after a hangup."""
        self._jitter_buffer.reset()

    def _reset_tx_state(self) -> None:
        """Return TX to active after a start or device restart."""
        self._tx_state = "active"
        self._tx_voice_frames = 0
        self._tx_last_voice = time.monotonic()
        self._tx_preroll.clear()

    @property
    def tx_state(self) -> str:
        """Current TX state: 'active' or 'resting'."""
        return self._tx_state

    def _notify_tx_state(self) -> None:
        if self.on_tx_state is None:
            return
        try:
            self.on_tx_state(self._tx_state)
        except Exception:
            pass  # Never let a UI callback break the audio thread.

    def _handle_tx(self, ulaw_payload: bytes, rms: float) -> None:
        """Gate mic frames on voice activity and explicit PTT.

        Runs on the PortAudio input callback thread. While resting, frames
        are only kept in a short pre-roll so the first syllable is not lost
        when the operator starts speaking.
        """
        if not self._running or not ulaw_payload:
            return

        now = time.monotonic()
        ptt_active = bool(self.is_ptt_active and self.is_ptt_active())

        if rms >= self._tx_wake_rms:
            self._tx_voice_frames += 1
        else:
            self._tx_voice_frames = 0
        if rms >= self._tx_sleep_rms:
            self._tx_last_voice = now

        if self._tx_state == "resting":
            should_wake = (
                ptt_active or self._tx_voice_frames >= self._tx_wake_frames
            )
            if not should_wake:
                self._tx_preroll.append(ulaw_payload)
                return

            # The current frame is sent live, not from the pre-roll.
            buffered = list(self._tx_preroll)
            self._tx_preroll.clear()
            self._tx_voice_frames = 0
            self._tx_state = "active"
            logger.info("TX active (%s)", "PTT" if ptt_active else "voice")
            self._notify_tx_state()
            for frame in buffered:
                self.send_audio(frame)
            self.send_audio(ulaw_payload)
            return

        if not ptt_active and now - self._tx_last_voice > self._tx_rest_s:
            self._tx_state = "resting"
            self._tx_preroll.clear()
            logger.info("TX resting - no voice for %.0fs", self._tx_rest_s)
            self._notify_tx_state()
            return

        self.send_audio(ulaw_payload)

    def check_health(self) -> bool:
        """Reopen streams when a device changes, goes silent or stops firing.

        Called periodically from the main loop. A device disappearing can
        stop callbacks without closing the stream objects, and a Windows
        default-device switch can leave the old stream open but silent.
        Re-resolving here means no fixed device name is ever required.
        Returns True when a restart was attempted.

        Returns
        -------
        bool
            True if the streams were restarted, False when still healthy.
        """
        if not self._running:
            return False

        now = time.monotonic()
        device_changed = (
            self._input_device_id >= 0
            and self._current_device_ids()
            != (self._input_device_id, self._output_device_id)
        )
        # Silence is intentional while TX is resting, so it must not be
        # treated as a dead input and restart the streams.
        input_silent = (
            self._tx_state != "resting"
            and self._input_silent_since > 0.0
            and now - self._input_silent_since > SILENT_INPUT_S
        )
        input_stalled = (
            self._input_stream is not None
            and now - self._last_input_callback > AUDIO_CALLBACK_STALL_S
        )
        output_stalled = (
            self._output_stream is not None
            and now - self._last_output_callback > AUDIO_CALLBACK_STALL_S
        )
        if not (device_changed or input_silent or input_stalled or output_stalled):
            return False

        # A broken device can stay broken after a restart; without a cooldown
        # the health loop would tear down the streams every 2 seconds.
        if now - self._last_restart < RESTART_COOLDOWN_S:
            return False

        logger.warning(
            "Audio unhealthy (device_changed=%s, silent=%s, input_stalled=%s, "
            "output_stalled=%s) - re-resolving devices and reopening streams",
            device_changed,
            input_silent,
            input_stalled,
            output_stalled,
        )
        self.stop()
        self.start()
        self._input_silent_since = 0.0
        self._reset_tx_state()
        self._notify_tx_state()
        return True

    def _current_device_ids(self) -> tuple[int, int]:
        """Resolve the devices that would be used by a fresh start().

        Called every health tick, so device logging is suppressed here; the
        restart itself logs the resolved devices through start().
        """
        return (
            self._resolve_device(self._input_device, is_input=True, log=False),
            self._resolve_device(self._output_device, is_input=False, log=False),
        )

    @property
    def is_running(self) -> bool:
        """Whether audio streams are active."""
        return self._running

    # -- RX: playback (called from session when audio arrives) --

    def play_ulaw(self, ulaw_payload: bytes, ts_ms: int) -> None:
        """Queue received ulaw audio for speaker playback.

        Uses the jitter buffer for reordering, PLC, and adaptive delay.

        Parameters
        ----------
        ulaw_payload : bytes
            ulaw-encoded audio frame (typically 160 bytes = 20ms).
        ts_ms : int
            The frame's IAX2 timestamp, already unwrapped to a monotonic
            32-bit value by IAX2Session. The buffer keys and orders playout
            by it: arrival time cannot be used because Asterisk's steps are
            irregular (20/40 ms measured) and two frames landing in the same
            millisecond would be discarded as duplicates.
        """
        if not self._running or self._output_stream is None:
            return

        import time

        self._frame_counter += 1
        frame = AudioFrame(
            timestamp_ms=ts_ms,
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
        self._last_input_callback = time.monotonic()

        if status:
            logger.debug("Input callback status: %s", status)

        if not self._running or self.on_tx_audio is None:
            return

        # Convert PCM int16 -> ulaw
        pcm_bytes = indata.tobytes()
        ulaw_payload = lin2ulaw(pcm_bytes)

        # Emit audio levels for visualizer and reuse the measured RMS for
        # the idle-TX decision instead of decoding the frame twice.
        rms = self._emit_levels(ulaw_payload)
        self._handle_tx(ulaw_payload, rms)

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
        self._last_output_callback = time.monotonic()

        if status:
            logger.debug("Output callback status: %s", status)

        # Get next frame from jitter buffer
        ulaw_payload: Optional[bytes] = self._jitter_buffer.pop()

        if ulaw_payload is not None and len(ulaw_payload) == frames:
            # Feed the visualizer from the received payload before playback.
            # RX levels travel through the same on_levels hook as TX, tagged
            # with a direction; input-silence tracking stays TX-only.
            self._emit_levels(ulaw_payload, direction="rx")

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

    def _emit_levels(self, ulaw_payload: bytes, direction: str = "tx") -> float:
        """Compute RMS/spectrum, track input silence on TX and fire callback.

        Both directions share this path so the existing on_levels hook remains
        the single level source for main.py; only the direction tag differs.
        """
        rms, spectrum = self._compute_levels(ulaw_payload)

        # A stalled callback is already covered by AUDIO_CALLBACK_STALL_S, but
        # a sleeping device can keep firing with all-zero frames. Track that
        # separately so check_health() can re-resolve the available devices.
        # RX playback delivers received audio, so it must never affect the
        # microphone's silence timer.
        if direction == "tx":
            if rms >= SILENT_INPUT_RMS:
                self._input_silent_since = 0.0
            elif self._input_silent_since == 0.0:
                self._input_silent_since = time.monotonic()

        if self.on_levels is None:
            return rms

        try:
            self._call_on_levels(self.on_levels, rms, spectrum, direction)
        except Exception:
            pass  # Don't crash audio thread on callback errors
        return rms

    @staticmethod
    def _call_on_levels(
        callback: Callable[..., None],
        rms: float,
        spectrum: list[float],
        direction: str,
    ) -> None:
        """Invoke on_levels with every supported arity.

        Older hooks take ``(rms, spectrum)`` (or just ``(rms)``); newer hooks
        also accept ``direction``. Inspection avoids misreading an exception
        raised inside the callback as a signature mismatch and calling it
        twice. Introspection failures fall back to the legacy two-argument
        call.
        """
        try:
            params = inspect.signature(callback).parameters.values()
            positional = [
                p for p in params
                if p.kind in (
                    inspect.Parameter.POSITIONAL_ONLY,
                    inspect.Parameter.POSITIONAL_OR_KEYWORD,
                )
            ]
            accepts_varargs = any(
                p.kind is inspect.Parameter.VAR_POSITIONAL for p in params
            )
        except (TypeError, ValueError):
            logger.debug(
                "Could not inspect on_levels callback %r; falling back to legacy "
                "two-argument call",
                callback,
            )
            callback(rms, spectrum)
            return

        if accepts_varargs or len(positional) >= 3:
            callback(rms, spectrum, direction)
        elif len(positional) == 1:
            callback(rms)
        else:
            callback(rms, spectrum)

    @staticmethod
    def _compute_levels(ulaw_payload: bytes) -> tuple[float, list[float]]:
        """Compute raw linear RMS and a real 16-band spectrum from ulaw audio.

        Returns ``(rms, spectrum)``. ``rms`` stays the raw linear 0..1 value
        that drives TX wake/sleep (S6); ``spectrum`` holds 16 log-spaced
        frequency bands in 0..1, mapped from a fixed -70..-20 dBFS window so a
        weak tone remains visible without any adaptive scaling (S2, S7).
        """
        empty_spectrum = [0.0] * SPECTRUM_BANDS
        if not ulaw_payload:
            return 0.0, empty_spectrum

        # Decode ulaw -> s16 PCM
        try:
            pcm = ulaw2lin(ulaw_payload)
        except Exception:
            return 0.0, empty_spectrum

        samples = struct.unpack(f"<{len(pcm) // 2}h", pcm)
        if not samples:
            return 0.0, empty_spectrum

        # Raw linear RMS. This formula is deliberately byte-for-byte the one
        # used before the FFT work: display scaling must not leak into TX gating.
        sum_sq = sum(s * s for s in samples)
        rms = math.sqrt(sum_sq / len(samples)) / 32768.0
        rms = min(rms, 1.0)

        n = len(samples)
        # Hann window limits leakage so a single tone lights its own band
        # instead of smearing across all 16. The real FFT is already provided
        # by numpy, which the companion requires.
        values = np.frombuffer(pcm, dtype="<i2").astype(np.float64)
        window = np.hanning(n)
        magnitudes = np.abs(np.fft.rfft(values * window))

        # Normalize by the window's coherent gain so a full-scale sine reads
        # ~32768 counts (0 dBFS). The dB window is fixed, not adaptive.
        coherent_gain = float(window.sum()) / 2.0
        if coherent_gain <= 0.0:
            return rms, empty_spectrum
        amplitude = magnitudes / coherent_gain
        dbfs = 20.0 * np.log10(np.maximum(amplitude / 32768.0, 1e-12))

        # Map each FFT bin to one log-spaced band. Skip DC (bin 0) so a DC
        # offset cannot light the lowest band. A per-band max keeps a tone
        # visible even when its band spans several bins.
        resolution_hz = RATE / n
        min_hz = max(resolution_hz, 1.0)
        max_hz = max(min_hz * 2.0, RATE / 2.0)
        edges = np.geomspace(min_hz, max_hz, num=SPECTRUM_BANDS + 1)
        freqs = np.fft.rfftfreq(n, d=1.0 / RATE)
        band_index = np.searchsorted(edges, freqs, side="right") - 1

        bands = np.full(SPECTRUM_BANDS, -np.inf)
        for bin_idx in range(1, len(freqs)):
            band = int(band_index[bin_idx])
            if 0 <= band < SPECTRUM_BANDS:
                bands[band] = max(bands[band], float(dbfs[bin_idx]))

        spectrum = np.clip(
            (bands - SPECTRUM_DB_MIN) / (SPECTRUM_DB_MAX - SPECTRUM_DB_MIN),
            0.0,
            1.0,
        )
        # -inf (empty band) clips to 0; nan_to_num keeps the contract total.
        spectrum = np.nan_to_num(spectrum, nan=0.0, neginf=0.0, posinf=1.0)
        # Perceptual lift: voice has low energy per band on short 20ms frames.
        # Keep 0/1 endpoints, but make quiet speech visible.
        spectrum = np.sqrt(spectrum)
        return rms, [float(value) for value in spectrum]

    # -- Helpers --

    @staticmethod
    def _describe_device(index: int) -> str:
        """Human-readable device description for startup logs."""
        if sd is None or index < 0:
            return f"id={index}"
        try:
            dev = sd.query_devices(index)
            api = sd.query_hostapis()[dev["hostapi"]]["name"]
            return (
                f"{index}: [{api}] {dev['name']!r} "
                f"in={dev['max_input_channels']} out={dev['max_output_channels']}"
            )
        except Exception:
            return f"id={index}"

    @staticmethod
    def _resolve_device(name: str, is_input: bool, log: bool = True) -> int:
        """Resolve a configured device to a sounddevice device ID.

        An empty or unmatched name falls back to the OS default device, so a
        fresh install works with any microphone brand. A numeric index or a
        name substring can pin a specific device when the user needs to.
        Pass log=False for frequent health-check polls.
        """
        if sd is None:
            return 0

        kind = "input" if is_input else "output"
        try:
            devices = list(sd.query_devices())
        except Exception as exc:
            logger.warning("Cannot enumerate audio devices: %s", exc)
            return 0

        def has_channels(dev: dict) -> bool:
            key = "max_input_channels" if is_input else "max_output_channels"
            return dev.get(key, 0) > 0

        def valid(index: object) -> bool:
            return (
                isinstance(index, int)
                and 0 <= index < len(devices)
                and has_channels(devices[index])
            )

        # sounddevice exposes the OS default as an _InputOutputPair (indexable,
        # but not a tuple/list), or as a single value when both sides were set
        # to the same device. Index first, fall back for scalar defaults.
        configured_default = sd.default.device
        try:
            default_id = configured_default[0 if is_input else 1]
        except (TypeError, IndexError):
            default_id = configured_default
        if not valid(default_id):
            default_id = next(
                (i for i, dev in enumerate(devices) if has_channels(dev)), 0
            )

        if not name:
            if log:
                logger.info(
                    "Using system default %s device: %s",
                    kind,
                    AudioEngine._describe_device(default_id),
                )
            return default_id

        # Explicit numeric index (allowed, but not required).
        try:
            index = int(name)
        except ValueError:
            index = None
        if index is not None:
            if valid(index):
                return index
            if log:
                logger.warning(
                    "Configured %s device index %s is invalid — using system default: %s",
                    kind, name, AudioEngine._describe_device(default_id),
                )
            return default_id

        # Name substring. Prefer exact name, then more channels (stereo over
        # mono hands-free profiles), then higher native sample rate.
        needle = name.strip().lower()
        candidates = [
            (i, dev) for i, dev in enumerate(devices)
            if needle in dev["name"].lower() and has_channels(dev)
        ]
        if not candidates:
            if log:
                logger.warning(
                    "Device '%s' not found — using system default: %s",
                    name, AudioEngine._describe_device(default_id),
                )
            return default_id

        channel_key = "max_input_channels" if is_input else "max_output_channels"
        chosen = max(
            candidates,
            key=lambda item: (
                item[1]["name"].lower() == needle,
                item[1][channel_key],
                item[1].get("default_samplerate") or 0,
                -item[0],
            ),
        )
        if len(candidates) > 1 and log:
            logger.info(
                "%d devices match '%s': %s — using %s. Set %s_device to an exact "
                "name or index to pin one.",
                len(candidates), name,
                ", ".join(AudioEngine._describe_device(i) for i, _ in candidates),
                AudioEngine._describe_device(chosen[0]), kind,
            )
        return chosen[0]

    def get_jitter_stats(self) -> dict:
        """Return jitter buffer statistics for monitoring."""
        return self._jitter_buffer.get_stats()

    def get_audio_stats(self) -> dict:
        """Return audio engine statistics."""
        return {
            "running": self._running,
            "input_stream": self._input_stream is not None,
            "output_stream": self._output_stream is not None,
            "tx_state": self._tx_state,
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
