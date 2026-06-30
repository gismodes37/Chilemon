# Tasks: feat-rt-node-monitor — Real-Time Connected Node Table

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~300 (174 feature + 125 tests) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | ask-on-risk (auto-forecast → Low risk → single PR) |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Low

## Phase 1: AMI Command Execution

- [ ] 1.1 Add `async def command(cmd, timeout=10.0) -> list[str]` method to `AMIClient` in `ami_client.py` — send `Action: Command\r\nCommand: <cmd>\r\n\r\n`, collect `Output:` lines until `--END COMMAND--`
- [ ] 1.2 Write unit tests for `AMIClient.command()` — verify Action format sent, response lines collected, socket error returns empty list

## Phase 2: NodeMonitorService Core

- [ ] 2.1 Create `NodeMonitorService` class in `server.py` with `__init__(ami, asl_node)`, `set_peers_accessor()`, `set_broadcast_fn()`, `start()`, `stop()`
- [ ] 2.2 Implement `_parse_lstat(lines: list[str]) -> list[dict]` — parse `rpt lstats` tabular output, map direction chars (`>`/`<>`/`<` → TX/both/RX), handle header line and irregular whitespace
- [ ] 2.3 Implement `_poll_loop()` — 5s interval, lazy-sampled (skip when `len(peers)==0`), call `ami.command("rpt lstats <node>")`, diff against previous snapshot, broadcast on change
- [ ] 2.4 Wire `NodeMonitorService` lifecycle into main server — start on `RT_NODE_MONITOR_ENABLED` (default `true`), stop on shutdown
- [ ] 2.5 Write unit tests for `_parse_lstats()` with fixtures (3 connected nodes, header-only/empty, irregular whitespace)
- [ ] 2.6 Write integration tests for `NodeMonitorService` — poll cycle, diff detection (same/different snapshot), no-broadcast when unchanged, initial broadcast on first poll

## Phase 3: Dashboard Widget

- [ ] 3.1 Add WS message handler for `"rt_nodes"` + `renderRtNodesTable(nodes)` in `dashboard.js` — render Bootstrap table rows, show "No connected nodes" when empty, hide card on WS disconnect
- [ ] 3.2 Add Bootstrap card with `#rt-nodes-card` and `#rt-nodes-table-body` in `dashboard.php` below existing node table, hidden by default
- [ ] 3.3 Add CSS for `.rt-nodes-table` and `.rt-node-status-*` badges (TX/RX/both) in `dashboard.css`

## Phase 4: Edge Cases & Empty States

- [ ] 4.1 Handle AMI socket error in poll loop — log error, skip broadcast, retry next cycle
- [ ] 4.2 Handle WS disconnect — hide `#rt-nodes-card` when WS closes, re-show on first `rt_nodes` after reconnect
- [ ] 4.3 Handle unknown direction char in `_parse_lstats()` → map to `"unknown"` instead of crashing
