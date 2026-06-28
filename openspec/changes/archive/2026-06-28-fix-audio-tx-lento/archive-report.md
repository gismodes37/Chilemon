# Archive Report: fix-audio-tx-lento

**Fecha**: 2026-06-28
**Estado**: ✅ Completado
**SDD Cycle**: Full (explore → propose → spec → design → tasks → apply → verify → archive)

---

## Resumen

Corrección de velocidad TX lenta (slow motion 3×) en el WebRTC Audio Bridge. El problema ocurría cuando el browser no soportaba `AudioContext({sampleRate:16000})` y caía a 48kHz por defecto, mientras el servidor asumía 16kHz incondicionalmente → producía 64ms de ulaw con solo ~21ms de audio real.

**Solución**: Dual-layer fix:
1. **Browser-side** (capas 1 y 2): CIC downsample running-sum en `onaudioprocess` + envío de `"rate"` real en cada mensaje `audio_tx`
2. **Server-side** (capa 3): Resample lineal condicional con `linear_resample_float32()` como safety net cuando `rate != 16000`

---

## Archivos Modificados

| Archivo | Acción | Descripción |
|---------|--------|-------------|
| `public/assets/js/ptt-widget.js` | Modificado | Se agregó `_downsampleCIC()`, se modificó firma de `_sendAudioChunk` para incluir `rate`, se actualizó `onaudioprocess` con detección de sample rate |
| `app/Services/WebRTCBridge/server.py` | Modificado | Se agregó `linear_resample_float32()`, se actualizó handler `audio_tx` con validación de rate + resample fallback + warning log |

---

## Decisiones Técnicas Clave

| Decisión | Opción Elegida | Alternativa Rechazada |
|----------|---------------|----------------------|
| Downsample browser-side | CIC stage-1 running-sum (O(n), ~3 sumas + 1 div por callback) | Decimación directa (aliasing audible) o FIR filter (>50 taps, pesado) |
| Resample server-side | `linear_resample_float32()` puro Python con `struct` (~10 LOC) | `scipy.signal.resample` (~15MB dependencia) o polyphase filter bank (overkill para safety net) |
| Envío de rate | Campo `"rate"` en **cada** `audio_tx` (stateless, robusto ante reconexiones) | Rate solo en primer mensaje (complejidad de estado, frágil) |
| Default rate | `16000` (backward compatible con mensajes sin campo rate) | — |

---

## Resultado de Verificación

**✅ ALL TASKS PASS — 0 critical, 0 warnings, 0 suggestions**

| Tarea | Descripción | Resultado |
|-------|-------------|-----------|
| T1 | `_downsampleCIC()` en ptt-widget.js | ✅ PASS |
| T2 | Modificar `onaudioprocess` en ptt-widget.js | ✅ PASS |
| T3 | Campo `rate` en mensaje `audio_tx` | ✅ PASS |
| T4 | `linear_resample_float32()` en server.py | ✅ PASS |
| T5 | Modificar handler `audio_tx` + resample fallback | ✅ PASS |

---

## Especificaciones Actualizadas

| Spec | Acción | Detalle |
|------|--------|---------|
| `openspec/specs/webrtc-bridge/spec.md` — BRIDGE-AUDIO-02 | MODIFICADO | Reemplazado "Audio Relay (WS → IAX2)" por "Rate-Aware Audio TX (WS → IAX2)" con 4 escenarios (rate coincide, rate mismatch, server fallback, múltiples rates) |
| `openspec/specs/webrtc-bridge/spec.md` — BRIDGE-AUDIO-03 | AGREGADO | Nuevo requirement "Browser-Side Sample Rate Detection" con 3 escenarios (16kHz directo, 48kHz downsample exitoso, 48kHz downsample falla) |

Todos los demás requirements del spec baseline permanecen sin cambios.

---

## Contenido del Archivo

```
openspec/changes/archive/2026-06-28-fix-audio-tx-lento/
├── archive-report.md    ← Este archivo
├── apply-progress.md
├── design.md
├── explore.md
├── proposal.md
├── spec.md
├── tasks.md
└── verify-report.md
```

---

## Limitaciones Conocidas

- **44.1kHz no soportado explícitamente**: Hardware >99% usa 48kHz. Si un browser entrega 44100 Hz, CIC downsample produce ~14700 Hz y el server aplica doble resample vía fallback. Audio correcto pero con procesamiento extra.
- **ScriptProcessorNode aún deprecado**: El callback `onaudioprocess` corre en el hilo principal. Migración a `AudioWorklet` postergada a v0.6.x.
- **WebSocket aún usa hex encoding**: Overhead de ~50% vs ArrayBuffer binario. Se aborda en cambio separado.
- **Anti-aliasing en `resample_16k_to_8k()`**: La decimación simple en `audio.py` no tiene filtro anti-aliasing. Impacto menor para voz humana. Fuera de scope de este cambio.

---

## Artefactos Relacionados (Engram)

- `sdd/fix-audio-tx-lento/proposal` — Propuesta con definición de scope y enfoque
- `sdd/fix-audio-tx-lento/spec` — Delta spec con requirements ADDED y MODIFIED
- `sdd/fix-audio-tx-lento/design` — Diseño técnico con architecture decisions y data flow
- `sdd/fix-audio-tx-lento/tasks` — Desglose en 5 tareas de implementación
- `sdd/fix-audio-tx-lento/verify-report` — Reporte de verificación con 0 issues
- `sdd/fix-audio-tx-lento/archive-report` — Este reporte

---

## SDD Cycle Complete

El cambio fue completamente planificado, implementado, verificado y archivado. Listo para el próximo cambio.
