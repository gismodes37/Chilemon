"""
Minimal IAX2 Protocol Handler — RFC 5456 subset for phone endpoint.

Implements only the frames needed for a WebRTC ↔ Asterisk bridge:

- Registration:  REGREQ / REGACK / REGREL
- Call setup:    NEW / NEWACK / ACCEPT / ANSWER
- Voice:         Mini frames (4-byte header + ulaw payload)
- DTMF:          Full frame with DTMF subclass
- Control:       RINGING / ANSWER / HANGUP

Architecture
------------
``IAX2Session`` manages a single UDP transport to Asterisk port 4569.
It is async-safe (asyncio) and emits received audio frames via a callback.

Frame Format (12-byte full header, per RFC 5456 §4.2)
-----------------------------------
Offset  Size  Field        Description
0       2     src          F-bit (bit 15 = 0 for full frames) + source callno (bits 14-0)
2       2     dst          R-bit (bit 15 = 0 for non-retransmit) + dest callno (bits 14-0)
4       4     timestamp    Milliseconds since session start
8       1     oseqno       Outbound sequence number
9       1     iseqno       Inbound sequence number
10      1     frametype    IAX frame type (DTMF=0x01, VOICE=0x02, CONTROL=0x04, IAX=0x05)
11      1     c_subclass   C-bit (bit 7) + subclass (bits 6-0)

Mini Frame (4 bytes, F=1)
-------------------------
Offset  Size  Field        Description
0       2     type         F=1 (bit 15) + destination call number (bits 14-0)
2       2     ts_low       Low 16 bits of timestamp
"""

from __future__ import annotations

import asyncio
import logging
import struct
import time
from typing import Callable, Optional, Awaitable

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# IAX2 Constants
# ---------------------------------------------------------------------------

# -- Frame types (byte 10 of full header) --
IAX_TYPE_DTMF = 0x01
IAX_TYPE_VOICE = 0x02
IAX_TYPE_CONTROL = 0x04
IAX_TYPE_IAX = 0x05

# -- IAX control subclasses (IAX_TYPE_IAX) --
IAX_CMD_REGREQ = 0x01
IAX_CMD_REGACK = 0x02
IAX_CMD_REGREJ = 0x03
IAX_CMD_REGREL = 0x04
IAX_CMD_NEW = 0x06
IAX_CMD_NEWACK = 0x07
IAX_CMD_ACK = 0x0A
IAX_CMD_PING = 0x0D
IAX_CMD_PONG = 0x0E
IAX_CMD_ACCEPT = 0x0E   # Same value as PONG — distinguished by call context
IAX_CMD_LAGRQ = 0x20
IAX_CMD_LAGRP = 0x21
IAX_CMD_HANGUP = 0x16

# -- Control subclasses (IAX_TYPE_CONTROL) --
CONTROL_RINGING = 0x03
CONTROL_ANSWER = 0x0B

# -- Information Element types --
IE_USERNAME = 0x01
IE_PASSWORD = 0x02
IE_CAUSE = 0x04
IE_CALLING_NUMBER = 0x0A
IE_CALLING_NAME = 0x0B
IE_DATAFORMAT = 0x1D
IE_CODEC = 0x1E
IE_CALLED_NUMBER = 0x1F
IE_VERSION = 0x2B

# -- Codec IDs --
CODEC_ULAW = 0x04  # g711u / PCM ulaw

# -- DTMF digit bytes (subclass value for IAX_TYPE_DTMF) --
DTMF_DIGITS: dict[str, int] = {
    "0": 0x30, "1": 0x31, "2": 0x32, "3": 0x33,
    "4": 0x34, "5": 0x35, "6": 0x36, "7": 0x37,
    "8": 0x38, "9": 0x39, "*": 0x2A, "#": 0x23,
    "A": 0x41, "B": 0x42, "C": 0x43, "D": 0x44,
}

# -- Call state constants (for IAX2Call) --
CALL_STATE_IDLE = 0
CALL_STATE_CALLING = 1
CALL_STATE_ACTIVE = 2
CALL_STATE_HUNGUP = 3

# Maximum retries for registration
MAX_REG_RETRIES = 3
REG_TIMEOUT = 5.0
CALL_SETUP_TIMEOUT = 15.0

# Registration call number convention
REG_CALLNO = 0x8000


# ---------------------------------------------------------------------------
# IE builder
# ---------------------------------------------------------------------------

def _make_ie(ie_type: int, value: bytes | str) -> bytes:
    """Build an IAX2 Information Element (Type-Length-Value)."""
    if isinstance(value, str):
        value = value.encode("utf-8")
    return struct.pack("!BB", ie_type, len(value)) + value


