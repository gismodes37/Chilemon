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
import time
import tomllib
from typing import Any, Optional

from companion.session import CompanionSession
from companion.audio import AudioEngine
from companion.ws_server import WSServer
from companion.discovery import find_reachable_pi, probe_local_subnet

# Windows Firewall check (optional — best-effort, never blocks startup)
try:
    from companion.firewall import check_and_warn as _check_firewall
except ImportError:
    _check_firewall = None  # type: ignore[assignment]

logger = logging.getLogger("companion")

# Default config path
DEFAULT_CONFIG = os.path.expanduser("~/.chilemon/config.toml")
FALLBACK_CONFIG = os.path.join(os.path.dirname(__file__), "config.toml")

# Audio callbacks fire every 20 ms; the browser only needs 20 updates/s.
# Throttling per direction keeps TX and RX visualizers independent.
AUDIO_LEVEL_INTERVAL_S = 0.05


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
        discovery_cfg = config.get("discovery", {})

        try:
            self._scan_prefix = int(discovery_cfg.get("subnet_prefix", 24))
        except (TypeError, ValueError):
            self._scan_prefix = 24

        # Stable node name from the dashboard config. Used as a candidate when
        # the pinned IP stops answering after a DHCP lease change.
        self._node_host = str(ast_cfg.get("node_host", "")).strip()

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

        # Last audio_level broadcast per direction, on the monotonic clock.
        self._last_level_broadcast: dict[str, float] = {}

        # Wire callbacks
        self._wire_callbacks()

    def _wire_callbacks(self) -> None:
        """Connect component callbacks."""
        # Session -> Audio: RX audio forwarding
        self.session.on_audio_rx = self._on_session_audio_rx

        # Session -> WS: status updates
        self.session.on_status = self._on_session_status
        self.session.on_rediscover = self._rediscover_endpoint

        # Browser -> Companion: host used to reach the dashboard
        self.ws_server.on_host = self._on_dashboard_host

        # Audio -> Session: TX audio forwarding
        self.audio.on_tx_audio = self._on_audio_tx

        # Audio -> WS: level metadata (TX mic and RX playback share this hook;
        # the audio engine tags each event with its direction).
        self.audio.on_levels = self._on_audio_levels

        # PTT state is read by the audio callback to wake a resting mic.
        self.audio.is_ptt_active = lambda: self.session.ptt_active
        self.audio.on_tx_state = self._on_tx_state

        # WS -> Session: PTT, DTMF, call
        self.ws_server.on_ptt_key = self.session.ptt_key
        self.ws_server.on_ptt_unkey = self.session.ptt_unkey
        self.ws_server.on_dtmf = self.session.send_dtmf
        self.ws_server.on_status_request = self._get_full_status
        self.ws_server.on_call = self._place_call
        self.ws_server.on_hangup = self._hangup

    async def _on_session_audio_rx(self, ulaw_payload: bytes, ts_ms: int) -> None:
        """Forward received audio from IAX2 to speaker."""
        self.audio.play_ulaw(ulaw_payload, ts_ms)

    async def _hangup(self) -> None:
        """Unkey, hang up and close mic/speaker so no audio loop remains."""
        await self.session.hangup_call()
        self.audio.reset()
        self.audio.stop()

    async def _place_call(self, node: str) -> None:
        """Reopen audio only when the IAX2 call was actually established."""
        await self.session.place_call(node)
        if self.session.in_call:
            self.audio.start()

    async def _on_session_status(self, status: dict) -> None:
        """Forward status and keep audio streams tied to the active call."""
        call_active = bool(status.get("call_active"))
        if call_active and not self.audio.is_running:
            self.audio.start()
        elif not call_active and self.audio.is_running:
            self.audio.reset()
            self.audio.stop()
        await self.ws_server.broadcast_status(status)

    def _on_audio_tx(self, ulaw_payload: bytes) -> None:
        """Forward mic audio to IAX2 session for transmission."""
        self.session.send_audio(ulaw_payload)

    def _on_audio_levels(
        self, rms: float, spectrum: list[float], direction: str = "tx"
    ) -> None:
        """Forward audio level metadata to browser visualizer.

        Called from the PortAudio callback thread, so the throttle uses the
        monotonic clock and the broadcast is scheduled with
        run_coroutine_threadsafe — never awaited on the audio thread.
        """
        last_sent = getattr(self, "_last_level_broadcast", None)
        if last_sent is None:
            last_sent = self._last_level_broadcast = {}

        now = time.monotonic()
        if now - last_sent.get(direction, 0.0) < AUDIO_LEVEL_INTERVAL_S:
            return
        last_sent[direction] = now

        loop = getattr(self, '_loop', None)
        if loop is not None and loop.is_running():
            asyncio.run_coroutine_threadsafe(
                self.ws_server.broadcast_audio_level(rms, spectrum, direction),
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

        # Audio streams open only while a call is active (see _on_session_status
        # and _place_call): an idle mic + speaker pair feeds back as a hum.

        # Start IAX2 session (registration, etc.)
        await self.session.start()

        # Start periodic stats logging and audio liveness checks
        self._stats_task = asyncio.create_task(self._stats_loop())
        self._audio_health_task = asyncio.create_task(self._audio_health_loop())

        logger.info("All components started — ready")

    async def _ensure_pi_connection(
        self, extra_hosts: tuple[str, ...] = ()
    ) -> bool:
        """Verify the Pi endpoint with IAX2 and switch to a reachable one.

        The configured address is only a hint: after a DHCP change it silently
        stops answering. Hostnames from the config and from the dashboard are
        tried too, and the address that actually sends an IAX2 reply wins.
        Returns True only when the endpoint changed.
        """
        configured_host = self.session._host
        port = self.session._port
        candidates = [self._node_host, *extra_hosts]
        logger.info(
            "Verifying IAX2 endpoint (configured=%s:%d, candidates=%s)",
            configured_host, port, candidates,
        )

        reachable = await find_reachable_pi(configured_host, port, candidates)
        if reachable is None:
            logger.warning(
                "No IAX2 reply at %s or %s — scanning local subnet /%d",
                configured_host, candidates, self._scan_prefix,
            )
            reachable = await probe_local_subnet(port, prefix=self._scan_prefix)

        if reachable is None:
            logger.error(
                "No ChileMon Pi answered an IAX2 probe at %s, %s or on the local "
                "subnet. Check that the Pi is on the same network and Asterisk "
                "is running.",
                configured_host, candidates,
            )
            return False

        if reachable == configured_host:
            logger.info("IAX2 endpoint confirmed at %s:%d", reachable, port)
            return False

        logger.info(
            "Pi moved from %s to %s — switching IAX2 endpoint",
            configured_host, reachable,
        )
        await self.session.set_endpoint(reachable, port)
        await self._save_config(reachable, port)
        return True

    async def _on_dashboard_host(self, host: str) -> None:
        """Re-verify using the address the dashboard was reached through."""
        candidate = str(host or "").strip()
        if (
            not candidate
            or len(candidate) > 253
            or any(char in candidate for char in "/\\?@# ")
        ):
            logger.warning("Ignoring invalid dashboard host %r", candidate)
            return
        if await self._ensure_pi_connection((candidate,)):
            # set_endpoint() drops the live transport; without this the session
            # stays unregistered until an unrelated failure triggers reconnect.
            await self.session.reconnect()

    async def _rediscover_endpoint(self) -> None:
        """Re-run discovery before a reconnect so a moved Pi is found."""
        await self._ensure_pi_connection()

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
                f.write(f"port = {pi_port}\n")
                node_host = str(config["asterisk"].get("node_host", "")).replace('"', '\\"')
                if node_host:
                    f.write(f'node_host = "{node_host}"\n')
                f.write("\n")
                
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
