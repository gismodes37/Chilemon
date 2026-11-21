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
from companion.discovery import discover_pi

# Windows Firewall check (optional — best-effort, never blocks startup)
try:
    from companion.firewall import check_and_warn as _check_firewall
except ImportError:
    _check_firewall = None  # type: ignore[assignment]

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
        tx_cfg = config.get("tx", {})
        ws_cfg = config.get("ws", {})

        # Session
        self.session = CompanionSession(
            host=ast_cfg.get("host", "127.0.0.1"),
            port=ast_cfg.get("port", 4569),
            username=peer_cfg.get("username", "companion-app"),
            password=peer_cfg.get("password", ""),
            node=config.get("node", ""),
            skip_registration=peer_cfg.get("skip_registration", True),
            local_port=ast_cfg.get("local_port", None),
        )

        # Audio
        self.audio = AudioEngine(
            input_device=audio_cfg.get("input_device", ""),
            output_device=audio_cfg.get("output_device", ""),
            tx_rest_s=float(tx_cfg.get("idle_rest_s", 120)),
            tx_wake_rms=float(tx_cfg.get("wake_rms", 0.01)),
            tx_sleep_rms=float(tx_cfg.get("sleep_rms", 0.005)),
            tx_wake_frames=int(tx_cfg.get("wake_frames", 3)),
            tx_preroll_frames=int(tx_cfg.get("preroll_frames", 10)),
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

        # PTT state is read by the audio callback to wake a resting mic.
        self.audio.is_ptt_active = lambda: self.session.ptt_active
        self.audio.on_tx_state = self._on_tx_state

        # WS -> Session: PTT, DTMF, call
        self.ws_server.on_ptt_key = self.session.ptt_key
        self.ws_server.on_ptt_unkey = self.session.ptt_unkey
        self.ws_server.on_dtmf = self.session.send_dtmf
        self.ws_server.on_status_request = self._get_full_status
        self.ws_server.on_call = self.session.place_call
        self.ws_server.on_hangup = self._hangup

    async def _on_session_audio_rx(self, ulaw_payload: bytes, ts_ms: int) -> None:
        """Forward received audio from IAX2 to speaker."""
        self.audio.play_ulaw(ulaw_payload, ts_ms)

    async def _hangup(self) -> None:
        """Unkey and hang up the call, then drop buffered RX audio."""
        await self.session.hangup_call()
        self.audio.reset()

    async def _on_session_status(self, status: dict) -> None:
        """Forward session status changes to browser via WS."""
        await self.ws_server.broadcast_status(status)

    def _on_audio_tx(self, ulaw_payload: bytes) -> None:
        """Forward mic audio to IAX2 session for transmission."""
        self.session.send_audio(ulaw_payload)

    def _on_audio_levels(self, rms: float, spectrum: list[float]) -> None:
        """Forward audio level metadata to browser visualizer.

        Called from audio thread (non-async), so use run_coroutine_threadsafe.
        """
        loop = getattr(self, '_loop', None)
        if loop is not None and loop.is_running():
            asyncio.run_coroutine_threadsafe(
                self.ws_server.broadcast_audio_level(rms, spectrum),
                loop,
            )

    def _on_tx_state(self, state: str) -> None:
        """Push a TX rest/wake transition to the dashboard.

        Called from the audio thread, so the broadcast is scheduled on the
        event loop exactly like audio level updates.
        """
        loop = getattr(self, '_loop', None)
        if loop is not None and loop.is_running():
            asyncio.run_coroutine_threadsafe(
                self.session.set_tx_state(state), loop
            )

    def _get_full_status(self) -> dict:
        """Get full status including audio stats."""
        audio_stats = self.audio.get_audio_stats() if self.audio else {}
        return self.session.get_full_status(audio_stats)

    async def start(self) -> None:
        """Start all components."""
        self._loop = asyncio.get_running_loop()
        logger.info("Starting ChileMon Companion App")

        # Windows Firewall check (best-effort, non-blocking)
        if _check_firewall is not None:
            _check_firewall()

        # Auto-discovery: if config host is unreachable, try to find Pi
        await self._ensure_pi_connection()

        # Start WS server first (no IAX2 dependency)
        await self.ws_server.start()

        # Start audio engine
        self.audio.start()

        # Start IAX2 session (registration, etc.)
        await self.session.start()

        # Start periodic stats logging and audio liveness checks
        self._stats_task = asyncio.create_task(self._stats_loop())
        self._audio_health_task = asyncio.create_task(self._audio_health_loop())

        logger.info("All components started — ready")

    async def _ensure_pi_connection(self) -> None:
        """Ensure we can reach the ChileMon Pi.
        
        If the configured host is unreachable, try UDP auto-discovery.
        """
        config_host = self.session._host
        config_port = self.session._port
        
        logger.info("Checking connection to Pi at %s:%d...", config_host, config_port)
        
        # Quick connectivity check (UDP ping)
        try:
            import socket
            sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            sock.settimeout(1.0)
            # IAX2 requires ≥4 bytes per frame — 1-byte "midget" packets
            # are silently rejected by Asterisk ("midget packet received").
            sock.sendto(b'\x00\x00\x00\x00', (config_host, config_port))
            sock.close()
            logger.info("Pi reachable at %s:%d", config_host, config_port)
            return
        except Exception as e:
            logger.warning("Cannot reach Pi at %s:%d: %s", config_host, config_port, e)
        
        # Pi unreachable — try auto-discovery
        logger.info("Attempting UDP auto-discovery...")
        pi_info = await discover_pi(timeout=5.0)
        
        if pi_info:
            logger.info(
                "Auto-discovered Pi at %s:%d — updating session",
                pi_info["ip"], pi_info["port"]
            )
            # Update session with discovered IP
            self.session._host = pi_info["ip"]
            self.session._port = pi_info["port"]
            
            # Save updated config for next time
            await self._save_config(pi_info["ip"], pi_info["port"])
        else:
            logger.error(
                "Could not find ChileMon Pi. "
                "Please ensure the Pi is on the same network and running ChileMon."
            )

    async def stop(self) -> None:
        """Graceful shutdown of all components."""
        logger.info("Shutting down...")
        
        # Cancel stats task
        if hasattr(self, '_stats_task') and self._stats_task is not None:
            self._stats_task.cancel()
            try:
                await self._stats_task
            except asyncio.CancelledError:
                pass

        # Cancel audio health task
        if hasattr(self, '_audio_health_task') and self._audio_health_task is not None:
            self._audio_health_task.cancel()
            try:
                await self._audio_health_task
            except asyncio.CancelledError:
                pass

        await self.session.stop()
        self.audio.stop()
        await self.ws_server.stop()
        logger.info("Shutdown complete")

    async def _stats_loop(self) -> None:
        """Periodic stats logging for debugging."""
        while True:
            try:
                await asyncio.sleep(30)  # Log every 30 seconds
                if self.audio and self.audio.is_running:
                    self.audio.log_jitter_stats()
            except asyncio.CancelledError:
                break
            except Exception as exc:
                logger.error("Stats loop error: %s", exc)

    async def _audio_health_loop(self) -> None:
        """Reopen audio streams if PortAudio stops delivering callbacks.

        A sleeping Bluetooth device can halt both callbacks without closing
        the streams. Without this check the UI shows 'connected' while no
        audio moves in either direction and the visualizer stays flat.
        """
        while True:
            try:
                await asyncio.sleep(2)
                if self.audio:
                    self.audio.check_health()
            except asyncio.CancelledError:
                break
            except Exception as exc:
                logger.error("Audio health loop error: %s", exc)

    async def _save_config(self, pi_ip: str, pi_port: int) -> None:
        """Save updated Pi IP to config file for next startup."""
        try:
            config_path = DEFAULT_CONFIG
            os.makedirs(os.path.dirname(config_path), exist_ok=True)
            
            # Read existing config or create new
            config = {}
            if os.path.exists(config_path):
                with open(config_path, "rb") as f:
                    config = tomllib.load(f)
            
            # Update asterisk section
            if "asterisk" not in config:
                config["asterisk"] = {}
            config["asterisk"]["host"] = pi_ip
            config["asterisk"]["port"] = pi_port
            
            # Write TOML manually (simple format)
            with open(config_path, "w") as f:
                f.write("# ChileMon Companion App Configuration\n")
                f.write("# Auto-discovered Pi IP\n\n")
                
                f.write("[asterisk]\n")
                f.write(f'host = "{pi_ip}"\n')
                f.write(f"port = {pi_port}\n\n")
                
                f.write("[peer]\n")
                f.write('username = "companion-app"\n')
                peer_config = config.get("peer", {})
                # Never invent a credential: discovery only learns the Pi IP.
                # A missing password must stay empty and fail at connect time.
                password = str(peer_config.get("password", "")).replace('"', '\\"')
                f.write(f'password = "{password}"\n')
                f.write("skip_registration = true\n\n")
                
                f.write("[audio]\n")
                f.write('input_device = ""\n')
                f.write('output_device = ""\n')
                f.write("sample_rate = 8000\n")
                f.write("frames_per_buffer = 160\n\n")
                
                f.write("[ws]\n")
                f.write('bind_host = "127.0.0.1"\n')
                f.write("bind_port = 9093\n\n")
                
                f.write("[logging]\n")
                log_config = config.get("logging", {})
                f.write(f'level = "{log_config.get("level", "INFO")}"\n')
                f.write('file = ""\n')

            # The file contains the IAX2 peer secret: owner-only permissions.
            os.chmod(config_path, 0o600)

            logger.info("Saved updated config to %s", config_path)
        except Exception as e:
            logger.error("Failed to save config: %s", e)

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


def list_audio_devices() -> None:
    """Print available audio devices and mark the OS defaults."""
    try:
        import sounddevice as sd
    except ImportError:
        print("sounddevice is not installed — audio is unavailable.")
        return

    try:
        devices = sd.query_devices()
        apis = sd.query_hostapis()
    except Exception as exc:
        print(f"Cannot enumerate audio devices: {exc}")
        return

    default = sd.default.device
    try:
        default_in, default_out = default[0], default[1]
    except (TypeError, IndexError):
        default_in = default_out = default

    print("Available audio devices:")
    for i, dev in enumerate(devices):
        api = apis[dev["hostapi"]]["name"]
        markers = []
        if i == default_in:
            markers.append("DEFAULT INPUT")
        if i == default_out:
            markers.append("DEFAULT OUTPUT")
        suffix = f"  <-- {', '.join(markers)}" if markers else ""
        print(
            f"  {i:>2}: [{api}] {dev['name']!r} "
            f"in={dev['max_input_channels']} out={dev['max_output_channels']}{suffix}"
        )
    print()
    print("Leave input_device/output_device empty in ~/.chilemon/config.toml")
    print("to follow the system default, or set an ID or name substring to pin one.")


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
    parser.add_argument(
        "--log-file",
        default="",
        help="Write logs to this file instead of stdout",
    )
    parser.add_argument(
        "--list-devices",
        action="store_true",
        help="List available audio devices and exit",
    )
    args = parser.parse_args()

    if args.list_devices:
        list_audio_devices()
        return

    # Load config
    config = load_config(args.config)

    # Setup logging
    log_level = args.log_level or config.get("logging", {}).get("level", "INFO")
    log_file = args.log_file or config.get("logging", {}).get("file", "")
    setup_logging(log_level, log_file)

    # Run app
    app = CompanionApp(config)

    # Python 3.14+ on Windows uses ProactorEventLoop by default (IocpProactor).
    # The IocpProactor has a bug with asyncio DatagramTransport (UDP IAX2):
    #   assert fut is self._write_fut  (proactor_events.py:_loop_writing)
    # This causes the transport to crash on any UDP write after the first error.
    #
    # Switch to SelectorEventLoop which handles UDP correctly.
    # NOTE: asyncio.WindowsSelectorEventLoopPolicy is deprecated in 3.14,
    # slated for removal in 3.16, so we use the loop class directly.
    if sys.platform == "win32":
        try:
            with asyncio.Runner(
                loop_factory=asyncio.SelectorEventLoop,  # type: ignore[arg-type]
            ) as runner:
                runner.run(app.run())
        except KeyboardInterrupt:
            pass
        return

    # Default path (Linux/macOS): ProactorEventLoop has no UDP bug here
    try:
        asyncio.run(app.run())
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    main()
