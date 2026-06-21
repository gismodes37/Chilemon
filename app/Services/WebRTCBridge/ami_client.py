"""
AMI (Asterisk Manager Interface) Client — async TCP for bridge-reversal.

Connects to Asterisk's AMI on 127.0.0.1:5038, performs login, sends
actions (primarily ``Originate`` with ``Async: true``), and reads async
events from the event stream in a background task.

Architecture
------------
``AMIClient`` opens a single TCP connection to Asterisk's manager port.
A background ``monitor_events()`` coroutine reads key:value pairs from the
socket, dispatching complete message blocks (delimited by ``\\r\\n\\r\\n``)
to registered callbacks.

A reconnect loop with exponential backoff (1s → 2s → 4s → max 30s)
keeps the connection alive through Asterisk restarts.

Frame Format (AMI Protocol)
---------------------------
Each message is a sequence of ``Key: Value`` pairs separated by ``\\r\\n``,
terminated by a blank line ``\\r\\n\\r\\n``. Actions are sent as:

    Action: Login\\r\\n
    Username: admin\\r\\n
    Secret: secret\\r\\n
    Events: on\\r\\n
    \\r\\n

Events arrive asynchronously:

    Event: Hangup\\r\\n
    Channel: IAX2/webrtc-bridge/...\\r\\n
    \\r\\n

Configuration
-------------
All values from environment variables (see ``server.py`` ``BridgeConfig``):

    AMI_HOST     (default: 127.0.0.1)
    AMI_PORT     (default: 5038)
    AMI_USER     (default: admin)
    AMI_PASS     (required)
    AMI_TIMEOUT  (default: 15)
"""

from __future__ import annotations

import asyncio
import logging
import re
from typing import Any, Awaitable, Callable, Optional
from uuid import uuid4

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# AMI Client
# ---------------------------------------------------------------------------

_RECONNECT_BASE = 1      # seconds
_RECONNECT_MAX = 30       # seconds
_DEFAULT_TIMEOUT = 15     # originate timeout


