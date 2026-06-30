# RT Node Monitor — Full Spec

**Domain**: `rt-node-monitor`
**Change**: `feat-rt-node-monitor`
**Type**: Full spec (new domain — no baseline)

## Purpose

Monitoreo en tiempo real de nodos conectados al repetidor ASL. El sistema consulta `rpt lstats` vía AMI Command, parsea el output tabular, y lo difunde a peers WebSocket cuando hay cambios. Solo corre mientras haya peers conectados.

## Requirements

### RTNODE-01: AMI Command Execution

The system MUST execute Asterisk CLI commands via `Action: Command`. It MUST send `Action: Command\r\nCommand: <cmd>\r\n\r\n` and collect `Output:` lines from the response.

| Scenario | GIVEN | WHEN | THEN |
|----------|-------|------|------|
| Command succeeds | AMI client is logged in | `command("rpt lstats 494780")` is called | All `Output:` lines are returned |
| AMI socket closed mid-command | AMI client is logged in | socket closes while collecting response | The method returns empty list and logs error |

### RTNODE-02: Output Parsing

The system MUST parse `rpt lstats` tabular output into structured node objects with fields: `ip`, `direction` (TX/RX/both), `mode`, `status`, `connected_since`.

| Scenario | GIVEN | WHEN | THEN |
|----------|-------|------|------|
| Standard output | `rpt lstats` returns 3 connected nodes | parser receives the output | Returns 3 Node objects with all fields populated |
| No connected nodes | `rpt lstats` returns only the header line | parser receives it | Returns empty list |
| Extra whitespace in output | Output has irregular spacing | parser receives it | Parses correctly, tolerant of whitespace variance |

### RTNODE-03: Polling Schedule

The system MUST poll `rpt lstats` every 5 seconds while WS peers are connected. It MUST NOT poll when no peers are connected.

| Scenario | GIVEN | WHEN | THEN |
|----------|-------|------|------|
| Polling active | 1+ WS peers are connected | 5 seconds elapse | `rpt lstats` is executed via AMI |
| No peers stops polling | No WS peers are connected | polling interval fires | The loop is skipped, no AMI command sent |
| Start on first peer | No peers, polling stopped | first WS peer connects | Polling starts within 5 seconds |

### RTNODE-04: Data Diffing

The system MUST compare the current node snapshot against the previous one. Broadcast MUST only occur when the snapshot changes. The initial snapshot after first poll MUST always be broadcast.

| Scenario | GIVEN | WHEN | THEN |
|----------|-------|------|------|
| Initial broadcast | No previous snapshot exists | first poll completes | Snapshot is broadcast to all WS peers |
| Change detected | Previous snapshot has 2 nodes | poll returns 3 nodes (1 new) | New snapshot is broadcast |
| No changes | Previous snapshot has 2 nodes | poll returns same 2 nodes | No broadcast occurs |

### RTNODE-05: WS Message Type

The system MUST broadcast JSON `{"type":"rt_nodes","nodes":[...]}` to all connected WS peers. Each node entry MUST include `ip`, `direction`, `mode`, `status`, and `connected_since`.

| Scenario | GIVEN | WHEN | THEN |
|----------|-------|------|------|
| Broadcast change | 2 WS peers connected | snapshot changes | Both peers receive `rt_nodes` message |
| Empty node list | No nodes connected | snapshot returns empty | `{"type":"rt_nodes","nodes":[]}` is broadcast |

### RTNODE-06: Browser Widget

The dashboard MUST render a Bootstrap card with a live node table on receiving `rt_nodes`. When the node list is empty, the UI MUST show "No connected nodes".

| Scenario | GIVEN | WHEN | THEN |
|----------|-------|------|------|
| Nodes received | Dashboard is open | WS message `rt_nodes` with 3 nodes | Table renders with 3 rows |
| Empty received | Dashboard shows 3 nodes | WS message `rt_nodes` with `[]` | Table clears, shows "No connected nodes" |

### RTNODE-07: Lazy Lifecycle

The system MUST clean up all polling state (timer, cached snapshot) when the last WS peer disconnects. It MUST NOT interfere with other WebSocket features.

| Scenario | GIVEN | WHEN | THEN |
|----------|-------|------|------|
| Last peer leaves | 1 WS peer connected, polling active | peer disconnects | Polling stops, snapshot is cleared |
| Clean slate on reconnect | No peers, polling stopped | new peer connects | Fresh snapshot is built from scratch |
