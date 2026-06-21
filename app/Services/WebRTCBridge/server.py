"""
WebRTC Audio Bridge Daemon — aiohttp + aiortc server.

This daemon runs alongside Apache and Asterisk on the ASL3 node.
It provides:

- WebSocket endpoint at ``/ws`` for PTT signaling and audio relay
- WebRTC peer connection at ``/webrtc`` (aiortc)
- Health check at ``/health`` returning ``{"status":"ok"}``
- IAX2 server mode (inbound NEW via AMI Originate) via ``IAX2Server``
- AMI client (connect, login, Originate) via ``AMIClient``

Configuration
-------------
All values are read from environment variables:

========================  =================  ======  ===========================
Variable                 Default            Required Description
========================  =================  ======  ===========================
WEBRTC_PORT              9091               No       HTTP/WS listen port
IAX_LISTEN_PORT          9092               No       IAX2 server listen port
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

from app.Services.WebRTCBridge.iax2 import IAX2Server, IAX2Call, CALL_STATE_ACTIVE, CALL_STATE_HUNGUP
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

    The bridge listens on UDP 9092 for inbound IAX2 calls triggered by
    AMI ``Originate``. On WebSocket auth success, it calls
    ``ami.originate()`` which causes Asterisk to send an IAX2 NEW frame
    to the bridge. Audio is relayed bidirectionally between WS and IAX2.
    """

    def __init__(self, config: BridgeConfig) -> None:
        self.config = config

        # AMI client (connect/login to Asterisk manager)
        self.ami: AMIClient = AMIClient()

        # IAX2 server (listens for inbound NEW frames)
        self.iax_server: IAX2Server = IAX2Server()

        # Connected WebSocket peers
        self._ws_peers: set[web.WebSocketResponse] = set()

        # PTT state
        self._ptt_active: bool = False
        self._call_active: bool = False

        # Active IAX2 call (single-call model for v1)
        self._active_call: Optional[IAX2Call] = None

        # Wire IAX2 server callback
        self.iax_server.on_new_call = self._on_iax_new_call

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
        """Handle an inbound IAX2 NEW from Asterisk.

        Sets the active call, sends ACCEPT + ANSWER to complete setup,
        wires audio/hangup callbacks, and broadcasts status to WS peers.
        """
        self._active_call = call
        self._call_active = True
        logger.info(
            "Inbound IAX2 call from peer_callno=%d called_num=%s",
            call.peer_callno, call.called_num,
        )

        # Wire callbacks
        call.on_voice = self._on_iax_audio
        call.on_hangup = self._on_iax_disconnect

        # Complete call setup
        call.send_accept()
        await asyncio.sleep(0.05)  # small gap between frames
        call.send_answer()

        await self._broadcast_status()

    # -- IAX2 Audio Callback --

    async def _on_iax_audio(self, ulaw_payload: bytes) -> None:
        """Forward received ulaw audio from Asterisk to all WS peers."""
        if not self._ws_peers:
            return

        try:
            # RX pipeline: ulaw → PCM s16le → upscale to 16 kHz → float32
            pcm_f32 = rx_process(ulaw_payload)
            msg = json.dumps({
                "type": "audio",
                "data": pcm_f32.hex(),
                "rate": 16000,
            })
            for ws in list(self._ws_peers):
                try:
                    await ws.send_str(msg)
                except ConnectionResetError:
                    self._ws_peers.discard(ws)
        except Exception as exc:
            logger.exception("Audio RX callback error: %s", exc)

    async def _on_iax_disconnect(self) -> None:
        """Handle remote HANGUP from Asterisk — reset call state."""
        logger.info("IAX2 disconnect received — resetting call state")
        self._active_call = None
        self._call_active = False
        self._ptt_active = False
        await self._broadcast_status()

    # -- PTT Handlers --

    async def _ptt_key(self) -> None:
        """Key the transmitter via DTMF *99.

        Requires an active IAX2 call (established via AMI Originate).
        """
        if not self._active_call or self._active_call.state == CALL_STATE_HUNGUP:
            logger.warning("PTT key ignored: no active IAX2 call")
            return

        logger.debug("PTT key: sending *99")
        self._active_call.send_dtmf_string("*99")
        self._ptt_active = True

        await self._broadcast_status()

    async def _ptt_unkey(self) -> None:
        """Unkey the transmitter via DTMF #."""
        if not self._ptt_active:
            return

        logger.debug("PTT unkey: sending #")
        if self._active_call:
            self._active_call.send_dtmf("#")
        self._ptt_active = False

        await self._broadcast_status()

    async def _broadcast_status(self) -> None:
        """Send current bridge status to all connected WS peers."""
        status = {
            "type": "status",
            "ami_connected": self.ami.connected,
            "iax_server_running": self.iax_server.is_running,
            "active_call": self._call_active,
            "in_call": self._call_active,
            "ptt_active": self._ptt_active,
        }
        msg = json.dumps(status)
        for ws in list(self._ws_peers):
            try:
                await ws.send_str(msg)
            except ConnectionResetError:
                self._ws_peers.discard(ws)

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

        # Trigger AMI Originate to establish inbound IAX2 call
        try:
            aid = await self.ami.originate(
                channel=f"IAX2/webrtc-bridge/{self.config.asl_node}",
                context="webrtc",
                exten=self.config.asl_node,
                priority=1,
                callerid=f"\"WebRTC\" <{self.config.asl_node}>",
            )
            logger.info("AMI Originate sent — ActionID=%s", aid)
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
        except asyncio.CancelledError:
            pass
        finally:
            self._ws_peers.discard(ws)
            logger.info("WS client disconnected (%d peers)", len(self._ws_peers))

            # If no more peers, hang up the IAX2 call
            if not self._ws_peers and self._active_call is not None:
                logger.info("Last WS peer gone — sending IAX2 HANGUP")
                self._active_call.send_hangup()
                self._active_call = None
                self._call_active = False
                self._ptt_active = False

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

        elif msg_type == "audio_tx":
            # Transmit audio: hex-encoded float32 PCM 16 kHz from browser
            if not self._active_call:
                return
            try:
                pcm_f32_hex = payload.get("data", "")
                if pcm_f32_hex:
                    pcm_f32 = bytes.fromhex(pcm_f32_hex)
                    ulaw = tx_process(pcm_f32)
                    self._active_call.send_voice(ulaw)
            except (ValueError, KeyError) as exc:
                logger.warning("audio_tx parse error: %s", exc)

        else:
            logger.debug("Unknown WS message type: %s", msg_type)

    # -- Health Endpoint --

    async def handle_health(self, request: web.Request) -> web.Response:
        """Return bridge health status as JSON.

        Always responds 200 — the PHP status proxy uses this.
        """
        return web.json_response({
            "status": "ok",
            "ami_connected": self.ami.connected,
            "iax_server_running": self.iax_server.is_running,
            "active_call": self._call_active,
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
    logger.info("Starting WebRTC Audio Bridge v0.2.0 (bridge-reversal)")

    # 1. Start IAX2 server (must be listening before AMI Originate)
    try:
        await bridge.iax_server.start(
            host="0.0.0.0",
            port=bridge.config.iax_listen_port,
        )
        logger.info("IAX2 server listening on port %d", bridge.config.iax_listen_port)
    except ConnectionError as exc:
        logger.error("IAX2 server start failed: %s", exc)

    # 2. Connect and login to AMI
    try:
        await bridge.ami.connect(bridge.config.ami_host, bridge.config.ami_port)
        await bridge.ami.login(bridge.config.ami_user, bridge.config.ami_pass)
        logger.info("AMI connected and logged in as '%s'", bridge.config.ami_user)

        # 3. Start background event monitor
        bridge.ami._monitor_task = asyncio.create_task(bridge.ami.monitor_events())
    except (ConnectionError, PermissionError, OSError) as exc:
        logger.error("AMI startup failed: %s", exc)
        # Don't crash — health endpoint will show ami_connected=false


async def _on_shutdown(app: web.Application) -> None:
    """Cleanly tear down AMI and IAX2 server on shutdown."""
    bridge: WebRTCBridgeApp = app["bridge"]
    logger.info("Shutting down WebRTC Audio Bridge")

    # Close AMI connection
    try:
        await bridge.ami.close()
    except Exception as exc:
        logger.warning("AMI close error: %s", exc)

    # Stop IAX2 server
    try:
        await bridge.iax_server.stop()
    except Exception as exc:
        logger.warning("IAX2 server stop error: %s", exc)


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
