# Propuesta: fix-audio-tx-lento — Corrección de velocidad TX en WebRTC Bridge

## Intención

El audio del micrófono se reproduce a cámara lenta (3×) en la radio cuando el browser no soporta `AudioContext({sampleRate:16000})` y cae a 48kHz. El servidor asume 16kHz y aplica decimación simple → produce 64ms de ulaw a 8kHz con solo ~21ms de audio real. Operadores de radio reciben audio ininteligible.

## Scope

### In Scope
- Downsample browser-side 48kHz→16kHz en `onaudioprocess` con filtro anti-aliasing básico (running-sum CIC)
- Envío de `"rate": actualRate` en cada `audio_tx` como safety net
- Resample server-side con interpolación lineal cuando `rate != 16000`
- Server log warning cuando fallback se activa
- Verificación con tono de 1kHz (frecuencia percibida correcta)

### Out of Scope
- **AudioWorklet**: Migración postergada a v0.6.x (worklet .js + fallback)
- **WebSocket binario**: Hex→ArrayBuffer no resuelve el bug, se aborda aparte
- **Anti-aliasing en `resample_16k_to_8k()`**: Impacto mínimo en voz humana
- **Jitter buffer / rate limiting**: Robustez del pipeline fuera de este fix

## Capacidades

> Contrato con sdd-spec basado en `openspec/specs/webrtc-bridge/spec.md`.

### Nuevas Capacidades
None.

### Capacidades Modificadas
- `webrtc-bridge`: **BRIDGE-AUDIO-02** se actualiza — el servidor DEBE verificar sample rate real y aplicar resample si `rate != 16000` antes de `tx_process()`.

## Enfoque

**Opción B (downsample browser)** como fix principal + **Opción D (rate + resample server-side)** como safety net:

1. `ptt-widget.js`: leer `this._txCtx.sampleRate` real post-creación
2. `onaudioprocess`: si `sampleRate !== 16000`, downsample vía CIC stage-1 (running sum, decimate-by-3 para 48kHz→16kHz)
3. Enviar `"rate": this._txCtx.sampleRate` en cada `audio_tx`
4. `server.py`: si `rate != 16000`, aplicar `linear_resample_float32()` antes de `tx_process()`
5. Loggear warning en servidor al activar fallback

## Áreas Afectadas

| Área | Impacto | Descripción |
|------|---------|-------------|
| `public/assets/js/ptt-widget.js` | Modificado | Downsample + envío de `rate` |
| `app/Services/WebRTCBridge/server.py` | Modificado | Rate check + resample condicional |
| `app/Services/WebRTCBridge/audio.py` | Sin cambios | Sigue recibiendo float32 16kHz |
| `openspec/specs/webrtc-bridge/spec.md` | Modificado | BRIDGE-AUDIO-02 rate-aware |

## Riesgos

| Riesgo | Probabilidad | Mitigación |
|--------|-------------|------------|
| Aliasing por downsample sin filtro adecuado | Media | CIC running-sum (no decimación directa). Validar con tono 1kHz. |
| Interpolación lineal server-side distorsiona voz | Baja | Solo fallback; para voz humana es aceptable |
| Latencia extra en browser | Baja | CIC es O(n), ~0.1ms por callback de 1024 samples |
| ScriptProcessor bloquea UI | Baja | Ya existe; downsample agrega ~0.1ms, perfil invariante |

## Rollback

Revertir commits de `ptt-widget.js` y `server.py`. El pipeline anterior sigue funcionando (con el bug). Opcional: feature flag `tx_downsample_enabled` en `config/local.php` (default: true).

## Dependencias

Ninguna externa. TODO nativo (JS Math + Python struct/audioop). Ambiente Docker para pruebas end-to-end. Generador de tonos (sox/Audacity) para verificación.

## Criterios de Éxito

- [ ] Tono 1kHz generado en browser → grabado en servidor → frecuencia medida = 1kHz ± 1%
- [ ] Voz humana → radio → velocidad normal (no slow motion)
- [ ] Firefox < 98 / Safari < 16.4: downsample browser activo, audio correcto
- [ ] Chrome 60+: downsample NO activo (pasa directo 16kHz), audio correcto
- [ ] Sin warnings de `rate != 16000` cuando browser soporta 16kHz
- [ ] Server log muestra warning cuando fallback se activa
