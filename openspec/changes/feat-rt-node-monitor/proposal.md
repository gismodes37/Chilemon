# Proposal: feat-rt-node-monitor — Real-Time Connected Node Table

## Intent

El dashboard muestra nodos de la base de datos local, no los nodos **actualmente conectados** al repetidor. Operadores necesitan ver en vivo quién está conectado, su IP, modo, dirección y tiempo conectado — funcionalidad estándar que supermon-ng resuelve con `rpt lstats`.

## Scope

### In Scope
- Agregar `command()` en `AMIClient` para `Action: Command` (CLI de Asterisk)
- Background task en `server.py` que ejecuta `rpt lstats <ASL_NODE>` cada 5s cuando hay WS peers
- Parser de output `rpt lstats` (IP, dirección, modo, estado, tiempo conectado)
- Data diffing contra estado anterior — broadcast solo en cambios
- Nuevo tipo de mensaje WS `rt_nodes` con payload de nodos conectados
- Widget Bootstrap en dashboard que recibe y muestra la tabla en vivo

### Out of Scope
- Historial de conexiones (solo estado actual)
- Control PTT desde la tabla de nodos en vivo (futuro)
- Monitoreo de nodos remotos vía `rpt xnode`
- Persistencia en SQLite de los datos en vivo (solo en memoria)

## Capabilities

### New Capabilities
- `rt-node-monitor`: Monitoreo en tiempo real de nodos conectados vía AMI `Command(rpt lstats)` + WebSocket

### Modified Capabilities
- `ami-integration`: Se agrega capacidad de ejecutar `Action: Command` (comandos CLI de Asterisk)
- `webrtc-bridge`: Se extiende el protocolo WS con el tipo `rt_nodes`

## Approach

1. **AMIClient.command(cmd)** — nuevo método que envía `Action: Command\r\nCommand: <cmd>\r\n\r\n` y lee respuesta con líneas `Output:`
2. **NodeMonitorService** — clase async en `server.py` con `_poll_loop()` cada 5s, ejecuta `rpt lstats <node>` via AMIClient, parsea output tabular, mantiene snapshot anterior, compara con `dataclass` equality
3. **Broadcast** — mismo patrón que `_broadcast_status()`: JSON `{"type":"rt_nodes","nodes":[...]}`
4. **Lazy sampling** — el loop solo corre mientras `len(self._ws_peers) > 0`
5. **Dashboard widget** — nuevo JS escucha `rt_nodes` en WebSocket y renderiza tabla en carta Bootstrap debajo del nodo table existente

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Services/WebRTCBridge/ami_client.py` | Modified | +`command()` method |
| `app/Services/WebRTCBridge/server.py` | Modified | +NodeMonitorService, scheduling |
| `public/assets/js/dashboard.js` | Modified | +WS handler for `rt_nodes` |
| `public/views/dashboard.php` | Modified | +card HTML for live nodes |
| `public/assets/css/dashboard.css` | Modified | +styles for live node card |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| AMI Command bloqueante (socket compartido) | Med | Usar cola async para no bloquear monitor_events |
| rpt lstats output cambia entre versiones ASL | Bajo | Parser tolerante con split por whitespace + validación numérica |
| Broadcast frecuente satura WS peers | Bajo | Data diffing evita broadcast si no hay cambios |

## Rollback Plan

Revertir cambios en `ami_client.py`, `server.py` y dashboard files. La variable de entorno `RT_NODE_MONITOR_ENABLED=false` desactiva el feature sin cambiar código.

## Dependencies

- ASL3 con `rpt lstats` soportado (comando CLI estándar de app_rpt)
- AMI ya conectado (infraestructura existente)

## Success Criteria

- [ ] `rpt lstats` output se parsea correctamente en objetos node
- [ ] Nodos conectados aparecen en dashboard dentro de 5s de conectarse
- [ ] Data diffing evita broadcast cuando no hay cambios
- [ ] Widget se limpia cuando último WS peer se desconecta
