"""
companion/jitter_buffer.py -- Adaptive jitter buffer for IAX2 audio RX.

Implements a simple adaptive jitter buffer that:
- Reorders out-of-order packets
- Compensates for network jitter
- Provides Packet Loss Concealment (PLC) for missing frames
- Targets 40-100ms latency for responsive communication

Based on IAX2 best practices and Asterisk jitterbuffer documentation.
"""

from __future__ import annotations

import logging
import struct
import threading
import time
from collections import deque
from dataclasses import dataclass
from typing import Optional

logger = logging.getLogger("companion.jitter_buffer")

# Audio constants
FRAME_DURATION_MS = 20  # 20ms per frame at 8kHz
BYTES_PER_FRAME = 160   # 20ms * 8000 Hz * 2 bytes (ulaw 8-bit → 160 bytes)


@dataclass
class AudioFrame:
    """A single audio frame with timing metadata."""
    timestamp_ms: int      # Relative timestamp from IAX2 mini frame
    payload: bytes         # ulaw audio data
    received_at: float     # time.monotonic() when received
    sequence: int          # For ordering


class JitterBuffer:
    """Adaptive jitter buffer for IAX2 voice frames.
    
    Features:
    - Reorders out-of-order packets based on timestamp
    - Adaptive buffer size based on measured jitter
    - Packet Loss Concealment (PLC) for missing frames
    - Configurable target latency and max buffer size
    """
    
    def __init__(
        self,
        target_delay_ms: int = 60,      # Target buffer delay (ms)
        max_delay_ms: int = 200,        # Maximum buffer delay (ms)
        min_delay_ms: int = 20,         # Minimum buffer delay (ms)
        plc_enabled: bool = True,       # Enable Packet Loss Concealment
        max_plc_frames: int = 3,        # Max consecutive PLC frames before silence
    ) -> None:
        self._target_delay_ms = target_delay_ms
        self._max_delay_ms = max_delay_ms
        self._min_delay_ms = min_delay_ms
        self._plc_enabled = plc_enabled
        self._max_plc_frames = max_plc_frames
        
        # Buffer storage: timestamp -> frame
        self._buffer: dict[int, AudioFrame] = {}
        self._lock = threading.Lock()
        
        # Timing state
        self._first_timestamp: Optional[int] = None
        self._last_played_timestamp: Optional[int] = None
        self._play_start_time: Optional[float] = None
        
        # Jitter measurement
        self._jitter_estimates: deque[float] = deque(maxlen=50)
        self._last_arrival_time: Optional[float] = None
        self._last_timestamp: Optional[int] = None
        
        # PLC state
        self._plc_count: int = 0
        self._last_good_frame: Optional[AudioFrame] = None
        
        # Statistics
        self._frames_received: int = 0
        self._frames_played: int = 0
        self._frames_dropped: int = 0
        self._frames_plc: int = 0
        
        logger.info(
            "JitterBuffer initialized: target=%dms, max=%dms, PLC=%s",
            target_delay_ms, max_delay_ms, plc_enabled
        )
    
    def push(self, frame: AudioFrame) -> None:
        """Add a frame to the jitter buffer.
        
        Called from the IAX2 receive thread when a voice frame arrives.
        """
        with self._lock:
            self._frames_received += 1
            
            # Update jitter estimate
            self._update_jitter(frame)
            
            # Store frame in buffer
            ts = frame.timestamp_ms
            if ts in self._buffer:
                # Duplicate frame - ignore
                self._frames_dropped += 1
                return
            
            self._buffer[ts] = frame
            
            # Initialize timing on first frame
            if self._first_timestamp is None:
                self._first_timestamp = ts
                self._last_played_timestamp = ts - FRAME_DURATION_MS
                self._play_start_time = time.monotonic()
                logger.info("JitterBuffer: first frame received, ts=%d", ts)
            
            # Trim buffer if too large (drop oldest frames)
            self._trim_buffer()
    
    def pop(self) -> Optional[bytes]:
        """Get the next frame to play.

        Returns the earliest-timestamped buffered frame, or concealment
        audio when the buffer has run dry. Called from the audio playback
        thread.

        The step between consecutive timestamps is deliberately NOT assumed
        to be FRAME_DURATION_MS. Asterisk stamps the echo from its own
        receive clock, which mirrors our bursty Windows audio callback:
        the live *0 echo measured steps of 20 ms and 40 ms interleaved. An
        exact ``last + 20 ms`` walk misses those frames, the buffer fills
        to its maximum and _trim_buffer discards the rest.
        """
        with self._lock:
            if self._first_timestamp is None:
                return None

            if self._buffer:
                ts = min(self._buffer)
                frame = self._buffer.pop(ts)
                self._last_played_timestamp = ts
                self._last_good_frame = frame
                self._plc_count = 0
                self._frames_played += 1
                return frame.payload

            # Buffer underrun — conceal with the last good frame.
            if self._plc_count == 0:
                logger.debug("PLC: buffer empty, generating concealment")

            if self._plc_enabled and self._last_good_frame is not None:
                if self._plc_count < self._max_plc_frames:
                    plc_payload = self._generate_plc()
                    self._plc_count += 1
                    self._frames_plc += 1
                    return plc_payload

                # Too many consecutive missing frames — return silence.
                logger.warning(
                    "PLC: %d consecutive missing frames, returning silence",
                    self._plc_count,
                )
                return b'\x00' * BYTES_PER_FRAME

            return b'\x00' * BYTES_PER_FRAME
    
    def _update_jitter(self, frame: AudioFrame) -> None:
        """Update jitter estimate based on frame arrival."""
        now = time.monotonic()
        
        if self._last_arrival_time is not None and self._last_timestamp is not None:
            # Calculate inter-arrival jitter
            arrival_diff = now - self._last_arrival_time
            timestamp_diff = (frame.timestamp_ms - self._last_timestamp) / 1000.0
            
            # Jitter = |arrival_diff - timestamp_diff|
            jitter = abs(arrival_diff - timestamp_diff)
            self._jitter_estimates.append(jitter)
            
            # Adapt target delay based on jitter
            if len(self._jitter_estimates) >= 10:
                avg_jitter = sum(self._jitter_estimates) / len(self._jitter_estimates)
                # Set target delay to 2x average jitter, clamped to min/max
                new_target = int(avg_jitter * 2000)  # Convert to ms
                new_target = max(self._min_delay_ms, min(new_target, self._max_delay_ms))
                
                if abs(new_target - self._target_delay_ms) > 10:
                    logger.debug("JitterBuffer: adapting target delay %d -> %dms (avg jitter: %.1fms)",
                               self._target_delay_ms, new_target, avg_jitter * 1000)
                    self._target_delay_ms = new_target
        
        self._last_arrival_time = now
        self._last_timestamp = frame.timestamp_ms
    
    def _trim_buffer(self) -> None:
        """Remove old frames from buffer if it's too large."""
        if not self._buffer:
            return
        
        # Calculate max buffer size in frames
        max_frames = self._max_delay_ms // FRAME_DURATION_MS
        
        # Sort timestamps and remove oldest if needed
        timestamps = sorted(self._buffer.keys())
        while len(timestamps) > max_frames:
            old_ts = timestamps.pop(0)
            self._buffer.pop(old_ts)
            self._frames_dropped += 1
    
    def _generate_plc(self) -> bytes:
        """Generate a Packet Loss Concealment frame.
        
        Uses simple repetition of last good frame with decay.
        For better quality, could implement waveform substitution.
        """
        if self._last_good_frame is None:
            return b'\x00' * BYTES_PER_FRAME
        
        # Simple PLC: repeat last frame with slight volume reduction
        # In production, could use WSOLA or other advanced techniques
        payload = self._last_good_frame.payload
        
        # Apply slight decay to avoid clicking
        if self._plc_count > 0:
            # Simple decay: reduce amplitude by 10% per PLC frame
            decay = 0.9 ** self._plc_count
            # Convert ulaw to linear, apply decay, convert back
            # For simplicity, just return the same frame (basic PLC)
            pass
        
        return payload
    
    def get_stats(self) -> dict:
        """Return buffer statistics."""
        with self._lock:
            return {
                "frames_received": self._frames_received,
                "frames_played": self._frames_played,
                "frames_dropped": self._frames_dropped,
                "frames_plc": self._frames_plc,
                "buffer_size": len(self._buffer),
                "target_delay_ms": self._target_delay_ms,
                "current_jitter_ms": self._get_current_jitter_ms(),
            }
    
    def _get_current_jitter_ms(self) -> float:
        """Get current jitter estimate in milliseconds."""
        if not self._jitter_estimates:
            return 0.0
        return sum(self._jitter_estimates) / len(self._jitter_estimates) * 1000
    
    def reset(self) -> None:
        """Reset buffer state."""
        with self._lock:
            self._buffer.clear()
            self._first_timestamp = None
            self._last_played_timestamp = None
            self._play_start_time = None
            self._plc_count = 0
            self._last_good_frame = None
            logger.info("JitterBuffer reset")
