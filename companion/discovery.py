#!/usr/bin/env python3
"""
chilemon/companion/discovery.py -- UDP auto-discovery for ChileMon Companion.

Broadcasts a discovery packet on the local network to find the ChileMon Pi.
When the Pi responds, the companion knows the Pi's IP and can connect.

Protocol:
  1. Companion sends UDP broadcast: {"action": "discover", "version": "1.0"}
  2. Pi responds: {"action": "here", "ip": "192.168.0.116", "port": 4569}
  3. Companion saves Pi IP and connects via IAX2

Usage:
    from companion.discovery import DiscoveryClient
    client = DiscoveryClient()
    pi_info = await client.discover(timeout=5.0)
    if pi_info:
        print(f"Found Pi at {pi_info['ip']}:{pi_info['port']}")
"""

from __future__ import annotations

import asyncio
import ipaddress
import json
import logging
import socket
import struct
from typing import Awaitable, Callable, Iterable, Optional

logger = logging.getLogger("companion.discovery")

# Discovery constants
DISCOVERY_PORT = 9094  # UDP port for discovery (matches ChileMon Pi)
DISCOVERY_MESSAGE = json.dumps({
    "action": "discover",
    "version": "1.0",
    "app": "chilemon-companion",
}).encode("utf-8")

# Broadcast address for common home networks
BROADCAST_ADDRESSES = [
    "255.255.255.255",  # Limited broadcast
    "192.168.0.255",    # Common home network
    "192.168.1.255",    # Alternative home network
    "10.0.0.255",       # Class A private
]

# IAX2 endpoint verification. A hostname is not enough: on a multi-homed node
# DNS may point at a secondary interface while Asterisk answers from the primary
# one. Only the address that actually sends a PONG is a usable IAX2 endpoint.
IAX_TYPE_IAX = 0x06
IAX_CMD_REGREQ = 0x0D
# Asterisk ignores PING/LAGRQ from a source it does not know, but always
# answers a REGREQ. These are the control replies that prove IAX2 is alive.
IAX_PROBE_REPLIES = {0x06, 0x08, 0x0E, 0x0F, 0x10, 0x28}  # REJECT..CALLTOKEN
PROBE_CALLNO = 0x12AB  # Dedicated call number; never shared with user calls.
PING_TIMEOUT = 0.7

Resolver = Callable[[str], Awaitable[list[str]]]
Probe = Callable[[str, int, float], Awaitable[Optional[str]]]


def build_iax2_probe_frame(ts: int = 0) -> bytes:
    """Build a 12-byte full IAX2 REGREQ probe (Asterisk F=1 convention)."""
    return struct.pack(
        "!HHIBBBB",
        0x8000 | (PROBE_CALLNO & 0x7FFF),
        0,
        ts & 0xFFFFFFFF,
        0,
        0,
        IAX_TYPE_IAX,
        IAX_CMD_REGREQ,
    )


def is_iax2_probe_reply(data: bytes) -> bool:
    """True for a full IAX control reply addressed to our probe call number."""
    if len(data) < 12:
        return False
    first, dest, _ts, _oseq, _iseq, frametype, subclass = struct.unpack(
        "!HHIBBBB", data[:12]
    )
    return (
        bool(first & 0x8000)
        and (dest & 0x7FFF) == PROBE_CALLNO
        and frametype == IAX_TYPE_IAX
        and (subclass & 0x7F) in IAX_PROBE_REPLIES
    )


def _usable_address(value: str) -> bool:
    """Reject loopback/unspecified addresses; a remote Pi cannot live there."""
    try:
        address = ipaddress.ip_address(value.strip("[]"))
    except ValueError:
        return False
    return not (address.is_loopback or address.is_unspecified)