# ---------------------------------------------------------------------------
# IAX2 Session
# ---------------------------------------------------------------------------

class IAX2Session:
    """Async IAX2 phone endpoint session.

    Parameters
    ----------
    host : str
        Asterisk IAX2 bind address (default: 127.0.0.1).
    port : int
        Asterisk IAX2 UDP port (default: 4569).
    username : str
        IAX2 phone extension username (matches iax.conf).
    password : str
        IAX2 phone extension secret.
    """

    STATE_IDLE = 0
    STATE_REGISTERING = 1
    STATE_REGISTERED = 2
    STATE_CALLING = 3
    STATE_ACTIVE = 4

    def __init__(
        self,
        host: str = "127.0.0.1",
        port: int = 4569,
        username: str = "webrtc-bridge",
        password: str = "",
    ) -> None:
        self.host = host
        self.port = port
        self.username = username
        self.password = password

        self._transport: Optional[asyncio.DatagramTransport] = None
        self._state = self.STATE_IDLE

        # Source call numbers
        self._reg_callno = REG_CALLNO & 0x7FFF
        self._callno: int = 0x0001
        self._dest_callno: int = 0

        # Sequence tracking
        self._oseqno: int = 0
        self._iseqno: int = 0

        # Timestamp base
        self._start_ts: float = 0.0

        # Registration retry
        self._reg_retries: int = 0

        # Async response synchronisation
        self._response_event = asyncio.Event()
        self._response_data: Optional[str] = None
        self._loop: Optional[asyncio.AbstractEventLoop] = None

        # -- Public callbacks --
        self.on_audio_frame: Optional[Callable[[bytes], Awaitable[None]]] = None
        self.on_disconnect: Optional[Callable[[], Awaitable[None]]] = None

    # -- Properties --

    @property
    def is_registered(self) -> bool:
        return self._state >= self.STATE_REGISTERED

    @property
    def in_call(self) -> bool:
        return self._state >= self.STATE_ACTIVE

    @property
    def state(self) -> int:
        return self._state

    # -- Transport lifecycle --

    async def connect(self) -> None:
        """Open UDP transport to Asterisk IAX2 port."""
        if self._transport is not None:
            return

        self._loop = asyncio.get_running_loop()
        self._start_ts = time.monotonic()

        class _Protocol(asyncio.DatagramProtocol):
            def __init__(self, owner: IAX2Session) -> None:
                self._owner = owner

            def datagram_received(self, data: bytes, addr) -> None:  # type: ignore[type-untyped]
                self._owner._on_datagram(data)

            def error_received(self, exc: Exception) -> None:
                logger.error("IAX2 transport error: %s", exc)

        try:
            self._transport, _ = await self._loop.create_datagram_endpoint(
                lambda: _Protocol(self),
                remote_addr=(self.host, self.port),
            )
            logger.info(
                "IAX2 transport open → %s:%s", self.host, self.port
            )
        except OSError as exc:
            raise ConnectionError(
                f"Cannot connect IAX2 to {self.host}:{self.port}: {exc}"
            ) from exc

    async def close(self) -> None:
        """Tear down: hang up, unregister, close transport."""
        if self.in_call:
            await self.hangup_call()
        if self.is_registered:
            await self.unregister()
        if self._transport is not None:
            self._transport.close()
            self._transport = None
        self._state = self.STATE_IDLE
        logger.info("IAX2 session closed")

    # -- Registration --

    async def register(self, timeout: float = REG_TIMEOUT) -> bool:
        """Register the phone extension with Asterisk.

        Returns True on success (REGACK received).
        Raises PermissionError on REGREJ, TimeoutError on timeout.
        """
        if self.is_registered:
            return True

        await self.connect()

        self._state = self.STATE_REGISTERING
        self._reg_retries = 0

        while self._reg_retries < MAX_REG_RETRIES:
            self._response_event.clear()
            self._response_data = None
            self._send_regreq()
            self._oseqno = (self._oseqno + 1) & 0xFF

            try:
                await asyncio.wait_for(
                    self._response_event.wait(), timeout=timeout
                )
            except asyncio.TimeoutError:
                self._reg_retries += 1
                logger.warning(
                    "REGREQ timeout (%d/%d)",
                    self._reg_retries,
                    MAX_REG_RETRIES,
                )
                continue

            if self._response_data == "REGACK":
                self._state = self.STATE_REGISTERED
                logger.info("IAX2 registered as '%s'", self.username)
                return True

            if self._response_data == "REGREJ":
                self._state = self.STATE_IDLE
                raise PermissionError(
                    "IAX2 registration rejected by Asterisk"
                )

        raise TimeoutError("IAX2 registration failed after all retries")

    async def unregister(self) -> None:
        """Send REGREL to release the phone extension."""
        if self._state >= self.STATE_REGISTERED:
            self._send_regrel()
            self._state = self.STATE_IDLE
            logger.info("IAX2 unregistered")

    # -- Call management --

    async def start_call(self, called_number: str) -> bool:
        """Place a call to *called_number* via phone context.

        Sends NEW → waits for NEWACK → waits for ANSWER.
        Returns True when answered.
        """
        if not self.is_registered:
            raise RuntimeError("Cannot call: not registered")

        self._callno = 0x0001
        self._dest_callno = 0
        self._state = self.STATE_CALLING

        # --- NEW ---
        self._response_event.clear()
        self._response_data = None
        self._send_new(called_number)
        self._oseqno = (self._oseqno + 1) & 0xFF

        try:
            await asyncio.wait_for(
                self._response_event.wait(), timeout=CALL_SETUP_TIMEOUT
            )
        except asyncio.TimeoutError:
            self._state = self.STATE_REGISTERED
            raise TimeoutError("Call setup timeout (no NEWACK)")

        # --- Wait for ANSWER ---
        self._response_event.clear()
        self._response_data = None

        try:
            await asyncio.wait_for(
                self._response_event.wait(), timeout=CALL_SETUP_TIMEOUT
            )
        except asyncio.TimeoutError:
            self._state = self.STATE_REGISTERED
            raise TimeoutError("Call setup timeout (no ANSWER)")

        if self._response_data == "ANSWER":
            logger.info("Call to %s answered (dest_callno=%d)", called_number, self._dest_callno)
            self._state = self.STATE_ACTIVE
            return True

        logger.warning("Call result: %s", self._response_data)
        self._state = self.STATE_REGISTERED
        return False

    async def hangup_call(self) -> None:
        """Hang up the active IAX2 call."""
        if self._state < self.STATE_CALLING:
            return
        self._send_hangup()
        self._oseqno = (self._oseqno + 1) & 0xFF
        self._state = self.STATE_REGISTERED
        self._dest_callno = 0
        logger.info("Call hung up")

    # -- DTMF and Voice --

    def send_dtmf(self, digit: str) -> bool:
        """Send a single DTMF digit via full frame.

        *digit* must be one of ``0-9*A-D#``.
        Returns True if queued, False if not in a call.
        """
        if not self.in_call:
            logger.warning("Cannot send DTMF: not in a call")
            return False

        d = DTMF_DIGITS.get(digit)
        if d is None:
            logger.warning("Invalid DTMF digit: %r", digit)
            return False

        self._send_full_frame(
            dest_callno=self._dest_callno,
            frametype=IAX_TYPE_DTMF,
            subclass=d,
        )
        logger.debug("DTMF sent: %s", digit)
        return True

    def send_dtmf_string(self, digits: str) -> None:
        """Send a string of DTMF digits sequentially (fire-and-forget)."""
        for d in digits:
            self.send_dtmf(d)

    def send_voice(self, ulaw_payload: bytes) -> bool:
        """Send ulaw audio as a mini voice frame.

        Parameters
        ----------
        ulaw_payload : bytes
            ulaw-encoded audio (20 ms = 160 bytes recommended).

        Returns True if sent, False if not in a call.
        """
        if not self.in_call:
            return False

        ts_raw = int((time.monotonic() - self._start_ts) * 1000)
        ts_low = ts_raw & 0xFFFF

        # Mini frame: F=1 (0x8000) + destination call number (15 bits)
        type_field = 0x8000 | (self._dest_callno & 0x7FFF)
        mini = struct.pack("!HH", type_field, ts_low)
        self._transport_send(mini + ulaw_payload)
        return True

    # -- Internal: frame builders --

    def _send_regreq(self) -> None:
        payload = (
            _make_ie(IE_USERNAME, self.username)
            + _make_ie(IE_PASSWORD, self.password)
        )
        self._send_full_frame(
            dest_callno=0,
            src_callno=self._reg_callno,
            frametype=IAX_TYPE_IAX,
            subclass=IAX_CMD_REGREQ,
            payload=payload,
        )

    def _send_regrel(self) -> None:
        self._send_full_frame(
            dest_callno=0,
            src_callno=self._reg_callno,
            frametype=IAX_TYPE_IAX,
            subclass=IAX_CMD_REGREL,
        )

    def _send_new(self, called_number: str) -> None:
        payload = (
            _make_ie(IE_CALLED_NUMBER, called_number)
            + _make_ie(IE_CODEC, struct.pack("!H", CODEC_ULAW))
        )
        self._send_full_frame(
            dest_callno=0,
            src_callno=self._callno,
            frametype=IAX_TYPE_IAX,
            subclass=IAX_CMD_NEW,
            payload=payload,
        )

    def _send_hangup(self) -> None:
        payload = _make_ie(IE_CAUSE, struct.pack("!B", 16))
        self._send_full_frame(
            dest_callno=self._dest_callno,
            src_callno=self._callno,
            frametype=IAX_TYPE_IAX,
            subclass=IAX_CMD_HANGUP,
            payload=payload,
        )

    def _send_ack(self, dest_callno: int, src_callno: int) -> None:
        """Send ACK frame (C=1, no payload)."""
        self._send_full_frame(
            dest_callno=dest_callno,
            src_callno=src_callno,
            frametype=IAX_TYPE_IAX,
            subclass=IAX_CMD_ACK,
            c=1,
        )

    def _send_pong(self, dest_callno: int, src_callno: int) -> None:
        self._send_full_frame(
            dest_callno=dest_callno,
            src_callno=src_callno,
            frametype=IAX_TYPE_IAX,
            subclass=IAX_CMD_PONG,
        )

    def _send_full_frame(
        self,
        dest_callno: int,
        frametype: int,
        subclass: int,
        payload: bytes = b"",
        src_callno: Optional[int] = None,
        c: int = 0,
    ) -> None:
        """Build and send a full IAX2 frame (12-byte header + payload)."""
        src = self._callno if src_callno is None else src_callno
        ts = int((time.monotonic() - self._start_ts) * 1000) & 0xFFFFFFFF

        # Per RFC 5456 §4.2:
        # Word 0: F(bit15) + source call number(bits14-0)
        first_word = src & 0x7FFF
        # Word 1: R(bit15) + destination call number(bits14-0)
        second_word = dest_callno & 0x7FFF
        # c_subclass: C (bit 7) + subclass (bits 6-0)
        cs = ((c & 1) << 7) | (subclass & 0x7F)

        header = struct.pack(
            "!HHIBBBB",
            first_word,
            second_word,
            ts,
            self._oseqno & 0xFF,
            self._iseqno & 0xFF,
            frametype & 0xFF,
            cs & 0xFF,
        )
        self._transport_send(header + payload)

    def _transport_send(self, data: bytes) -> None:
        if self._transport is not None:
            self._transport.sendto(data)

    # -- Internal: datagram receiver --

    def _on_datagram(self, data: bytes) -> None:
        """Parse incoming datagram and dispatch."""
        if len(data) < 4:
            return

        # Check F bit (bit 15 of first uint16)
        type_field = struct.unpack("!H", data[:2])[0]
        f = (type_field >> 15) & 1

        if f:
            self._on_mini_frame(data)
        else:
            self._on_full_frame(data)

    def _on_mini_frame(self, data: bytes) -> None:
        """Handle incoming mini frame (voice from Asterisk)."""
        # Mini header is 4 bytes
        if len(data) < 4:
            return

        type_field, _ts_low = struct.unpack("!HH", data[:4])
        callno = type_field & 0x7FFF

        voice_payload = data[4:]
        if voice_payload and self.on_audio_frame:
            asyncio.ensure_future(self.on_audio_frame(voice_payload))

        # ACK the mini frame's destination call number
        self._send_ack(dest_callno=callno, src_callno=self._callno)

    def _on_full_frame(self, data: bytes) -> None:
        """Parse and dispatch a full frame."""
        if len(data) < 12:
            return

        first_word, second_word, ts, oseqno, iseqno, frametype, cs = (
            struct.unpack("!HHIBBBB", data[:12])
        )
        payload = data[12:]

        src_callno = first_word & 0x7FFF
        c = (cs >> 7) & 1
        subclass = cs & 0x7F

        # Track incoming sequence number
        if c == 0:
            self._iseqno = oseqno

        logger.debug(
            "RX frame: type=0x%02X subclass=0x%02X src=%d len=%d",
            frametype, subclass, src_callno, len(payload),
        )

        # Dispatch by frame type
        if frametype == IAX_TYPE_IAX:
            self._on_iax_control(subclass, src_callno, payload)
        elif frametype == IAX_TYPE_CONTROL:
            self._on_control(subclass, src_callno)
        elif frametype == IAX_TYPE_VOICE:
            # Incoming voice full-frame (not mini) — rare from phone context
            if c == 1:
                pass  # ACK-only, no payload
            elif payload and self.on_audio_frame:
                asyncio.ensure_future(self.on_audio_frame(payload))

    def _on_iax_control(self, subclass: int, src_callno: int, payload: bytes) -> None:
        """Handle IAX control frames (registration, call setup)."""
        if subclass == IAX_CMD_REGACK:
            self._response_data = "REGACK"
            self._response_event.set()

        elif subclass == IAX_CMD_REGREJ:
            self._response_data = "REGREJ"
            self._response_event.set()

        elif subclass == IAX_CMD_NEWACK:
            self._dest_callno = src_callno
            logger.debug("NEWACK received, dest_callno=%d", self._dest_callno)
            self._response_data = "NEWACK"
            self._response_event.set()

        elif subclass == IAX_CMD_HANGUP:
            logger.info("Remote HANGUP received")
            self._state = self.STATE_REGISTERED
            self._dest_callno = 0
            self._response_data = "HANGUP"
            self._response_event.set()
            if self.on_disconnect:
                asyncio.ensure_future(self.on_disconnect())

        elif subclass == IAX_CMD_ACK:
            pass  # Acknowledgement — no action needed

        elif subclass == IAX_CMD_PING:
            self._send_pong(dest_callno=src_callno, src_callno=self._callno)

    def _on_control(self, subclass: int, src_callno: int) -> None:
        """Handle control frames (ringing, answer)."""
        if subclass == CONTROL_RINGING:
            logger.debug("Call ringing")
            self._response_data = "RINGING"
            self._response_event.set()

        elif subclass == CONTROL_ANSWER:
            logger.info("Call answered")
            self._dest_callno = src_callno
            self._response_data = "ANSWER"
            self._response_event.set()


