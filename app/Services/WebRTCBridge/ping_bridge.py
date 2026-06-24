#!/usr/bin/env python3
"""Send IAX2 PING to bridge and wait for PONG."""
import socket
import struct
import sys

sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
sock.settimeout(3)

# PING: F=1, src=1, dst=1, ts=0, oseq=0, iseq=0, type=IAX(6), sub=PING(1)
ping = struct.pack('!HHIBBBB', 0x8001, 0x0001, 0, 0, 0, 6, 1)
sock.sendto(ping, ('127.0.0.1', 9092))
print(f'Sent PING: {ping.hex()}', flush=True)

try:
    data, addr = sock.recvfrom(1024)
    print(f'GOT {len(data)}B from {addr[0]}:{addr[1]} hex={data.hex()[:40]}', flush=True)
    if len(data) >= 12:
        src = struct.unpack('!H', data[:2])[0] & 0x7FFF
        dst = struct.unpack('!H', data[2:4])[0] & 0x7FFF
        ftype = data[10]
        sub = data[11] & 0x7F
        print(f'  src={src} dst={dst} type=0x{ftype:02X} sub=0x{sub:02X}', flush=True)
        if ftype == 6 and sub == 2:  # IAX type, PONG subclass
            print('  => PONG received!', flush=True)
        else:
            print(f'  => Not a PONG', flush=True)
except socket.timeout:
    print('No response (timeout)', flush=True)

sock.close()