class AMIClient:
    """Async TCP client for Asterisk Manager Interface.

    Usage
    -----
        ami = AMIClient()
        await ami.connect("127.0.0.1", 5038)
        await ami.login("admin", "secret")

        # Register event callbacks
        ami.on_event("Hangup", my_hangup_handler)

        # Start background monitor
        monitor = asyncio.create_task(ami.monitor_events())

        # Originate a call
        aid = await ami.originate(
            Channel="IAX2/webrtc-bridge/61916",
            Context="webrtc",
            Exten="s",
            Priority=1,
        )

        # ... later ...
        await ami.close()
    """

    def __init__(self) -> None:
        self._reader: Optional[asyncio.StreamReader] = None
        self._writer: Optional[asyncio.StreamWriter] = None
        self._connected: bool = False
        self._logged_in: bool = False
        self._should_stop: bool = False

        # Connection parameters (set during connect/login, reused for reconnect)
        self._host: str = ""
        self._port: int = 5038
        self._user: str = ""
        self._pass: str = ""
        self._timeout: int = _DEFAULT_TIMEOUT

        # Event callback registry: event_type → [callable]
        self._event_callbacks: dict[str, list[Callable[[dict[str, str]], Awaitable[None]]]] = {}

        # Track background tasks
        self._monitor_task: Optional[asyncio.Task[None]] = None
        self._reconnect_task: Optional[asyncio.Task[None]] = None

    # -- Properties --

    @property
    def connected(self) -> bool:
        return self._connected

    @property
    def logged_in(self) -> bool:
        return self._logged_in

    # -- Lifecycle --

    async def connect(self, host: str = "127.0.0.1", port: int = 5038) -> None:
        """Open TCP connection to *host*:*port* and read Asterisk banner.

        The banner is a ``Asterisk Call Manager/1.x`` line followed by
        ``\\r\\n\\r\\n``. We consume it to advance the reader past the
        greeting before sending commands.
        """
        self._host = host
        self._port = port

        if self._writer is not None:
            return  # already connected

        try:
            self._reader, self._writer = await asyncio.open_connection(host, port)
        except OSError as exc:
            raise ConnectionError(
                f"Cannot connect AMI to {host}:{port}: {exc}"
            ) from exc

        # Read banner (ends with \r\n — Asterisk 11+ sends a single line)
        try:
            banner = await asyncio.wait_for(
                self._reader.readuntil(b"\r\n"),
                timeout=5.0,
            )
            logger.info("AMI connected — banner: %s", banner.decode("utf-8", errors="replace").strip())
        except asyncio.TimeoutError:
            self._close_transport()
            raise ConnectionError("AMI banner read timeout")

        self._connected = True

    async def login(self, user: str, secret: str, timeout: float = 5.0) -> None:
        """Send ``Action: Login`` and verify ``Response: Success``.

        Raises ``PermissionError`` on auth failure.
        Raises ``ConnectionError`` on transport/read error.
        """
        self._user = user
        self._pass = secret

        if self._logged_in:
            return

        msg = (
            f"Action: Login\r\n"
            f"Username: {user}\r\n"
            f"Secret: {secret}\r\n"
            f"Events: on\r\n"
            f"\r\n"
        )
        await self._send(msg)

        response = await self._read_message(timeout=timeout)
        if response is None:
            raise ConnectionError("AMI login: no response")

        if response.get("Response") == "Success":
            self._logged_in = True
            logger.info("AMI logged in as '%s'", user)
        else:
            err_msg = response.get("Message", "unknown error")
            raise PermissionError(f"AMI login rejected: {err_msg}")

    async def close(self) -> None:
        """Send ``Action: Logoff`` and close TCP connection.

        Stops background monitor and reconnect tasks.
        """
        self._should_stop = True

        # Cancel background tasks
        if self._monitor_task is not None and not self._monitor_task.done():
            self._monitor_task.cancel()
        if self._reconnect_task is not None and not self._reconnect_task.done():
            self._reconnect_task.cancel()

        if self._writer is not None and self._connected:
            try:
                await self._send("Action: Logoff\r\n\r\n")
            except Exception:
                pass

        self._close_transport()
        self._logged_in = False
        logger.info("AMI client closed")

    # -- Originate --

    async def originate(
        self,
        *,
        channel: str,
        context: str = "webrtc",
        exten: str = "s",
        priority: int = 1,
        callerid: str = "",
        async_: bool = True,
        timeout: int = 15000,
        variables: Optional[dict[str, str]] = None,
    ) -> str:
        """Send ``Action: Originate`` with auto-generated ``ActionID``.

        Parameters
        ----------
        channel : str
            Channel to call (e.g. ``IAX2/webrtc-bridge/61916``).
        context : str
            Dialplan context (default: ``webrtc``).
        exten : str
            Extension to dial (default: ``s``).
        priority : int
            Dialplan priority (default: 1).
        callerid : str
            Caller ID string (default: empty).
        async_ : bool
            Set ``Async: true`` (default: ``True``). Always true for this bridge.
        timeout : int
            Originate timeout in milliseconds (default: 15000).
        variables : dict or None
            Optional channel variables.

        Returns
        -------
        str
            The generated ActionID for event correlation.
        """
        if not self._logged_in:
            raise RuntimeError("AMI not logged in — cannot originate")

        action_id = f"bridge-reversal/{uuid4()}"

        lines = [
            f"Action: Originate",
            f"Channel: {channel}",
            f"Context: {context}",
            f"Exten: {exten}",
            f"Priority: {priority}",
            f"Async: {'true' if async_ else 'false'}",
            f"Timeout: {timeout}",
            f"ActionID: {action_id}",
        ]
        if callerid:
            lines.append(f"CallerID: {callerid}")
        if variables:
            for k, v in variables.items():
                lines.append(f"Variable: {k}={v}")
        lines.append("")
        msg = "\r\n".join(lines) + "\r\n"

        await self._send(msg)
        logger.info("AMI Originate sent — ActionID=%s Channel=%s", action_id, channel)
        return action_id

    # -- Event callbacks --

    def on_event(
        self,
        event_type: str,
        cb: Callable[[dict[str, str]], Awaitable[None]],
    ) -> None:
        """Register a callback for an AMI event type.

        The callback receives a dict of key/value pairs from the event.
        Multiple callbacks may be registered for the same event type.
        """
        if event_type not in self._event_callbacks:
            self._event_callbacks[event_type] = []
        self._event_callbacks[event_type].append(cb)

    # -- Background monitor --

    async def monitor_events(self) -> None:
        """Background reader: read AMI events from socket and dispatch.

        Runs until connection closes or ``close()`` is called.
        Intended to be launched as an ``asyncio.Task``.

        On unexpected disconnect, starts the reconnect loop.
        """
        while not self._should_stop:
            try:
                raw = await self._read_message_raw(timeout=None)
                if raw is None:
                    break
                self._dispatch_event(raw)
            except asyncio.IncompleteReadError:
                logger.warning("AMI connection lost in monitor")
                break
            except asyncio.CancelledError:
                break
            except Exception as exc:
                logger.error("AMI monitor error: %s", exc)
                break

        # Start reconnect loop if not intentional shutdown
        if not self._should_stop and self._connected:
            self._connected = False
            self._logged_in = False
            self._close_transport()
            logger.info("AMI monitor ended — starting reconnect loop")
            self._reconnect_task = asyncio.create_task(self._reconnect_loop())

    async def _reconnect_loop(self) -> None:
        """Reconnect with exponential backoff (1s → 2s → 4s → max 30s)."""
        delay = _RECONNECT_BASE
        while not self._should_stop:
            try:
                await asyncio.sleep(delay)
                logger.info("AMI reconnecting in %ds...", delay)
                await self.connect(self._host, self._port)
                await self.login(self._user, self._pass)
                self._monitor_task = asyncio.create_task(self.monitor_events())
                logger.info("AMI reconnected successfully")
                return
            except (ConnectionError, PermissionError, OSError) as exc:
                logger.warning("AMI reconnect failed (%s) — retry in %ds", exc, delay)
                delay = min(delay * 2, _RECONNECT_MAX)

    # -- Internal: send / receive --

    async def _send(self, msg: str) -> None:
        """Write string to the TCP socket and drain."""
        if self._writer is None:
            raise ConnectionError("AMI transport not connected")
        self._writer.write(msg.encode("utf-8"))
        await self._writer.drain()

    async def _read_message(
        self,
        timeout: float = 5.0,
    ) -> Optional[dict[str, str]]:
        """Read one AMI response and parse into a dict.

        Returns ``None`` on timeout or connection close.
        """
        raw = await self._read_message_raw(timeout=timeout)
        if raw is None:
            return None
        return _parse_ami_message(raw)

    async def _read_message_raw(self, timeout: Optional[float] = 5.0) -> Optional[str]:
        """Read bytes until ``\\r\\n\\r\\n`` and return as decoded string.

        Returns ``None`` if the reader is exhausted or on timeout.
        """
        if self._reader is None:
            return None
        try:
            data = await asyncio.wait_for(
                self._reader.readuntil(b"\r\n\r\n"),
                timeout=timeout,
            )
            return data.decode("utf-8", errors="replace")
        except asyncio.TimeoutError:
            return None
        except (asyncio.IncompleteReadError, ConnectionError):
            return None

    def _dispatch_event(self, raw: str) -> None:
        """Parse raw AMI message and invoke registered callbacks."""
        params = _parse_ami_message(raw)
        event_type = params.get("Event", "")
        if not event_type:
            return

        logger.debug("AMI event: %s", event_type)
        callbacks = self._event_callbacks.get(event_type, [])
        for cb in callbacks:
            try:
                asyncio.ensure_future(cb(params))
            except Exception as exc:
                logger.error("AMI event callback error (%s): %s", event_type, exc)

    def _close_transport(self) -> None:
        """Close the TCP writer if open."""
        if self._writer is not None:
            try:
                self._writer.close()
            except Exception:
                pass
            self._writer = None
        self._reader = None
        self._connected = False


# ---------------------------------------------------------------------------
# AMI message parser
# ---------------------------------------------------------------------------

# Regex for splitting lines: \r\n or \n (handle both)
_CRLF_RE = re.compile(r"\r?\n")


def _parse_ami_message(raw: str) -> dict[str, str]:
    """Parse a raw AMI message block into key/value pairs.

    Handles both ``\\r\\n`` and ``\\n`` line endings.
    Skips blank lines. Whitespace around the colon is stripped.
    """
    result: dict[str, str] = {}
    for line in _CRLF_RE.split(raw.strip()):
        line = line.strip()
        if not line:
            continue
        if ":" in line:
            key, _, value = line.partition(":")
            result[key.strip()] = value.strip()
    return result