# ---------------------------------------------------------------------------
# IAX2Call — represents a single active IAX2 call (server side)
# ---------------------------------------------------------------------------

class IAX2Call:
    """Represents a single IAX2 call on the server side.

    Created when an inbound NEW frame is received. Tracks call state,
    sequence numbers, and provides methods for sending frames back
    to the peer (Asterisk).

    Attributes
    ----------
    callno : int
        Local call number (allocated by IAX2Server).
    peer_callno : int
        Peer's call number (from the NEW frame source callno).
    called_num : str
        Called number from the NEW frame (IE_CALLED_NUMBER).
    state : int
        One of CALL_STATE_* constants.
    last_activity : float
        Monotonic timestamp of last received frame.
    peer_addr : tuple[str, int]
        Peer address (host, port) for sending frames.
    """

    def __init__(
        self,
        callno: int,
        peer_callno: int,
        called_num: str,
        peer_addr: tuple[str, int],
        transport: asyncio.DatagramTransport,
        start_ts: float,
    ) -> None:
        self.callno = callno
        self.peer_callno = peer_callno
        self.called_num = called_num
        self.state = CALL_STATE_CALLING
        self.last_activity: float = time.monotonic()
        self.peer_addr = peer_addr

        # Internal
        self._transport = transport
        self._start_ts = start_ts
        self.oseqno: int = 1
        self.iseqno: int = 0

        # -- Public callbacks (set by server.py) --
        self.on_voice: Optional[Callable[[bytes], Awaitable[None]]] = None
        self.on_hangup: Optional[Callable[[], Awaitable[None]]] = None

    # -- Frame sending helpers --

    def _send_full_frame(
        self,
        frametype: int,
        subclass: int,
        payload: bytes = b"",
        c: int = 0,
    ) -> None:
        """Build and send a full IAX2 frame for this call."""
        ts = int((time.monotonic() - self._start_ts) * 1000) & 0xFFFFFFFF
        first_word = self.callno & 0x7FFF
        second_word = self.peer_callno & 0x7FFF
        cs = ((c & 1) << 7) | (subclass & 0x7F)

        header = struct.pack(
            "!HHIBBBB",
            first_word,
            second_word,
            ts,
            self.oseqno & 0xFF,
            self.iseqno & 0xFF,
            frametype & 0xFF,
            cs & 0xFF,
        )
        self._transport.sendto(header + payload, self.peer_addr)
        self.oseqno = (self.oseqno + 1) & 0xFF

    def send_newack(self) -> None:
        """Send NEWACK acknowledging an incoming NEW frame.

        Includes IE_CALLED_NUMBER to echo back the called number.
        """
        ie_called = _make_ie(IE_CALLED_NUMBER, self.called_num)
        self._send_full_frame(IAX_TYPE_IAX, IAX_CMD_NEWACK, payload=ie_called)
        logger.debug("NEWACK sent for callno=%d", self.callno)

    def send_accept(self) -> None:
        """Send ACCEPT frame (IAX2 subclass 0x0E) — accept the incoming call."""
        self._send_full_frame(IAX_TYPE_IAX, IAX_CMD_ACCEPT)
        logger.debug("ACCEPT sent for callno=%d", self.callno)

    def send_answer(self) -> None:
        """Send ANSWER control frame — mark the call as answered/active."""
        self._send_full_frame(IAX_TYPE_CONTROL, CONTROL_ANSWER)
        self.state = CALL_STATE_ACTIVE
        logger.info("ANSWER sent for callno=%d → STATE_ACTIVE", self.callno)

    def send_hangup(self) -> None:
        """Send HANGUP frame to terminate the call."""
        if self.state == CALL_STATE_HUNGUP:
            return
        payload = _make_ie(IE_CAUSE, struct.pack("!B", 16))
        self._send_full_frame(IAX_TYPE_IAX, IAX_CMD_HANGUP, payload=payload)
        self.state = CALL_STATE_HUNGUP
        logger.info("HANGUP sent for callno=%d", self.callno)

    def send_dtmf(self, digit: str) -> bool:
        """Send a single DTMF digit via full frame.

        *digit* must be one of ``0-9*A-D#``.
        Returns True if queued, False if call not active.
        """
        if self.state != CALL_STATE_ACTIVE:
            logger.warning("Cannot send DTMF: call not active (state=%d)", self.state)
            return False

        d = DTMF_DIGITS.get(digit)
        if d is None:
            logger.warning("Invalid DTMF digit: %r", digit)
            return False

        self._send_full_frame(IAX_TYPE_DTMF, d)
        logger.debug("DTMF sent: %s", digit)
        return True

    def send_dtmf_string(self, digits: str) -> None:
        """Send a string of DTMF digits sequentially (fire-and-forget)."""
        for d in digits:
            self.send_dtmf(d)

    def send_voice(self, ulaw_payload: bytes) -> None:
        """Send ulaw audio as a mini voice frame to the peer."""
        if self.state == CALL_STATE_HUNGUP:
            return
        ts_raw = int((time.monotonic() - self._start_ts) * 1000)
        ts_low = ts_raw & 0xFFFF
        type_field = 0x8000 | (self.peer_callno & 0x7FFF)
        mini = struct.pack("!HH", type_field, ts_low)
        self._transport.sendto(mini + ulaw_payload, self.peer_addr)

    def send_ack(self) -> None:
        """Send ACK frame (C=1) for this call."""
        self._send_full_frame(IAX_TYPE_IAX, IAX_CMD_ACK, c=1)

    def handle_frame(self, frametype: int, subclass: int, c: int, payload: bytes) -> None:
        """Dispatch an incoming full frame to this call.

        Called by IAX2Server when a frame matches this call.
        """
        self.last_activity = time.monotonic()

        if c == 0:
            # Update input sequence tracking
            pass  # caller already updated iseqno

        if frametype == IAX_TYPE_IAX:
            self._handle_iax(subclass, payload)
        elif frametype == IAX_TYPE_CONTROL:
            self._handle_control(subclass)
        elif frametype == IAX_TYPE_VOICE and c == 0 and payload and self.on_voice:
            asyncio.ensure_future(self.on_voice(payload))
        elif frametype == IAX_TYPE_DTMF:
            logger.debug("DTMF received from peer: 0x%02X", subclass)
            # DTMF from Asterisk → forward to WS (future use)

    def _handle_iax(self, subclass: int, payload: bytes) -> None:
        """Handle IAX control frames for this call."""
        if subclass == IAX_CMD_HANGUP:
            logger.info("Remote HANGUP for callno=%d", self.callno)
            self.state = CALL_STATE_HUNGUP
            if self.on_hangup:
                asyncio.ensure_future(self.on_hangup())
        elif subclass == IAX_CMD_ACK:
            pass  # No action needed
        elif subclass == IAX_CMD_PING:
            # Respond with PONG using our call context
            self._send_full_frame(IAX_TYPE_IAX, IAX_CMD_PONG)
        elif subclass == IAX_CMD_LAGRQ:
            # LAGRP with same timestamp preserves timing for RTT measurement
            self._send_full_frame(IAX_TYPE_IAX, IAX_CMD_LAGRP)
        elif subclass == IAX_CMD_PONG:
            pass  # Ignore PONG — we didn't send PING
        else:
            logger.debug("Unhandled IAX frame: subclass=0x%02X", subclass)

    def _handle_control(self, subclass: int) -> None:
        """Handle control frames (e.g. ANSWER from Asterisk)."""
        if subclass == CONTROL_ANSWER:
            logger.info("CONTROL_ANSWER received for callno=%d", self.callno)
            self.state = CALL_STATE_ACTIVE


