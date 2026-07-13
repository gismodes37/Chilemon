#!/usr/bin/env python3
"""
companion/main.py -- ChileMon Companion Audio App entry point.

Wires together:
- IAX2 session (registration, call, PTT, DTMF)
- Native audio (mic capture, speaker playback)
- Localhost WS server (browser communication)
- Signal handling for clean shutdown

Usage:
    python -m companion.main [--config ~/.chilemon/config.toml]
"""

from __future__ import annotations

import argparse
import asyncio
import logging
import os
import signal
import sys
import tomllib
from typing import Any, Optional

from companion.session import CompanionSession
from companion.audio import AudioEngine
from companion.ws_server import WSServer

logger = logging.getLogger("companion")

# Default config path
DEFAULT_CONFIG = os.path.expanduser("~/.chilemon/config.toml")
FALLBACK_CONFIG = os.path.join(os.path.dirname(__file__), "config.toml")


def load_config(path: str) -> dict[str, Any]:
    """Load TOML config from file, falling back to defaults."""
    config_paths = [path, DEFAULT_CONFIG, FALLBACK_CONFIG]
    for cp in config_paths:
        if cp and os.path.exists(cp):
            with open(cp, "rb") as f:
                return tomllib.load(f)
    return {}


def setup_logging(level: str = "INFO", log_file: str = "") -> None:
    """Configure logging to stdout or file."""
    kwargs: dict[str, Any] = {
        "level": getattr(logging, level.upper(), logging.INFO),
        "format": "%(asctime)s [%(levelname)s] %(name)s: %(message)s",
        "datefmt": "%Y-%m-%d %H:%M:%S",
    }
    if log_file:
        kwargs["filename"] = log_file
        kwargs["filemode"] = "a"
    else:
        kwargs["stream"] = sys.stdout

    logging.basicConfig(**kwargs)


class CompanionApp:
    """Main application — wires session, audio, and WS server together."""

    def __init__(self, config: dict[str, Any]) -> None:
        ast_cfg = config.get("asterisk", {})
        peer_cfg = config.get("peer", {})
        audio_cfg = config.get("audio", {})
        ws_cfg = config.get("ws", {})

        # Session
        self.session = CompanionSession(
            host=ast_cfg.get("host", "127.0.0.1"),
            port=ast_cfg.get("port", 4569),
            username=peer_cfg.get("username", "companion-app"),
            password=peer_cfg.get("password", ""),
            node=config.get("node", ""),
        )

        # Audio
        self.audio = AudioEngine(
            input_device=audio_cfg.get("input_device", ""),
            output_device=audio_cfg.get("output_device", ""),
        )

        # WS server
        self.ws_server = WSServer(
            host=ws_cfg.get("bind_host", "127.0.0.1"),
            port=ws_cfg.get("bind_port", 9093),
        )

        # Wire callbacks
        self._wire_callbacks()

    def _wire_callbacks(self) -> None:
        """Connect component callbacks."""
        # Session -> Audio: RX audio forwarding
        self.session.on_audio_rx = self._on_session_audio_rx

        # Session -> WS: status updates
        self.session.on_status = self._on_session_status

        # Audio -> Session: TX audio forwarding
        self.audio.on_tx_audio = self._on_audio_tx

        # Audio -> WS: level metadata
        self.audio.on_levels = self._on_audio_levels

        # WS -> Session: PTT, DTMF
        self.ws_server.on_ptt_key = self.session.ptt_key
        self.ws_server.on_ptt_unkey = self.session.ptt_unkey
        self.ws_server.on_dtmf = self.session.send_dtmf
        self.ws_server.on_status_request = self.session.get_status

    async def _on_session_audio_rx(self, ulaw_payload: bytes) -> None:
        """Forward received audio from IAX2 to speaker."""
        self.audio.play_ulaw(ulaw_payload)

    async def _on_session_status(self, status: dict) -> None:
        """Forward session status changes to browser via WS."""
        await self.ws_server.broadcast_status(status)

    def _on_audio_tx(self, ulaw_payload: bytes) -> None:
        """Forward mic audio to IAX2 session for transmission."""
        self.session.send_audio(ulaw_payload)

    def _on_audio_levels(self, rms: float, spectrum: list[float]) -> None:
        """Forward audio level metadata to browser visualizer."""
        asyncio.ensure_future(
            self.ws_server.broadcast_audio_level(rms, spectrum)
        )

    async def start(self) -> None:
        """Start all components."""
        logger.info("Starting ChileMon Companion App")

        # Start WS server first (no IAX2 dependency)
        await self.ws_server.start()

        # Start audio engine
        self.audio.start()

        # Start IAX2 session (registration, etc.)
        await self.session.start()

        logger.info("All components started — ready")

    async def stop(self) -> None:
        """Graceful shutdown of all components."""
        logger.info("Shutting down...")
        await self.session.stop()
        self.audio.stop()
        await self.ws_server.stop()
        logger.info("Shutdown complete")

    async def run(self) -> None:
        """Start and wait until shutdown."""
        await self.start()

        # Wait for shutdown signal
        stop_event = asyncio.Event()

        def _signal_handler() -> None:
            logger.info("Shutdown signal received")
            stop_event.set()

        loop = asyncio.get_running_loop()
        for sig in (signal.SIGINT, signal.SIGTERM):
            try:
                loop.add_signal_handler(sig, _signal_handler)
            except (NotImplementedError, ValueError):
                # Windows doesn't support add_signal_handler
                pass

        await stop_event.wait()
        await self.stop()


def main() -> None:
    """CLI entry point."""
    parser = argparse.ArgumentParser(
        description="ChileMon Companion Audio App",
    )
    parser.add_argument(
        "--config", "-c",
        default=DEFAULT_CONFIG,
        help=f"Config file path (default: {DEFAULT_CONFIG})",
    )
    parser.add_argument(
        "--log-level",
        default="",
        help="Log level: DEBUG, INFO, WARNING, ERROR",
    )
    args = parser.parse_args()

    # Load config
    config = load_config(args.config)

    # Setup logging
    log_level = args.log_level or config.get("logging", {}).get("level", "INFO")
    log_file = config.get("logging", {}).get("file", "")
    setup_logging(log_level, log_file)

    # Run app
    app = CompanionApp(config)
    try:
        asyncio.run(app.run())
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
