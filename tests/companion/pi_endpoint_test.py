"""Endpoint detection tests for the Windows Companion.

A stale configured address must never be reported as reachable: UDP ``sendto``
does not prove anything. Candidates are resolved and verified with an IAX2
REGREQ probe, and the address that actually replies is the one we use.

Run with: python -m pytest tests/companion/pi_endpoint_test.py -v
"""

from __future__ import annotations

import asyncio
import struct
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from companion import discovery  # noqa: E402


def _probe_reply(dest_callno: int = discovery.PROBE_CALLNO, subclass: int = 0x10) -> bytes:
    return struct.pack(
        "!HHIBBBB",
        0x8001,  # Asterisk full-frame convention
        dest_callno & 0x7FFF,
        1,
        0,
        1,
        0x06,  # IAX_TYPE_IAX
        subclass,  # IAX_CMD_REGREJ by default
    )


def test_probe_frame_is_a_valid_full_iax_control_frame() -> None:
    frame = discovery.build_iax2_probe_frame(ts=0x01020304)
    first, second, ts, oseqno, iseqno, frametype, subclass = struct.unpack(
        "!HHIBBBB", frame
    )

    assert len(frame) == 12
    assert first == 0x8000 | discovery.PROBE_CALLNO
    assert second == 0
    assert ts == 0x01020304
    assert (oseqno, iseqno) == (0, 0)
    assert frametype == 0x06
    assert subclass == 0x0D  # REGREQ: Asterisk answers even for unknown peers


def test_only_addressed_iax_control_replies_count() -> None:
    assert discovery.is_iax2_probe_reply(_probe_reply())
    assert not discovery.is_iax2_probe_reply(b"\x00\x01")  # too short
    echo = bytearray(_probe_reply())
    echo[-1] = 0x0D
    assert not discovery.is_iax2_probe_reply(bytes(echo))  # our own request
    wrong_call = _probe_reply(dest_callno=0x9999)
    assert not discovery.is_iax2_probe_reply(wrong_call)
    wrong_type = bytearray(_probe_reply())
    wrong_type[-2] = 0x07
    assert not discovery.is_iax2_probe_reply(bytes(wrong_type))


class FakeSocket:
    def __init__(self) -> None:
        self.sent: list[tuple[bytes, tuple[str, int]]] = []
        self.closed = False

    def setblocking(self, flag: bool) -> None:
        self.blocking = flag

    def sendto(self, data: bytes, addr: tuple[str, int]) -> None:
        self.sent.append((data, addr))

    def close(self) -> None:
        self.closed = True


async def _resolve_to_secondary_ip(host: str) -> list[str]:
    return ["192.168.0.114"]


def _run_real_probe(responder: str, monkeypatch: object) -> tuple[str | None, FakeSocket]:
    async def scenario() -> tuple[str | None, FakeSocket]:
        loop = asyncio.get_running_loop()
        sock = FakeSocket()

        async def fake_recvfrom(_sock: object, _size: int) -> tuple[bytes, tuple[str, int]]:
            return _probe_reply(), (responder, 4569)

        monkeypatch.setattr(discovery, "resolve_host_addresses", _resolve_to_secondary_ip)
        monkeypatch.setattr(discovery.socket, "socket", lambda *a, **k: sock)
        monkeypatch.setattr(loop, "sock_recvfrom", fake_recvfrom, raising=False)
        result = await discovery.probe_iax2_endpoint("pi.local", 4569, 0.2)
        return result, sock

    return asyncio.run(scenario())


def test_real_probe_accepts_a_reply_from_the_primary_interface(monkeypatch: object) -> None:
    result, sock = _run_real_probe("192.168.0.125", monkeypatch)

    assert result == "192.168.0.125"
    assert sock.sent[0][1] == ("192.168.0.114", 4569)
    assert sock.closed is True


def test_real_probe_rejects_a_reply_from_another_subnet(monkeypatch: object) -> None:
    result, _sock = _run_real_probe("10.0.0.5", monkeypatch)

    assert result is None


def test_stale_configured_host_is_never_reported_as_reachable() -> None:
    async def resolve(host: str) -> list[str]:
        return [host]

    async def probe(host: str, port: int, timeout: float) -> str | None:
        return None

    result = asyncio.run(
        discovery.find_reachable_pi(
            "192.168.0.110", 4569, resolve=resolve, probe=probe
        )
    )

    assert result is None


def test_configured_host_is_used_when_it_answers() -> None:
    async def resolve(host: str) -> list[str]:
        return [host]

    async def probe(host: str, port: int, timeout: float) -> str | None:
        return host

    result = asyncio.run(
        discovery.find_reachable_pi(
            "192.168.0.125", 4569, resolve=resolve, probe=probe
        )
    )

    assert result == "192.168.0.125"


def test_multihome_dns_ip_is_replaced_by_the_address_that_replies() -> None:
    async def resolve(host: str) -> list[str]:
        if host == "node494780.local":
            return ["192.168.0.114"]
        return [host]

    async def probe(host: str, port: int, timeout: float) -> str | None:
        if host == "192.168.0.114":
            return "192.168.0.125"  # Asterisk replies from its primary IP
        return None

    result = asyncio.run(
        discovery.find_reachable_pi(
            "192.168.0.110",
            4569,
            extra_hosts=["node494780.local"],
            resolve=resolve,
            probe=probe,
        )
    )

    assert result == "192.168.0.125"


def test_dashboard_host_is_probed_after_a_stale_config_host() -> None:
    probed: list[str] = []

    async def resolve(host: str) -> list[str]:
        if host == "node494780.local":
            return ["192.168.0.114"]
        return [host]

    async def probe(host: str, port: int, timeout: float) -> str | None:
        probed.append(host)
        if host == "192.168.0.114":
            return "192.168.0.125"
        return None

    result = asyncio.run(
        discovery.find_reachable_pi(
            "192.168.0.110",
            4569,
            extra_hosts=["node494780.local"],
            resolve=resolve,
            probe=probe,
        )
    )

    assert probed == ["192.168.0.110", "192.168.0.114"]
    assert result == "192.168.0.125"


def test_local_subnet_scan_finds_the_pi_without_a_pinned_address() -> None:
    async def probe(host: str, port: int, timeout: float) -> str | None:
        if host == "192.168.0.125":
            return host
        return None

    result = asyncio.run(
        discovery.probe_local_subnet(local_ip="192.168.0.109", probe=probe)
    )

    assert result == "192.168.0.125"


def test_local_subnet_scan_does_not_probe_our_own_address() -> None:
    probed: list[str] = []

    async def probe(host: str, port: int, timeout: float) -> str | None:
        probed.append(host)
        return None

    asyncio.run(
        discovery.probe_local_subnet(local_ip="192.168.0.109", probe=probe)
    )

    assert "192.168.0.109" not in probed
    assert "192.168.0.125" in probed


def test_loopback_and_duplicate_candidates_are_skipped() -> None:
    probed: list[str] = []

    async def resolve(host: str) -> list[str]:
        if host == "node494780.local":
            return ["192.168.0.125", "192.168.0.125", "127.0.0.1"]
        return [host]

    async def probe(host: str, port: int, timeout: float) -> str | None:
        probed.append(host)
        return host

    result = asyncio.run(
        discovery.find_reachable_pi(
            "127.0.0.1",
            4569,
            extra_hosts=["node494780.local", "192.168.0.125"],
            resolve=resolve,
            probe=probe,
        )
    )

    assert result == "192.168.0.125"
    assert probed == ["192.168.0.125"]