# ---------------------------------------------------------------------------
# IAX2Server — UDP listener for inbound IAX2 calls
# ---------------------------------------------------------------------------

class _ServerProtocol(asyncio.DatagramProtocol):
    """Internal protocol class for IAX2Server's datagram endpoint."""

    def __init__(self, server: IAX2Server) -> None:
        self._server = server

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        self._server._on_datagram(data, addr)

    def error_received(self, exc: Exception) -> None:
        logger.error("IAX2 server transport error: %s", exc)


class IAX2Server:
    """IAX2 server — listens for inbound NEW frames from Asterisk.

    Manages the UDP listener on port 9092, allocates call numbers
    for incoming calls, dispatches keepalive frames (PING→PONG,
    LAGRQ→LAGRP) at the transport level, and routes call-specific
    frames to the appropriate IAX2Call instance.

    Usage
    -----
        server = IAX2Server()
        server.on_new_call = my_on_new_call_callback
        await server.start("0.0.0.0", 9092)
        ...
        await server.stop()
    """

    def __init__(self) -> None:
        self._transport: Optional[asyncio.DatagramTransport] = None
        self._calls: dict[int, IAX2Call] = {}   # local callno → IAX2Call
        self._next_callno: int = 0x0001
        self._start_ts: float = 0.0

        # -- Public callback --
        self.on_new_call: Optional[Callable[[IAX2Call], Awaitable[None]]] = None

    @property
    def is_running(self) -> bool:
        return self._transport is not None

    async def start(self, host: str = "0.0.0.0", port: int = 9092) -> None:
        """Start the UDP listener on *host*:*port*."""
        if self._transport is not None:
            logger.warning("IAX2 server already running")
            return

        loop = asyncio.get_running_loop()
        self._start_ts = time.monotonic()

        try:
            self._transport, _ = await loop.create_datagram_endpoint(
                lambda: _ServerProtocol(self),
                local_addr=(host, port),
            )
            logger.info("IAX2 server listening on %s:%d", host, port)
        except OSError as exc:
            raise ConnectionError(
                f"Cannot bind IAX2 server to {host}:{port}: {exc}"
            ) from exc

    async def stop(self) -> None:
        """Stop the UDP listener and tear down all active calls."""
        if self._transport is None:
            return

        # Hang up all active calls
        for callno, call in list(self._calls.items()):
            try:
                call.send_hangup()
            except Exception:
                pass
        self._calls.clear()

        self._transport.close()
        self._transport = None
        logger.info("IAX2 server stopped")

    def _on_datagram(self, data: bytes, addr: tuple[str, int]) -> None:
        """Parse an incoming datagram and dispatch."""
        if len(data) < 4:
            return

        type_field = struct.unpack("!H", data[:2])[0]
        f = (type_field >> 15) & 1

        if f:
            self._on_mini_frame(data, addr)
        else:
            self._on_full_frame(data, addr)

    def _on_mini_frame(self, data: bytes, addr: tuple[str, int]) -> None:
        """Handle incoming mini voice frame."""
        if len(data) < 4:
            return

        type_field, _ts_low = struct.unpack("!HH", data[:4])
        dest_callno = type_field & 0x7FFF
        voice_payload = data[4:]

        call = self._find_call(dest_callno)
        if call is None:
            logger.debug("Mini frame for unknown callno=%d", dest_callno)
            return

        call.last_activity = time.monotonic()
        if voice_payload and call.on_voice:
            asyncio.ensure_future(call.on_voice(voice_payload))

        # Mini frames are fire-and-forget — no ACK sent.
        # Full-frame ACKs would confuse Asterisk's seqno tracking.

    def _on_full_frame(self, data: bytes, addr: tuple[str, int]) -> None:
        """Parse and dispatch a full frame."""
        if len(data) < 12:
            return

        first_word, second_word, ts, oseqno, iseqno, frametype, cs = (
            struct.unpack("!HHIBBBB", data[:12])
        )
        payload = data[12:]

        src_callno = first_word & 0x7FFF
        dest_callno = second_word & 0x7FFF
        c = (cs >> 7) & 1
        subclass = cs & 0x7F

        # --- NEW frame (dest_callno == 0, subclass == NEW) ---
        if dest_callno == 0 and frametype == IAX_TYPE_IAX and subclass == IAX_CMD_NEW:
            self._on_new(data, addr, src_callno, ts, oseqno)
            return

        # --- Keepalive at transport level (no call context needed) ---
        if frametype == IAX_TYPE_IAX:
            if subclass == IAX_CMD_PING:
                self._send_pong(dest_callno=0, src_callno=0, addr=addr)
                return
            if subclass == IAX_CMD_LAGRQ:
                self._send_lagrp(dest_callno=0, src_callno=0, addr=addr)
                return

        # --- Dispatch to active call ---
        call = self._find_call(dest_callno, src_callno)
        if call is None:
            logger.debug(
                "Frame for unknown call: type=0x%02X sub=0x%02X src=%d dst=%d",
                frametype, subclass, src_callno, dest_callno,
            )
            return

        call.last_activity = time.monotonic()
        # Update inbound seqno: only advance if the received oseqno matches our
        # expected iseqno. Non-matching seqnos indicate retransmit or OOO — skip
        # the update but still dispatch (Asterisk expects duplicate handling for
        # DTMF/control frames).
        if oseqno == call.iseqno:
            call.iseqno = (call.iseqno + 1) & 0xFF
        call.handle_frame(frametype, subclass, c, payload)

    def _on_new(self, data: bytes, addr: tuple[str, int],
                src_callno: int, ts: int, oseqno: int) -> None:
        """Handle an incoming NEW frame — allocate callno, create IAX2Call.

        Parses IE_CALLED_NUMBER from the payload, allocates a local call
        number, creates an IAX2Call, and invokes the ``on_new_call``
        callback. The caller is expected to send NEWACK + ACCEPT + ANSWER
        (in ``on_new_call``) to complete setup.
        """
        payload = data[12:]

        # Parse IEs to extract called number
        called_num = ""
        offset = 0
        while offset < len(payload):
            if offset + 2 > len(payload):
                break
            ie_type = payload[offset]
            ie_len = payload[offset + 1]
            if offset + 2 + ie_len > len(payload):
                break
            ie_value = payload[offset + 2: offset + 2 + ie_len]
            if ie_type == IE_CALLED_NUMBER:
                called_num = ie_value.decode("utf-8", errors="replace")
            offset += 2 + ie_len

        callno = self._allocate_callno()
        if callno is None:
            logger.error("No available call numbers — dropping NEW from %s", addr)
            return

        call = IAX2Call(
            callno=callno,
            peer_callno=src_callno,
            called_num=called_num,
            peer_addr=addr,
            transport=self._transport,
            start_ts=self._start_ts,
        )
        call.iseqno = oseqno
        self._calls[callno] = call
        logger.info(
            "NEW callno=%d peer_callno=%d called='%s' from %s:%d",
            callno, src_callno, called_num, addr[0], addr[1],
        )

        # Send NEWACK immediately
        call.send_newack()

        # Invoke callback (server.py will send ACCEPT + ANSWER and set _active_call)
        if self.on_new_call:
            asyncio.ensure_future(self.on_new_call(call))

    def _allocate_callno(self) -> int | None:
        """Allocate a unique local call number."""
        start = self._next_callno
        while True:
            candidate = self._next_callno
            self._next_callno = (self._next_callno + 1) & 0x7FFF
            if self._next_callno == 0:
                self._next_callno = 1
            if candidate not in self._calls:
                return candidate
            if self._next_callno == start:
                return None  # All call numbers exhausted

    def _find_call(self, dest_callno: int, src_callno: int = 0) -> IAX2Call | None:
        """Find a call by local callno, falling back to peer callno."""
        if dest_callno in self._calls:
            return self._calls[dest_callno]
        if src_callno in self._calls:
            return self._calls[src_callno]
        # Fallback: search by peer_callno
        for call in self._calls.values():
            if call.peer_callno == src_callno:
                return call
        return None

    def _send_pong(self, dest_callno: int, src_callno: int,
                   addr: tuple[str, int]) -> None:
        """Send PONG to keepalive PING (transport level)."""
        ts = int((time.monotonic() - self._start_ts) * 1000) & 0xFFFFFFFF
        first_word = src_callno & 0x7FFF
        second_word = dest_callno & 0x7FFF
        cs = IAX_CMD_PONG & 0x7F

        header = struct.pack(
            "!HHIBBBB",
            first_word, second_word, ts, 0, 0,
            IAX_TYPE_IAX, cs,
        )
        self._transport.sendto(header, addr)

    def _send_lagrp(self, dest_callno: int, src_callno: int,
                    addr: tuple[str, int]) -> None:
        """Send LAGRP in response to LAGRQ."""
        ts = int((time.monotonic() - self._start_ts) * 1000) & 0xFFFFFFFF
        first_word = src_callno & 0x7FFF
        second_word = dest_callno & 0x7FFF
        cs = IAX_CMD_LAGRP & 0x7F

        header = struct.pack(
            "!HHIBBBB",
            first_word, second_word, ts, 0, 0,
            IAX_TYPE_IAX, cs,
        )
        self._transport.sendto(header, addr)