async def resolve_host_addresses(
    host: str, resolver: Optional[Resolver] = None
) -> list[str]:
    """Resolve a host to unique, routable IPv4 addresses, preserving order."""
    name = str(host or "").strip().strip("[]")
    if not name:
        return []

    if resolver is not None:
        candidates = await resolver(name)
    else:
        try:
            infos = await asyncio.get_running_loop().getaddrinfo(
                name, None, family=socket.AF_INET, type=socket.SOCK_DGRAM
            )
        except (OSError, UnicodeError) as exc:
            logger.debug("Could not resolve %s: %s", name, exc)
            return []
        candidates = [info[4][0] for info in infos]

    result: list[str] = []
    for candidate in candidates:
        address = str(candidate).strip()
        if _usable_address(address) and address not in result:
            result.append(address)
    return result


async def probe_iax2_endpoint(
    host: str, port: int = 4569, timeout: float = PING_TIMEOUT
) -> Optional[str]:
    """Send an IAX2 REGREQ probe and return the IP that answers it.

    Returns None when nothing answers. The responding address is returned
    instead of the queried one so a multi-homed Asterisk cannot be reached
    through the interface DNS happens to advertise.
    """
    targets = await resolve_host_addresses(host)
    if not targets:
        return None

    loop = asyncio.get_running_loop()
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.setblocking(False)
    frame = build_iax2_probe_frame()
    try:
        for target in targets:
            sock.sendto(frame, (target, port))
        deadline = loop.time() + timeout
        while True:
            remaining = deadline - loop.time()
            if remaining <= 0:
                return None
            try:
                data, addr = await asyncio.wait_for(
                    loop.sock_recvfrom(sock, 256), remaining
                )
            except asyncio.TimeoutError:
                return None
            if is_iax2_probe_reply(data) and addr[0] in targets:
                logger.debug("IAX2 probe reply from %s:%d", addr[0], addr[1])
                return addr[0]
    except OSError as exc:
        logger.debug("IAX2 probe failed for %s: %s", host, exc)
        return None
    finally:
        sock.close()


async def find_reachable_pi(
    configured_host: str,
    port: int = 4569,
    extra_hosts: Iterable[str] = (),
    *,
    resolve: Optional[Resolver] = None,
    probe: Optional[Probe] = None,
    timeout: float = PING_TIMEOUT,
) -> Optional[str]:
    """Return the first candidate that answers an IAX2 PING, or None.

    ``configured_host`` is tried first, then the hostnames supplied by the
    dashboard. A candidate with no IAX2 reply is never returned: that
    false-positive is exactly what kept a stale IP alive after a DHCP change.
    """
    probe_fn: Probe = probe or probe_iax2_endpoint
    seen_hosts: set[str] = set()
    seen_addresses: set[str] = set()

    for candidate in [configured_host, *extra_hosts]:
        name = str(candidate or "").strip()
        if not name or name.lower() in seen_hosts:
            continue
        seen_hosts.add(name.lower())

        for address in await resolve_host_addresses(name, resolver=resolve):
            if address in seen_addresses:
                continue
            seen_addresses.add(address)
            responder = await probe_fn(address, port, timeout)
            if responder:
                logger.info(
                    "Reachable IAX2 endpoint %s (candidate %s)", responder, name
                )
                return responder

    return None


def detect_local_ipv4() -> Optional[str]:
    """Return the local address used for outbound traffic, without sending data."""
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        # TEST-NET-1: a documentation address picked only to trigger routing.
        sock.connect(("192.0.2.1", 9))
        return str(sock.getsockname()[0])
    except OSError as exc:
        logger.debug("Could not detect local IP: %s", exc)
        return None
    finally:
        sock.close()


