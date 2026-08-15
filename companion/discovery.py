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
import json
import logging
import socket
import struct
from typing import Optional

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
