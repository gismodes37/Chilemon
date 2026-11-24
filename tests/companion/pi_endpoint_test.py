"""Endpoint detection tests for the Windows Companion.

A stale configured address must never be reported as reachable: UDP ``sendto``
does not prove anything. Candidates are resolved and verified with an IAX2
PING/PONG exchange, and the address that actually replies is the one we use.

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


def _pong(src_callno: int = 1, dest_callno: int = 0) -> bytes:
    return struct.pack(
        "!HHIBBBB",
        0x8000 | (src_callno & 0x7FFF),
        dest_callno & 0x7FFF,
        1,
        0,
        0,
        0x06,  # IAX_TYPE_IAX
        0x03,  # IAX_CMD_PONG
    )


def test_ping_frame_is_a_valid_full_iax_control_frame() -> None:
    frame = discovery.build_iax2_ping_frame(ts=0x01020304)
    first, second, ts, oseqno, iseqno, frametype, subclass = struct.unpack(
        "!HHIBBBB", frame
    )

    assert len(frame) == 12
    assert first == 0x8000 | discovery.PING_CALLNO
    assert second == 0
    assert ts == 0x01020304
    assert (oseqno, iseqno) == (0, 0)
    assert frametype == 0x06
    assert subclass == 0x02


def test_only_full_iax_pong_counts_as_a_reply() -> None:
    assert discovery.is_iax2_pong(_pong())
    assert not discovery.is_iax2_pong(b"\x00\x01")  # too short
    ping = bytearray(_pong())
    ping[-1] = 0x02
    assert not discovery.is_iax2_pong(bytes(ping))  # echo of our own PING
    wrong_type = bytearray(_pong())
    wrong_type[-2] = 0x07
    assert not discovery.is_iax2_pong(bytes(wrong_type))


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
