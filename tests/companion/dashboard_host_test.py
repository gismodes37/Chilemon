"""Tests for dashboard-provided host candidates and endpoint switching.

The dashboard knows which address the browser used to reach the Pi. That value
is announced over the local WebSocket so a DHCP change can be picked up without
reinstalling the Companion.

Run with: python -m pytest tests/companion/dashboard_host_test.py -v
"""

from __future__ import annotations

import asyncio
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from companion import main as companion_main  # noqa: E402
from companion import ws_server  # noqa: E402
from companion.session import CompanionSession  # noqa: E402


class FakeTransport:
    def __init__(self) -> None:
        self.closed = False

    async def close(self) -> None:
        self.closed = True


def test_host_message_is_routed_to_the_callback() -> None:
    server = ws_server.WSServer()
    seen: list[str] = []

    async def on_host(host: str) -> None:
        seen.append(host)

    server.on_host = on_host
    payload = json.dumps({"type": ws_server.MSG_HOST, "host": "node494780.local"})
    asyncio.run(server._handle_message(None, payload))

    assert seen == ["node494780.local"]


def test_host_message_without_a_host_is_ignored() -> None:
    server = ws_server.WSServer()
    seen: list[str] = []

    async def on_host(host: str) -> None:
        seen.append(host)

    server.on_host = on_host
    asyncio.run(server._handle_message(None, json.dumps({"type": "host", "host": ""})))
    asyncio.run(server._handle_message(None, json.dumps({"type": "host"})))

    assert seen == []


def test_switching_endpoint_drops_the_stale_transport() -> None:
    session = CompanionSession(host="192.168.0.110")
    transport = FakeTransport()
    session._session = transport  # type: ignore[assignment]
    session._registered = True

    changed = asyncio.run(session.set_endpoint("192.168.0.125", 4569))

    assert changed is True
    assert session._host == "192.168.0.125"
    assert session._session is None
    assert session._registered is False
    assert transport.closed is True


def test_same_endpoint_is_a_noop() -> None:
    session = CompanionSession(host="192.168.0.125")
    transport = FakeTransport()
    session._session = transport  # type: ignore[assignment]

    changed = asyncio.run(session.set_endpoint("192.168.0.125", 4569))

    assert changed is False
    assert session._session is transport
    assert transport.closed is False


class FakeSession:
    def __init__(self) -> None:
        self._host = "192.168.0.110"
        self._port = 4569
        self.endpoints: list[tuple[str, int]] = []
        self.reconnects = 0

    async def set_endpoint(self, host: str, port: int) -> bool:
        self.endpoints.append((host, port))
        self._host = host
        self._port = port
        return True

    async def reconnect(self) -> None:
        self.reconnects += 1


class FakeApp:
    def __init__(self) -> None:
        self._node_host = ""
        self._scan_prefix = 24
        self.session = FakeSession()
        self.saved: list[tuple[str, int]] = []

    async def _save_config(self, host: str, port: int) -> None:
        self.saved.append((host, port))

    async def _ensure_pi_connection(self, extra_hosts: tuple[str, ...] = ()) -> bool:
        return await companion_main.CompanionApp._ensure_pi_connection(
            self, extra_hosts
        )


def test_dashboard_host_switch_restarts_the_session(monkeypatch: object) -> None:
    async def reachable(_host: str, _port: int, _candidates: object) -> str:
        return "192.168.0.125"

    monkeypatch.setattr(companion_main, "find_reachable_pi", reachable)
    app = FakeApp()

    asyncio.run(companion_main.CompanionApp._on_dashboard_host(app, "node494780.local"))

    assert app.session.endpoints == [("192.168.0.125", 4569)]
    assert app.saved == [("192.168.0.125", 4569)]
    assert app.session.reconnects == 1


def test_invalid_dashboard_host_is_ignored(monkeypatch: object) -> None:
    async def reachable(_host: str, _port: int, _candidates: object) -> str:
        return "192.168.0.125"

    monkeypatch.setattr(companion_main, "find_reachable_pi", reachable)
    app = FakeApp()

    asyncio.run(companion_main.CompanionApp._on_dashboard_host(app, "http://evil.example"))

    assert app.session.endpoints == []
    assert app.session.reconnects == 0


def test_public_reconnect_delegates_to_the_reconnect_sequence(monkeypatch: object) -> None:
    session = CompanionSession(host="192.168.0.125")
    seen: list[bool] = []

    async def fake_reconnect() -> None:
        seen.append(True)

    monkeypatch.setattr(session, "_reconnect", fake_reconnect)
    asyncio.run(session.reconnect())

    assert seen == [True]
