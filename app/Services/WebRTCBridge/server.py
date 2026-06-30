"""
WebRTC Audio Bridge Daemon — aiohttp + aiortc server.

This daemon runs alongside Apache and Asterisk on the ASL3 node.
It provides:

- WebSocket endpoint at ``/ws`` for PTT signaling and audio relay
- WebRTC peer connection at ``/webrtc`` (aiortc)
- Health check at ``/health`` returning ``{"status":"ok"}``
- IAX2 server mode (receives inbound call NEW frames from Asterisk) via ``IAX2Server``
- AMI client (connect, login, Originate) via ``AMIClient``

Configuration
-------------
All values are read from environment variables:

========================  =================  ======  ===========================
Variable                 Default            Required Description
========================  =================  ======  ===========================
WEBRTC_PORT              9091               No       HTTP/WS listen port
IAX_LISTEN_PORT          9092               No       Bridge IAX2 UDP listen port
AMI_HOST                 127.0.0.1          No       Asterisk AMI bind address
AMI_PORT                 5038               No       Asterisk AMI TCP port
AMI_USER                 admin              No       Asterisk AMI username
AMI_PASS                 —                  Yes      Asterisk AMI password
WEBRTC_SECRET            —                  Yes      HMAC secret for WS auth
ASL_NODE                 494780             No       Local ASL node number
LOG_LEVEL                INFO               No       Python log level
========================  =================  ======  ===========================

Startup
-------
    python -m app.Services.WebRTCBridge.server

Or directly:

    python server.py
"""

from __future__ import annotations

import asyncio
import hmac
import json
import logging
import os
import sys
from typing import Any, Optional

import aiohttp
from aiohttp import web

from app.Services.WebRTCBridge.iax2 import IAX2Server, IAX2Call
from app.Services.WebRTCBridge.ami_client import AMIClient
from app.Services.WebRTCBridge.audio import tx_process, rx_process

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

