"""
companion/ws_server.py -- Localhost WebSocket server for browser communication.

Accepts connections from the ChileMon dashboard on localhost.
JSON-only protocol (no binary audio):
- Browser -> Companion: PTT key/unkey, DTMF, status request
- Companion -> Browser: Connection status, audio level metadata, errors
"""

from __future__ import annotations

import asyncio
import json
import logging
from typing import Any, Callable, Optional

try:
    from aiohttp import web
except ImportError:
    web = None  # type: ignore[assignment]

logger = logging.getLogger("companion.ws")

# Message types (browser -> companion)
MSG_PTT = "ptt"
MSG_DTMF = "dtmf"
MSG_STATUS = "status"
MSG_CALL = "call"

# Message types (companion -> browser)
MSG_STATUS_RESP = "status"
MSG_AUDIO_LEVEL = "audio_level"
MSG_ERROR = "error"


class WSServer:
    """Localhost WebSocket server for browser ↔ companion communication.

    Provides:
    - PTT key/unkey forwarding to session
    - DTMF digit forwarding
    - Status broadcasting to all connected browser tabs
    - Audio level metadata for visualizer
    """

    def __init__(
        self,
        host: str = "127.0.0.1",
        port: int = 9093,
    ) -> None:
        self._host = host
        self._port = port
        self._app: Any = None
        self._runner: Any = None
        self._site: Any = None
        self._peers: set[Any] = set()

        # Callbacks (wired by main.py)
        self.on_ptt_key: Optional[Callable[[], Awaitable[None]]] = None
        self.on_ptt_unkey: Optional[Callable[[], Awaitable[None]]] = None
        self.on_dtmf: Optional[Callable[[str], None]] = None
        self.on_status_request: Optional[Callable[[], dict]] = None
        self.on_call: Optional[Callable[[str], Awaitable[None]]] = None
        self.on_hangup: Optional[Callable[[], Awaitable[None]]] = None

    # -- Lifecycle --

    async def start(self) -> None:
        """Start the aiohttp server on localhost."""
        if web is None:
            logger.error("aiohttp not installed — WS server disabled")
            return

        self._app = web.Application()
        self._app.router.add_get("/ws", self._handle_ws)
        self._app.router.add_get("/health", self._handle_health)

        self._runner = web.AppRunner(self._app)
        await self._runner.setup()
        self._site = web.TCPSite(self._runner, self._host, self._port)
        await self._site.start()
        logger.info("WS server listening on %s:%d", self._host, self._port)

    async def stop(self) -> None:
        """Stop the server and disconnect all peers."""
        for ws in list(self._peers):
            try:
                await ws.close()
            except Exception:
                pass
        self._peers.clear()

        if self._runner is not None:
            await self._runner.cleanup()
            self._runner = None
        logger.info("WS server stopped")

    # -- Handlers --

    async def _handle_ws(self, request: Any) -> Any:
        """WebSocket handler — accepts connection, routes messages."""
        ws = web.WebSocketResponse()
        await ws.prepare(request)
        self._peers.add(ws)
        peer = request.remote
        logger.info("WS client connected: %s (%d peers)", peer, len(self._peers))

        # Send initial status
        await self._send_status(ws)

        try:
            async for msg in ws:
                if msg.type == web.WSMsgType.TEXT:
                    await self._handle_message(ws, msg.data)
                elif msg.type in (web.WSMsgType.CLOSE, web.WSMsgType.ERROR):
                    break
        except Exception as exc:
            logger.debug("WS handler error: %s", exc)
        finally:
            self._peers.discard(ws)
            logger.info("WS client disconnected: %s (%d peers)", peer, len(self._peers))

        return ws

    async def _handle_health(self, request: Any) -> Any:
        """Health check endpoint."""
        return web.json_response({"status": "ok", "peers": len(self._peers)})

    async def _handle_message(self, ws: Any, data: str) -> None:
        """Route incoming JSON messages."""
        try:
            payload: dict[str, Any] = json.loads(data)
        except json.JSONDecodeError:
            return

        msg_type = payload.get("type", "")

        if msg_type == MSG_PTT:
            action = payload.get("action", "")
            if action == "key" and self.on_ptt_key:
                await self.on_ptt_key()
            elif action == "unkey" and self.on_ptt_unkey:
                await self.on_ptt_unkey()

        elif msg_type == MSG_DTMF:
            digit = payload.get("digit", "")
            if digit and self.on_dtmf:
                self.on_dtmf(digit)

        elif msg_type == MSG_CALL:
            action = payload.get("action", "")
            if action == "hangup":
                if self.on_hangup:
                    await self.on_hangup()
            else:
                node = payload.get("node", "")
                if node and self.on_call:
                    await self.on_call(node)

        elif msg_type == MSG_STATUS:
            await self._send_status(ws)

    # -- Broadcasting --

    async def broadcast_status(self, status: dict) -> None:
        """Send status update to all connected peers."""
        msg = json.dumps({
            "type": MSG_STATUS_RESP,
            **status,
        })
        await self._broadcast(msg)

    async def broadcast_audio_level(self, rms: float, spectrum: list[float]) -> None:
        """Send audio level metadata to all connected peers for visualizer."""
        msg = json.dumps({
            "type": MSG_AUDIO_LEVEL,
            "rms": rms,
            "spectrum": spectrum,
        })
        await self._broadcast(msg)

    async def _send_status(self, ws: Any) -> None:
        """Send current status to a single peer."""
        if self.on_status_request is None:
            return
        status = self.on_status_request()
        try:
            await ws.send_str(json.dumps({
                "type": MSG_STATUS_RESP,
                **status,
            }))
        except Exception:
            pass

    async def _broadcast(self, msg: str) -> None:
        """Send a string message to all connected peers."""
        dead: list[Any] = []
        for ws in self._peers:
            try:
                await ws.send_str(msg)
            except (ConnectionResetError, ConnectionError):
                dead.append(ws)
            except Exception as exc:
                logger.debug("Broadcast error: %s", exc)
                dead.append(ws)
        for ws in dead:
            self._peers.discard(ws)
