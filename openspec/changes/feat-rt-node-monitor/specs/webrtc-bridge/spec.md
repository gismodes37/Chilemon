# Delta for webrtc-bridge

## ADDED Requirements

### BRIDGE-RTNODES-01: RT Nodes Broadcast Protocol

The WebSocket server MUST support a new message type `rt_nodes` for broadcasting connected node state. The server MUST send `{"type":"rt_nodes","nodes":[...]}` to all connected WS peers. Each node object MUST include `ip` (string), `direction` (string: "TX"|"RX"|"both"), `mode` (string), `status` (string), and `connected_since` (string, ISO 8601 or similar human-readable format).

| Scenario | GIVEN | WHEN | THEN |
|----------|-------|------|------|
| Broadcast to all peers | 2 WS peers are connected | NodeMonitorService produces updated snapshot | Both peers receive `{"type":"rt_nodes","nodes":[...]}` |
| Empty node list | No nodes are connected | NodeMonitorService produces empty snapshot | `{"type":"rt_nodes","nodes":[]}` is broadcast |
| No change suppressed | Same snapshot as previous broadcast | NodeMonitorService detects no diff | No message is sent over WS |