logging.basicConfig(
    level=os.environ.get("LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("webrtc-bridge")

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

# aiortc is optional — the server starts without it (health + WS only)
_has_aiortc = False
try:
    import aiortc  # noqa: F401 — ensure importable
    _has_aiortc = True
except ImportError:
    logger.warning("aiortc not available — WebRTC endpoint disabled")


def _env_str(key: str, default: str = "") -> str:
    return os.environ.get(key, default)


def _env_int(key: str, default: int) -> int:
    try:
        return int(os.environ.get(key, str(default)))
    except (ValueError, TypeError):
        return default


def linear_resample_float32(data: bytes, in_rate: int, out_rate: int) -> bytes:
    """Resample float32 PCM from in_rate to out_rate via linear interpolation.

    Pure Python (struct only). Handles arbitrary input/output rates.
    O(n) where n = output sample count.
    """
    if in_rate == out_rate:
        return data

    import struct

    count = len(data) // 4
    samples: tuple[float, ...] = struct.unpack(f"<{count}f", data)
    out_count = round(count * out_rate / in_rate)
    ratio = in_rate / out_rate
    out: list[float] = []
    for i in range(out_count):
        pos = i * ratio
        idx = int(pos)
        frac = pos - idx
        if idx + 1 < count:
            sample = samples[idx] * (1.0 - frac) + samples[idx + 1] * frac
        else:
            sample = samples[idx]
        out.append(sample)
    return struct.pack(f"<{len(out)}f", *out)


class BridgeConfig:
    """Daemon configuration, loaded from environment variables."""

    def __init__(self) -> None:
        self.webrtc_port: int = _env_int("WEBRTC_PORT", 9091)
        self.iax_listen_port: int = _env_int("IAX_LISTEN_PORT", 9092)
        self.ami_host: str = _env_str("AMI_HOST", "127.0.0.1")
        self.ami_port: int = _env_int("AMI_PORT", 5038)
        self.ami_user: str = _env_str("AMI_USER", "admin")
        self.ami_pass: str = _env_str("AMI_PASS", "")
        self.webrtc_secret: str = _env_str("WEBRTC_SECRET", "")
        self.asl_node: str = _env_str("ASL_NODE", "494780")

    def validate(self) -> list[str]:
        """Return list of missing required config items."""
        errors: list[str] = []
        if not self.ami_pass:
            errors.append("AMI_PASS is required")
        if not self.webrtc_secret:
            errors.append("WEBRTC_SECRET is required")
        return errors


# ---------------------------------------------------------------------------
# Bridge Application
# ---------------------------------------------------------------------------

class WebRTCBridgeApp:
    """Main bridge application — ties AMI, IAX2 server, WebSocket together.

    The bridge listens as an IAX2 server on UDP port 9092.
    On WebSocket auth success, it calls ``ami.originate()`` which triggers
    Asterisk to place an IAX2 call to the bridge.
    The bridge answers the inbound NEW frame, then audio/DTMF flow
    bidirectionally between WS and IAX2.
    """

    def __init__(self, config: BridgeConfig) -> None:
        self.config = config

        # AMI client (connect/login to Asterisk manager)
        self.ami: AMIClient = AMIClient()

        # IAX2 server — listens for inbound NEW frames from Asterisk
        self.iax_server: IAX2Server = IAX2Server()

        # Active IAX2 call (set when Asterisk calls us via AMI Originate)
        self._active_call: IAX2Call | None = None

        # Connected WebSocket peers
        self._ws_peers: set[web.WebSocketResponse] = set()

        # PTT state
        self._ptt_active: bool = False

        # Keepalive task reference
        self._keepalive_task: Optional[asyncio.Task[None]] = None

        # Wire IAX2 server callback
        self.iax_server.on_new_call = self._on_iax_new_call

        # Auto-reoriginate on call drop (max 3 attempts)
        self._reoriginate_count: int = 0
        self._max_reoriginate: int = 3
        self._reoriginate_delay: float = 2.0  # seconds
        self._reoriginate_task: Optional[asyncio.Task[None]] = None

        # Grace period on WS disconnect: keep IAX2 call alive briefly
        # so brief WS reconnects (<15s) resume instantly without re-originate
        self._ws_grace_period: float = 15.0  # seconds
        self._grace_task: Optional[asyncio.Task[None]] = None

    # -- Auth --

    def validate_ws_token(self, token: str) -> bool:
        """Validate a WebSocket HMAC token.

        Tokens are generated by the PHP endpoint ``/api/ptt-ws-token.php``
        using the same ``WEBRTC_SECRET``.
        """
        if not self.config.webrtc_secret:
            return False
        # Token format: "<username>:<hex_signature>"
        parts = token.split(":", 1)
        if len(parts) != 2:
            return False
        _username, signature = parts

        expected = hmac.new(
            self.config.webrtc_secret.encode("utf-8"),
            _username.encode("utf-8"),  # message = username only
            "sha256",
        ).hexdigest()[:16]  # first 16 hex chars

        # Constant-time comparison
        return hmac.compare_digest(signature, expected[: len(signature)])

    # -- IAX2 Inbound Call --

    async def _on_iax_new_call(self, call: IAX2Call) -> None:
        """Handle an inbound NEW frame from Asterisk.

        Asterisk sent a NEW IAX2 frame to our bridge.
        Wires audio/hangup callbacks, accepts and answers the call.
        """
        self._active_call = call
        self._reoriginate_count = 0  # reset retry counter
        self._cancel_reoriginate()   # cancel any pending reoriginate
        logger.info(
            "Inbound NEW from Asterisk — callno=%d called=%s (reoriginate_count=%d)",
            call.callno, call.called_num, self._reoriginate_count,
        )

        # Wire call-level callbacks
        call.on_voice = self._on_iax_audio
        call.on_hangup = self._on_iax_hangup
        call.on_accepted = self._on_iax_accepted

        # send_newack() is already called by IAX2Server._on_new with
        # IE_CALLED_NUMBER + IE_FORMAT.  Now send ANSWER so the call
        # transitions to active on Asterisk's side.
        call.send_answer()

        await self._broadcast_status()

    async def _on_iax_accepted(self, call: IAX2Call) -> None:
        """Called when TXACC is received — call established."""
        logger.info(
            "Call accepted by Asterisk — callno=%d state=ACTIVE",
            call.callno,
        )
        await self._broadcast_status()

    # -- IAX2 Audio Callback --

    async def _on_iax_audio(self, ulaw_payload: bytes) -> None:
        """Forward received ulaw audio from Asterisk to all WS peers."""
        peers = len(self._ws_peers)
        if not peers:
            logger.debug("Audio dropped: no WS peers")
            return

        try:
            # RX pipeline: ulaw → PCM s16le → AGC → float32 (native 8 kHz)
            pcm_f32 = rx_process(ulaw_payload)
            msg = json.dumps({
                "type": "audio",
                "data": pcm_f32.hex(),
                "rate": 8000,
            })
            logger.debug("Broadcasting audio to %d WS peer(s): %d bytes → %d floats",
                         peers, len(ulaw_payload), len(pcm_f32))
            for ws in list(self._ws_peers):
                try:
                    await ws.send_str(msg)
                except ConnectionResetError:
                    self._ws_peers.discard(ws)
                except Exception as exc:
                    logger.warning("WS send error: %s", exc)
                    self._ws_peers.discard(ws)
        except Exception as exc:
            logger.exception("Audio RX callback error: %s", exc)

    async def _on_iax_hangup(self, call: "IAX2Call") -> None:
        """Handle remote HANGUP from Asterisk — reset call state only if active."""
        if call is not self._active_call:
            logger.info("Ignoring HANGUP for stale callno=%d (active=%s)", call.callno, self._active_call)
            return
        logger.info("IAX2 HANGUP received for active call — resetting state")
        self._active_call = None
        self._ptt_active = False
        await self._broadcast_status()

        # Auto-reoriginate if WS peers still connected
        if self._ws_peers and self._reoriginate_count < self._max_reoriginate:
            logger.info(
                "Auto-reoriginate in %.1fs (attempt %d/%d, peers=%d)",
                self._reoriginate_delay,
                self._reoriginate_count + 1,
                self._max_reoriginate,
                len(self._ws_peers),
            )
            self._reoriginate_count += 1
            self._schedule_reoriginate()

    # -- PTT Handlers --

    async def _ptt_key(self) -> None:
        """Key the transmitter via DTMF * (simplex toggle).

        In Simplex Dumb Phone (S) mode, * toggles PTT on/off.
        Requires an active IAX2 call (established via AMI Originate).
        """
        if not self._active_call:
            logger.warning("PTT key ignored: no active IAX2 call")
            return

        logger.debug("PTT key: sending * (simplex toggle ON)")
        self._active_call.send_dtmf("*")
        self._ptt_active = True

        await self._broadcast_status()

    async def _ptt_unkey(self) -> None:
        """Unkey the transmitter via DTMF * (simplex toggle).

        In Simplex Dumb Phone (S) mode, * toggles PTT on/off.
        """
        if not self._ptt_active:
            return

        logger.debug("PTT unkey: sending * (simplex toggle OFF)")
        if self._active_call:
            self._active_call.send_dtmf("*")
        self._ptt_active = False

        await self._broadcast_status()

    def _cancel_reoriginate(self) -> None:
        """Cancel any pending reoriginate task."""
        if self._reoriginate_task is not None and not self._reoriginate_task.done():
            self._reoriginate_task.cancel()
            self._reoriginate_task = None

    # -- Grace Period (keep call alive after WS disconnect) --

    def _start_grace_period(self) -> None:
        """Start or restart the grace timer. When it fires, we hang up."""
        self._cancel_grace_period()
        self._grace_task = asyncio.create_task(self._grace_disconnect_loop())

    async def _grace_disconnect_loop(self) -> None:
        """Wait for grace period, then hang up IAX2 call if no WS reconnected."""
        try:
            await asyncio.sleep(self._ws_grace_period)
            if not self._ws_peers and self._active_call:
                callno = self._active_call.callno
                logger.info("Grace period expired — hanging up callno=%d", callno)
                self._active_call.send_hangup()
                self._active_call = None
                self._ptt_active = False
                await self._broadcast_status()
        except asyncio.CancelledError:
            logger.debug("Grace period cancelled — WS reconnected")
            raise

    def _cancel_grace_period(self) -> None:
        """Cancel grace timer early (WS reconnected)."""
        if self._grace_task is not None and not self._grace_task.done():
            self._grace_task.cancel()
            self._grace_task = None

    def _schedule_reoriginate(self) -> None:
        """Schedule a delayed reoriginate."""
        self._cancel_reoriginate()
        self._reoriginate_task = asyncio.create_task(
            self._do_reoriginate()
        )

    async def _do_reoriginate(self) -> None:
        """Wait, then re-originate an IAX2 call via AMI."""
        try:
            await asyncio.sleep(self._reoriginate_delay)

            # Double-check: still have peers and no active call
            if not self._ws_peers:
                logger.debug("Reoriginate cancelled: no WS peers")
                return
            if self._active_call is not None:
                logger.debug("Reoriginate skipped: call already active")
                self._reoriginate_count = 0
                return

            logger.info(
                "Reoriginating IAX2 call (attempt %d/%d)",
                self._reoriginate_count, self._max_reoriginate,
            )
            await self.ami.originate(
                channel=f"IAX2/webrtc-bridge/{self.config.asl_node}",
                context="webrtc",
                exten=self.config.asl_node,
                priority=1,
                callerid=f"\"WebRTC\" <{self.config.asl_node}>",
            )
        except asyncio.CancelledError:
            pass
        except Exception as exc:
            logger.error("Reoriginate failed: %s", exc)

    async def _broadcast_status(self) -> None:
        """Send current bridge status to all connected WS peers."""
        in_call = self._active_call is not None
        reconnecting = (
            not in_call
            and bool(self._ws_peers)
            and self._reoriginate_count > 0
        )
        status = {
            "type": "status",
            "ami_connected": self.ami.connected,
            "iax_server_running": self.iax_server.is_running,
            "active_call": in_call,
            "in_call": in_call,
            "ptt_active": self._ptt_active,
            "reconnecting": reconnecting,
        }
        msg = json.dumps(status)
        for ws in list(self._ws_peers):
            try:
                await ws.send_str(msg)
            except ConnectionResetError:
                self._ws_peers.discard(ws)

    # -- Keepalive --

    async def _keepalive_loop(self) -> None:
        """Send periodic keepalive pings to all connected WS peers.

        Prevents reverse proxies (Apache) from dropping idle WS connections.
        Interval: 15 seconds.
        """
        while True:
            await asyncio.sleep(15)
            if not self._ws_peers:
                continue
            msg = json.dumps({"type": "ping"})
            for ws in list(self._ws_peers):
                try:
                    await ws.send_str(msg)
                except (ConnectionResetError, ConnectionError) as exc:
                    logger.debug("Keepalive send error, discarding peer: %s", exc)
                    self._ws_peers.discard(ws)

    def _start_keepalive(self) -> None:
        """Launch the keepalive background task."""
        if self._keepalive_task is None or self._keepalive_task.done():
            self._keepalive_task = asyncio.create_task(self._keepalive_loop())
            logger.debug("Keepalive task started")

    async def _stop_keepalive(self) -> None:
        """Cancel the keepalive background task."""
        if self._keepalive_task is not None and not self._keepalive_task.done():
            self._keepalive_task.cancel()
            try:
                await self._keepalive_task
            except asyncio.CancelledError:
                pass
            self._keepalive_task = None
            logger.debug("Keepalive task stopped")

    # -- WebSocket Handler --

    async def handle_ws(self, request: web.Request) -> web.StreamResponse:
        """WebSocket handler at ``/ws``.

        Query parameter ``token`` (HMAC) is required for authentication.
        On auth success, triggers ``ami.originate()`` to establish an
        IAX2 call via Asterisk.
        """
        token = request.query.get("token", "")
        if not self.validate_ws_token(token):
            logger.warning("WS connection rejected: invalid token")
            return web.json_response(
                {"error": "invalid token"}, status=401
            )

        ws = web.WebSocketResponse()
        await ws.prepare(request)
        self._ws_peers.add(ws)
        logger.info("WS client connected (%d peers)", len(self._ws_peers))

        # Determine extension to call (overridable via query param)
        exten = request.query.get("exten", self.config.asl_node)
        context = request.query.get("context", "webrtc")

        # If there's an active IAX2 call (from grace period), skip AMI Originate.
        # The call survived the brief WS disconnect, so audio resumes instantly.
        if self._active_call:
            self._cancel_grace_period()
            logger.info(
                "WS reconnected — resuming active callno=%d (no re-originate)",
                self._active_call.callno,
            )
        else:
            # Trigger AMI Originate to establish inbound IAX2 call
            # Asterisk sends an IAX2 NEW frame to our bridge listener
            try:
                aid = await self.ami.originate(
                    channel=f"IAX2/webrtc-bridge/{self.config.asl_node}",
                    context=context,
                    exten=exten,
                    priority=1,
                    callerid=f"\"WebRTC\" <{self.config.asl_node}>",
                )
                logger.info("AMI Originate sent — exten=%s ActionID=%s", exten, aid)
            except (RuntimeError, ConnectionError, TypeError) as exc:
                logger.error("AMI Originate failed: %s", exc)

        # Send initial status
        await self._broadcast_status()

        try:
            async for msg in ws:
                if msg.type == aiohttp.WSMsgType.TEXT:
                    await self._handle_ws_message(ws, msg.data)
                elif msg.type == aiohttp.WSMsgType.ERROR:
                    logger.error("WS error: %s", ws.exception())
                elif msg.type == aiohttp.WSMsgType.CLOSE:
                    logger.info(
                        "WS client sent close — code=%s reason=%s",
                        msg.data,  # close code
                        getattr(msg, "extra", ""),
                    )
        except asyncio.CancelledError:
            pass
        except Exception as exc:
            logger.error("WS handler exception: %s", exc)
        finally:
            close_code = ws.close_code if hasattr(ws, "close_code") else "?"
            self._ws_peers.discard(ws)
            logger.info(
                "WS client disconnected (close_code=%s, peers=%d)",
                close_code, len(self._ws_peers),
            )

            # Grace period: keep IAX2 call alive for a while in case
            # the WS reconnects quickly (browser tab refresh, brief network drop).
            # Actual hangup happens only after _ws_grace_period seconds.
            if not self._ws_peers:
                self._cancel_reoriginate()
                if self._active_call and not self._ptt_active:
                    logger.info(
                        "Last WS peer gone — starting %.0fs grace period for callno=%d",
                        self._ws_grace_period, self._active_call.callno,
                    )
                    self._start_grace_period()

        return ws

    async def _handle_ws_message(
        self, ws: web.WebSocketResponse, data: str
    ) -> None:
        """Route incoming WebSocket messages."""
        try:
            payload: dict[str, Any] = json.loads(data)
        except json.JSONDecodeError:
            logger.warning("Invalid WS JSON: %.80s", data)
            return

        msg_type = payload.get("type", "")
        logger.debug("WS recv type=%s active_call=%s", msg_type, self._active_call is not None)

        if msg_type == "ptt":
            action = payload.get("action", "")
            if action == "key":
                await self._ptt_key()
            elif action == "unkey":
                await self._ptt_unkey()

        elif msg_type == "dtmf":
            digit = payload.get("digit", "")
            if digit and self._active_call:
                self._active_call.send_dtmf(digit)

        elif msg_type == "ping":
            # Respond to client keepalive pings
            try:
                await ws.send_str(json.dumps({"type": "pong"}))
            except (ConnectionResetError, ConnectionError) as exc:
                logger.debug("Pong send error: %s", exc)
                self._ws_peers.discard(ws)

        elif msg_type == "audio_tx":
            # Transmit audio: hex-encoded float32 PCM 16 kHz from browser
            rate = payload.get("rate", 16000)
            logger.info("audio_tx received: rate=%s, dataLen=%s, active_call=%s",
                        rate, len(payload.get("data", "")),
                        self._active_call is not None)
            if not self._active_call:
                logger.debug("audio_tx dropped: no active IAX2 call")
                return
            try:
                pcm_f32_hex = payload.get("data", "")
                if not pcm_f32_hex:
                    return

                # Validate rate: must be int between 8000 and 96000
                if not isinstance(rate, int) or rate < 8000 or rate > 96000:
                    logger.warning("audio_tx invalid rate=%s, assuming 16000", rate)
                    rate = 16000

                pcm_f32 = bytes.fromhex(pcm_f32_hex)

                # Fallback resample if rate != 16000
                if rate != 16000:
                    pcm_f32 = linear_resample_float32(pcm_f32, rate, 16000)
                    logger.warning("Fallback resample activated: rate=%d", rate)

                ulaw = tx_process(pcm_f32)
                sent = self._active_call.send_voice(ulaw)
                if sent:
                    logger.debug("audio_tx sent %d bytes ulaw (rate=%d)", len(ulaw), rate)
            except (ValueError, KeyError) as exc:
                logger.warning("audio_tx parse error: %s", exc)

        else:
            logger.debug("Unknown WS message type: %s", msg_type)

    # -- Health Endpoint --

    async def handle_health(self, request: web.Request) -> web.Response:
        """Return bridge health status as JSON.

        Always responds 200 — the PHP status proxy uses this.
        """
        in_call = self._active_call is not None
        return web.json_response({
            "status": "ok",
            "ami_connected": self.ami.connected,
            "iax_server_running": self.iax_server.is_running,
            "in_call": in_call,
            "active_call": in_call,
            "ptt_active": self._ptt_active,
            "peers": len(self._ws_peers),
        })

    # -- Application Factory --

    def create_app(self) -> web.Application:
        """Build the aiohttp application."""
        app = web.Application()

        app.router.add_get("/health", self.handle_health)
        app.router.add_get("/ws", self.handle_ws)

        if _has_aiortc:
            # WebRTC endpoint would go here in future phases
            logger.info("aiortc available — WebRTC endpoint ready")
        else:
            logger.info("aiortc not installed — WebRTC disabled")

        # Store app reference for cleanup
        app["bridge"] = self

        return app


# ---------------------------------------------------------------------------
# Startup / Shutdown
# ---------------------------------------------------------------------------

async def _on_startup(app: web.Application) -> None:
    """Start IAX2 server and connect AMI on application startup."""
    bridge: WebRTCBridgeApp = app["bridge"]
    logger.info("Starting WebRTC Audio Bridge v0.2.1 (iax2-server)")

    # 1. Start IAX2 server — listen for inbound NEW frames from Asterisk
    try:
        await bridge.iax_server.start("0.0.0.0", bridge.config.iax_listen_port)
        logger.info(
            "IAX2 server listening on 0.0.0.0:%d",
            bridge.config.iax_listen_port,
        )
    except (ConnectionError, PermissionError, OSError) as exc:
        logger.error("IAX2 server startup failed: %s", exc)
        # Don't crash — health endpoint will show iax_server_running=false

    # 2. Connect and login to AMI
    try:
        await bridge.ami.connect(bridge.config.ami_host, bridge.config.ami_port)
        await bridge.ami.login(bridge.config.ami_user, bridge.config.ami_pass)
        logger.info("AMI connected and logged in as '%s'", bridge.config.ami_user)

        # Start background event monitor
        bridge.ami._monitor_task = asyncio.create_task(bridge.ami.monitor_events())
    except (ConnectionError, PermissionError, OSError) as exc:
        logger.error("AMI startup failed: %s", exc)
        # Don't crash — health endpoint will show ami_connected=false

    # 3. Start keepalive background task
    bridge._start_keepalive()


async def _on_shutdown(app: web.Application) -> None:
    """Cleanly tear down IAX2 server, AMI, and keepalive on shutdown."""
    bridge: WebRTCBridgeApp = app["bridge"]
    logger.info("Shutting down WebRTC Audio Bridge")

    # Stop IAX2 server (hangs up active calls + closes UDP transport)
    try:
        await bridge.iax_server.stop()
    except Exception as exc:
        logger.warning("IAX2 server stop error: %s", exc)

    # Stop keepalive background task
    await bridge._stop_keepalive()

    # Close AMI connection
    try:
        await bridge.ami.close()
    except Exception as exc:
        logger.warning("AMI close error: %s", exc)


# ---------------------------------------------------------------------------
# Main entry point
# ---------------------------------------------------------------------------

def main() -> None:
    """Parse config, build app, and run the aiohttp server."""
    config = BridgeConfig()
    errors = config.validate()
    if errors:
        for err in errors:
            logger.error("Config error: %s", err)
        sys.exit(1)

    bridge = WebRTCBridgeApp(config)
    app = bridge.create_app()

    app.on_startup.append(_on_startup)
    app.on_shutdown.append(_on_shutdown)

    logger.info(
        "Listening on 0.0.0.0:%d — health at /health, WS at /ws",
        config.webrtc_port,
    )

    web.run_app(app, host="0.0.0.0", port=config.webrtc_port)


if __name__ == "__main__":
    main()
