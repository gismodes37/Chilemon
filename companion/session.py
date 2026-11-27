"""
companion/session.py -- IAX2 session wrapper with reconnect and call management.

Wraps IAX2Session from app.Services.WebRTCBridge.iax2 with companion-specific
logic: auto-reconnect, registration retry, call monitoring, status reporting.
"""

from __future__ import annotations

import asyncio
import logging
import time
from typing import Callable, Optional, Awaitable

from app.Services.WebRTCBridge.iax2 import IAX2Session

logger = logging.getLogger("companion.session")

# Retry constants
MAX_REG_RETRIES = 5
REG_RETRY_DELAY = 3.0  # seconds between registration retries
CALL_CHECK_INTERVAL = 5.0  # seconds between call health checks
CALL_IDLE_TIMEOUT = 60.0  # seconds without audio before auto-hangup


class CompanionSession:
    """High-level IAX2 session wrapper for the companion app.

    Manages registration, call lifecycle, and automatic reconnection.
    Provides callbacks for audio frames and status changes.
    """

    def __init__(
        self,
        host: str = "127.0.0.1",
        port: int = 4569,
        username: str = "companion-app",
        password: str = "",
        node: str = "",
        skip_registration: bool = True,
        local_port: int | None = None,
    ) -> None:
        self._host = host
        self._port = port
        self._username = username
        self._password = password
        self._node = node
        self._skip_registration = skip_registration
        self._local_port = local_port

        self._session: Optional[IAX2Session] = None
        self._registered = False
        self._in_call = False
        self._call_task: Optional[asyncio.Task[None]] = None
        self._monitor_task: Optional[asyncio.Task[None]] = None

        # Callback: received audio (ulaw bytes)
        self.on_audio_rx: Optional[Callable[[bytes, int], Awaitable[None]]] = None
        # Callback: status change (is_registered, in_call, ptt_active, error)
        self.on_status: Optional[Callable[[dict], Awaitable[None]]] = None
        # Hook: re-resolve the Pi endpoint before a reconnect.
        self.on_rediscover: Optional[Callable[[], Awaitable[None]]] = None

        self._loop: Optional[asyncio.AbstractEventLoop] = None
        self._reconnecting: bool = False  # guard against concurrent reconnects

        # PTT state (toggled by DTMF *)
        self.ptt_active: bool = False
        self.tx_state: str = "active"

        # Timing
        self._last_audio_time: float = 0.0

    # -- Properties --

    @property
    def is_registered(self) -> bool:
        return self._registered

    @property
    def in_call(self) -> bool:
        return self._in_call

    # -- Endpoint --

    async def set_endpoint(self, host: str, port: int) -> bool:
        """Point the session at a new Pi and drop the stale UDP transport.

        Returns True when the endpoint changed. The next ``start()`` rebuilds
        the transport against the new address; callers that are already running
        must trigger a reconnect themselves.
        """
        if host == self._host and port == self._port:
            return False

        previous = f"{self._host}:{self._port}"
        self._host = host
        self._port = port
        if self._session is not None:
            try:
                await self._session.close()
            except Exception:
                pass
            self._session = None
        self._registered = False
        self._in_call = False
        logger.info("IAX2 endpoint switched %s -> %s:%d", previous, host, port)
        return True

    # -- Lifecycle --

    async def start(self) -> None:
        """Create IAX2Session, connect transport, register."""
        logger.info(
            "Starting companion session -> %s:%s as '%s'",
            self._host, self._port, self._username,
        )
        local_addr: tuple[str, int] | None = None
        if self._local_port is not None:
            local_addr = ("0.0.0.0", self._local_port)

        self._session = IAX2Session(
            host=self._host,
            port=self._port,
            username=self._username,
            password=self._password,
            local_addr=local_addr,
        )
        self._session.on_audio_frame = self._on_audio_rx
        self._session.on_disconnect = self._on_disconnect
        self._session.on_inbound_call = self._on_inbound_call

        await self._register_with_retry()
        self._monitor_task = asyncio.create_task(self._monitor_loop())

    async def reconnect(self) -> None:
        """Public full reconnect, safe to call after an endpoint switch."""
        await self._reconnect()

    async def stop(self) -> None:
        """Tear down: hang up, unregister, close."""
        if self._monitor_task is not None:
            self._monitor_task.cancel()
            try:
                await self._monitor_task
            except asyncio.CancelledError:
                pass
            self._monitor_task = None

        if self._call_task is not None:
            self._call_task.cancel()
            try:
                await self._call_task
            except asyncio.CancelledError:
                pass
            self._call_task = None

        if self._session is not None:
            await self._session.close()
            self._session = None

        self._registered = False
        self._in_call = False
        logger.info("Companion session stopped")

    # -- Registration --

    async def _register_with_retry(self) -> None:
        """Attempt registration if not configured to skip it.

        With a static peer (host=127.0.0.1:9094 in iax.conf), Asterisk
        already knows our address — registration is unnecessary.
        When skip_registration=True we skip the 15s timeout entirely.
        """
        if self._skip_registration:
            # Static peer — Asterisk knows our address from iax.conf.
            # Still need to connect() so the UDP transport is established
            # for sending/receiving IAX2 frames.
            if self._session is not None:
                await self._session.connect()
                self._session._state = 2  # STATE_REGISTERED
            self._registered = True
            logger.info("Static peer — registration skipped, transport connected")
            await self._emit_status()
            return

        try:
            if self._session is None:
                raise RuntimeError("Session not created")
            success = await self._session.register()
            if success:
                self._registered = True
                logger.info("Registered as '%s'", self._username)
                await self._emit_status()
                return
        except (PermissionError, TimeoutError, ConnectionError, RuntimeError) as exc:
            logger.warning("Registration attempt failed: %s", exc)

        self._registered = False
        logger.error("Registration failed after all attempts")
        await self._emit_status(error="Registration failed")

    async def _reconnect(self) -> None:
        """Full reconnect: close and re-register."""
        if self._reconnecting:
            return  # already reconnecting, don't spawn another
        self._reconnecting = True
        try:
            logger.info("Starting reconnection sequence...")
            self._registered = False
            self._in_call = False

            if self._session is not None:
                try:
                    await self._session.close()
                except Exception:
                    pass
                self._session = None

            # A DHCP change may have moved the Pi since the last probe.
            if self.on_rediscover is not None:
                await self.on_rediscover()

            # Small delay before reconnect
            await asyncio.sleep(2.0)
            await self.start()
        finally:
            self._reconnecting = False

    # -- Call management --

    async def place_call(self, node: str = "") -> None:
        """Place an IAX2 call to the given ASL node.

        Automatically hangs up any existing call first for idempotency.
        Asterisk 22 (ASL3) may reject concurrent calls from the same
        static peer beyond the 2nd one, so a clean slate is essential.
        """
        target = node or self._node
        if not target:
            logger.warning("place_call: no node number configured")
            return
        if not self._registered or self._session is None:
            logger.warning("Cannot call: not registered")
            return

        # Idempotent hangup — ensures clean state before placing a new call
        if self._in_call:
            logger.info("Already in a call — hanging up first for clean state")
            await self.hangup_call()
            # Brief cooldown so Asterisk fully releases the channel
            await asyncio.sleep(0.5)

        try:
            logger.info("Starting call to node %s", target)
            # Log transport state before call
            transport = getattr(self._session, '_transport', None)
            logger.info("Transport state: transport=%s, state=%s, callno=%s",
                transport is not None,
                self._session._state,
                self._session._callno)
            # Use LOCAL node number as caller ID — AllStar's app_rpt requires
            # a numeric caller ID, otherwise it rejects with "Caller ID is not numeric"
            # NOTE: target is the REMOTE node, self._node is our LOCAL node
            caller_number = "".join(c for c in self._node if c.isdigit()) or "0"
            success = await self._session.start_call(target, caller_number=caller_number)
            if success:
                self._in_call = True
                self._last_audio_time = time.monotonic()
                logger.info("Call to node %s established", target)
            else:
                logger.warning("Call to node %s failed", target)
        except (TimeoutError, RuntimeError) as exc:
            logger.error("Call to %s failed: %s", target, exc)

        await self._emit_status()

    async def hangup_call(self) -> None:
        """Hang up the current call, forcing the transmitter off first."""
        if self._session is not None and self._in_call:
            # Mode S keys with '*' (toggle) and unkeys with '*' or '#'.
            # '#' forces the transmitter off regardless of toggle state and
            # must reach app_rpt before the IAX2 channel disappears.
            if self._session.send_dtmf("#"):
                await asyncio.sleep(0.2)
            await self._session.hangup_call()
            self._in_call = False
            self.ptt_active = False
            self._last_audio_time = 0.0
            logger.info("Call hung up")
            await self._emit_status()

    # -- PTT --

    async def ptt_key(self) -> None:
        """Key the transmitter via DTMF * (simplex toggle)."""
        if not self._in_call or self._session is None:
            return
        self._session.send_dtmf("*")
        self.ptt_active = True
        await self._emit_status()

    async def ptt_unkey(self) -> None:
        """Force the transmitter off via DTMF # (simplex hard unkey).

        '*' toggles in mode S, so a missed key/unkey leaves the node keyed
        and the remote end hears a constant carrier. '#' forces app_rpt TX
        off regardless of the toggle state.
        """
        if not self.ptt_active:
            return
        if self._session is not None:
            self._session.send_dtmf("#")
        self.ptt_active = False
        await self._emit_status()

    # -- DTMF --

    def send_dtmf(self, digit: str) -> None:
        """Send a single DTMF digit."""
        if self._session is not None:
            self._session.send_dtmf(digit)

    def send_dtmf_string(self, digits: str) -> None:
        """Send a DTMF string."""
        if self._session is not None:
            self._session.send_dtmf_string(digits)

    # -- Audio --

    def send_audio(self, ulaw_payload: bytes) -> bool:
        """Send ulaw audio frames — called from audio.py.

        PTT is a real transmit gate, not just dashboard state. On a duplex=0
        node AllStar keys the transmitter from any audio on the channel, so
        forwarding mic frames with PTT up re-keys the node and feeds a speaker
        -> mic feedback loop to the remote end. Drop every frame unless the
        operator is actively keyed.
        """
        if (
            self._session is not None
            and self._in_call
            and self.ptt_active
        ):
            return self._session.send_voice(ulaw_payload)
        return False

    async def _on_audio_rx(self, ulaw_payload: bytes, ts_ms: int) -> None:
        """Received audio from Asterisk — forward to audio.py callback.

        *ts_ms* is the frame's unwrapped IAX2 timestamp; the jitter buffer
        needs it to order and time playout.
        """
        self._last_audio_time = time.monotonic()
        if self.on_audio_rx:
            await self.on_audio_rx(ulaw_payload, ts_ms)

    async def _on_disconnect(self) -> None:
        """Remote disconnect from Asterisk."""
        logger.info("Remote disconnect received")
        self._in_call = False
        self.ptt_active = False
        self._last_audio_time = 0.0
        await self._emit_status()

    async def _on_inbound_call(self, called_num: str) -> None:
        """Inbound call from Asterisk (via AMI Originate or direct).

        Hangs up any existing call first to avoid conflicting with
        Asterisk's per-peer channel limit.
        """
        logger.info("Inbound call from Asterisk: called=%s", called_num)
        if self._session is not None:
            # Hang up any existing call first
            if self._in_call:
                logger.info("Inbound call while already in call — hanging up first")
                await self._session.hangup_call()
                self._in_call = False
                self.ptt_active = False
                await asyncio.sleep(0.3)

            self._session.accept_inbound()
            self._session.answer_inbound()
            self._in_call = True
            self._last_audio_time = time.monotonic()
            await self._emit_status()

    # -- Monitoring --

    async def _monitor_loop(self) -> None:
        """Periodic health check: registration, call state, audio timeout."""
        while True:
            try:
                await asyncio.sleep(CALL_CHECK_INTERVAL)
                await self._check_health()
            except asyncio.CancelledError:
                break
            except Exception as exc:
                logger.error("Monitor error: %s", exc)

    async def _check_health(self) -> None:
        """Check session health and reconnect if needed."""
        if not self._registered and self._session is not None and not self._reconnecting:
            logger.warning("Registration lost — reconnecting...")
            asyncio.ensure_future(self._reconnect())

        # Auto-hangup if no audio for CALL_IDLE_TIMEOUT
        if self._in_call and self._last_audio_time > 0:
            elapsed = time.monotonic() - self._last_audio_time
            if elapsed > CALL_IDLE_TIMEOUT:
                logger.info("Call idle for %.0fs — hanging up", elapsed)
                await self.hangup_call()

    # -- Status --

    async def set_tx_state(self, state: str) -> None:
        """Update the mic rest state shown by the dashboard."""
        self.tx_state = state
        await self._emit_status()

    async def _emit_status(self, error: str = "") -> None:
        """Broadcast current status via callback."""
        if self.on_status is None:
            return
        status = {
            "connected": self._registered,
            "call_active": self._in_call,
            "ptt": self.ptt_active,
            "tx_state": self.tx_state,
        }
        if error:
            status["error"] = error
        await self.on_status(status)

    def get_status(self) -> dict:
        """Return current status dict (synchronous)."""
        return {
            "connected": self._registered,
            "call_active": self._in_call,
            "ptt": self.ptt_active,
            "tx_state": self.tx_state,
        }

    def get_full_status(self, audio_stats: dict = None) -> dict:
        """Return full status including audio stats."""
        status = self.get_status()
        if audio_stats:
            status["audio"] = audio_stats
        return status
