# Design: feat-rt-node-monitor — Real-Time Connected Node Table

## Technical Approach

El daemon Python `server.py` ejecuta `rpt lstats <ASL_NODE>` vía AMI `Action: Command` cada 5s mientras haya peers WebSocket conectados. El output se parsea, se compara contra el snapshot anterior (data diffing), y solo se hace broadcast via WS cuando hay cambios. El dashboard recibe mensajes `rt_nodes` y renderiza una tabla Bootstrap debajo del nodo table existente.

## Architecture Decisions

| Opción | Tradeoff | Decisión |
|--------|----------|----------|
| Polling 5s vs SSE | SSE requiere worker Apache permanente; polling sobre socket AMI ya abierto es más eficiente en RPi | Polling 5s |
| Data diffing en server vs client | Server-side evita que cada cliente replique lógica; broadcast reducido | Server-side en `NodeMonitorService` |
| `Action: Command` vs `rpt show nodes` | `rpt lstats` da IP + dirección + tiempo conectado exacto; `rpt show nodes` no da conectado | `rpt lstats` |
| Lazy sampling (solo con peers) vs siempre | Sin peers el polling es trabajo muerto; ahorra CPU en RPi | Solo con `len(ws_peers) > 0` |

## Data Flow

```
server.py (NodeMonitorService)
  │
  ├─ cada 5s → AMIClient.command("rpt lstats <node>")
  │               │
  │               └─ AMI socket → Asterisk CLI
  │
  ├─ _parse_lstats() → list[dict]
  │
  ├─ diff vs _previous_nodes
  │     │
  │     └─ si cambió → _broadcast_rt_nodes()
  │                       │
  │                       └─ json {"type":"rt_nodes","nodes":[...]}
  │                          → WS peers
  │
dashboard.js ← WS handler "rt_nodes"
  │
  └─ renderRtNodesTable(nodes) → #rt-nodes-table-body
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Services/WebRTCBridge/ami_client.py` | Modify | +`command(cmd)` method — envía `Action: Command`, lee output `Output:` lines hasta `--END COMMAND--` |
| `app/Services/WebRTCBridge/server.py` | Modify | +`NodeMonitorService` class; +`_broadcast_rt_nodes()`; start/stop in lifecycle |
| `public/assets/js/dashboard.js` | Modify | +WS handler for `rt_nodes` message type; +`renderRtNodesTable()` |
| `public/views/dashboard.php` | Modify | +card HTML con `#rt-nodes-card` y `#rt-nodes-table-body` |
| `public/assets/css/dashboard.css` | Modify | +styles para `.rt-nodes-table` y `.rt-node-status-*` badges |

## Interfaces / Contracts

### AMIClient.command()

```python
async def command(self, cmd: str, timeout: float = 10.0) -> list[str]:
    """Execute CLI command via Action: Command.

    Returns output lines (without 'Output: ' prefix).
    Stops at --END COMMAND-- or CommandComplete event.
    """
```

### NodeMonitorService

```python
class NodeMonitorService:
    def __init__(self, ami: AMIClient, asl_node: str) -> None: ...
    def set_peers_accessor(self, fn: Callable[[], set]) -> None: ...
    def set_broadcast_fn(self, fn: Callable[[dict], Awaitable[None]]) -> None: ...
    async def start(self) -> None: ...
    async def stop(self) -> None: ...

    async def _poll_loop(self) -> None: ...   # 5s sleep, lazy-sampled
    def _parse_lstats(self, lines: list[str]) -> list[dict]: ...
```

### WS Message (new type)

```json
{
  "type": "rt_nodes",
  "nodes": [
    {
      "node_id": "12345",
      "ip": "192.168.1.1",
      "mode": "IAX",
      "direction": "TX",
      "connected_duration": "00:05:23",
      "state": "RX"
    }
  ]
}
```

### Direction Mapping

| rpt lstats Dir | WS direction | Description |
|----------------|-------------|-------------|
| `>` | `TX` | Transmitting audio to the network |
| `<` | `RX` | Receiving audio from the network |
| `<>` | `both` | Full duplex |

El parser `_parse_lstats()` mapea el caracter crudo a estos valores semanticos. Si aparece un caracter inesperado se usa `"unknown"`.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `_parse_lstats()` con output real y edge cases (header, vacío, nodos no numéricos) | Test con fixtures static de `rpt lstats` output |
| Unit | `AMIClient.command()` — verifica formato de Action enviado | Mock `_send`/`_read_message_raw` |
| Integration | NodeMonitorService — polling, diff detection, broadcast conditional | server.py con AMIClient mockeado |
| E2E | Dashboard JS handler recibe rt_nodes y renderiza tabla | Test manual o con WS mock |

## Migration / Rollout

No migration required. La variable `RT_NODE_MONITOR_ENABLED` (default: `true`) controla si el NodeMonitorService arranca. Para desactivar sin revertir cambios, setear `RT_NODE_MONITOR_ENABLED=false` en el environment. Para rollout gradual, deployar con `false` y habilitar después de validación.

El widget del dashboard se oculta automáticamente si no hay WS conectado, así que no hay cambio visual hasta que la feature esté activa y haya datos.

## Open Questions

- `node` field: se usa `node_id` en vez de `node` para consistencia con el spec y evitar confusión con el nombre del campo. El spec debería reflejar este nombre.
- `connected_duration` vs `connected_since`: `rpt lstats` devuelve duración conectado, no timestamp de conexión. En un dashboard en vivo la duración es más útil (ej: "05:23" = 5 min 23 seg). Si se necesita timestamp absoluto, habría que calcularlo contra el reloj del server. Por ahora se usa `connected_duration`.
- `RT_NODE_MONITOR_ENABLED`: esta variable de entorno (`false` por defecto para rollout seguro) no está en el proposal — agregarla ahí como mecanismo de feature flag.
- WS disconnected state: `#rt-nodes-card` arranca oculta y se muestra solo cuando llega el primer `rt_nodes` mensaje. Si el WS se desconecta, la card se oculta automáticamente.
- Empty state: cuando `nodes` llega vacío se muestra "No hay nodos conectados" en el cuerpo de la tabla.

## Edge Cases / States

| Estado | Comportamiento |
|--------|---------------|
| Sin WS conectado | Card oculta (display:none) |
| Primer `rt_nodes` recibido | Card visible, tabla renderizada |
| Nodos vacío | "No hay nodos conectados" |
| Nodos con datos | Tabla con filas y badges de estado |
| AMI error (socket) | NodeMonitorService omite broadcast, reintenta en el próximo ciclo |