async def probe_local_subnet(
    port: int = 4569,
    prefix: int = 24,
    timeout: float = PING_TIMEOUT,
    *,
    local_ip: Optional[str] = None,
    probe: Optional[Probe] = None,
) -> Optional[str]:
    """Last-resort discovery: probe the local subnet for an IAX2 responder.

    The subnet comes from the PC's own DHCP address, never from a pinned Pi IP.
    Probes run in small concurrent batches so a /24 completes in a few seconds.
    """
    address = local_ip or detect_local_ipv4()
    if not address:
        return None

    try:
        network = ipaddress.ip_network(f"{address}/{prefix}", strict=False)
    except ValueError as exc:
        logger.warning("Invalid discovery subnet prefix %s: %s", prefix, exc)
        return None

    probe_fn: Probe = probe or probe_iax2_endpoint
    targets = [str(host) for host in network.hosts() if str(host) != address]
    for start in range(0, len(targets), 64):
        batch = targets[start:start + 64]
        results = await asyncio.gather(
            *(probe_fn(target, port, timeout) for target in batch),
            return_exceptions=True,
        )
        for result in results:
            if isinstance(result, str) and result:
                logger.info("Found ChileMon Pi at %s", result)
                return result
    return None


class DiscoveryClient:
    """UDP discovery client for finding ChileMon Pi on the local network."""
    
    def __init__(self, port: int = DISCOVERY_PORT) -> None:
        self._port = port
        self._found_pi: Optional[dict] = None
    
    async def discover(self, timeout: float = 5.0) -> Optional[dict]:
        """Broadcast discovery packet and wait for Pi response.
        
        Parameters
        ----------
        timeout : float
            Seconds to wait for response (default 5.0)
        
        Returns
        -------
        dict or None
            {"ip": "x.x.x.x", "port": 4569} if found, None otherwise
        """
        logger.info("Starting discovery broadcast on port %d...", self._port)
        
        # Create UDP socket
        sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        sock.setblocking(False)
        
        try:
            # Send discovery broadcast to all addresses
            for addr in BROADCAST_ADDRESSES:
                try:
                    sock.sendto(DISCOVERY_MESSAGE, (addr, self._port))
                    logger.debug("Sent discovery to %s:%d", addr, self._port)
                except Exception as e:
                    logger.debug("Failed to send to %s: %s", addr, e)
            
            # Wait for response
            loop = asyncio.get_event_loop()
            deadline = asyncio.get_event_loop().time() + timeout
            
            while asyncio.get_event_loop().time() < deadline:
                try:
                    # Use selector to wait for data with timeout
                    remaining = deadline - asyncio.get_event_loop().time()
                    if remaining <= 0:
                        break
                    
                    # Non-blocking recv with selector
                    import selectors
                    sel = selectors.DefaultSelector()
                    sel.register(sock, selectors.EVENT_READ)
                    events = sel.select(timeout=min(remaining, 0.1))
                    sel.unregister(sock)
                    
                    if events:
                        data, addr = sock.recvfrom(1024)
                        response = json.loads(data.decode("utf-8"))
                        
                        if response.get("action") == "here":
                            pi_info = {
                                "ip": response["ip"],
                                "port": response.get("port", 4569),
                            }
                            logger.info(
                                "Found ChileMon Pi at %s:%d",
                                pi_info["ip"], pi_info["port"]
                            )
                            self._found_pi = pi_info
                            return pi_info
                
                except BlockingIOError:
                    # No data available yet
                    await asyncio.sleep(0.01)
                except json.JSONDecodeError:
                    # Invalid response, skip
                    continue
                except Exception as e:
                    logger.debug("Error receiving: %s", e)
                    continue
            
            logger.warning("Discovery timed out after %.1f seconds", timeout)
            return None
        
        finally:
            sock.close()
    
    def get_last_found(self) -> Optional[dict]:
        """Return the last found Pi info, or None."""
        return self._found_pi


async def discover_pi(timeout: float = 5.0) -> Optional[dict]:
    """Convenience function to discover ChileMon Pi.
    
    Returns {"ip": "x.x.x.x", "port": 4569} or None.
    """
    client = DiscoveryClient()
    return await client.discover(timeout=timeout)


if __name__ == "__main__":
    # Test discovery
    logging.basicConfig(level=logging.DEBUG)
    
    async def main():
        print("Searching for ChileMon Pi...")
        pi = await discover_pi(timeout=10.0)
        if pi:
            print(f"Found! IP: {pi['ip']}, Port: {pi['port']}")
        else:
            print("Pi not found on network")
    
    asyncio.run(main())
