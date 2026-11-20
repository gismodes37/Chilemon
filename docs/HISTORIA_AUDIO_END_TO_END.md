# 🇨🇱 ChileMon — Historia del camino de audio extremo a extremo

## 1. Objetivo

Lograr audio bidireccional (TX/RX) entre la PC —mediante el companion Python con micrófono y parlantes— y la Raspberry Pi con ASL3:

- Nodo local: `61916` (pseudo nodo, `duplex=0`)
- Repetidor remoto: `54614` (VHF)
- Transporte: IAX2 UDP sobre el puerto `4569`

Este documento reconstruye cómo se llegó a señal de entrada y salida, qué fallas bloquearon el camino y qué decisiones hubo que tomar para salvarlas. Es registro histórico, no instructivo de instalación.

---

## 2. Contexto

ChileMon nació como panel web para ASL3. El audio siempre fue el frente más difícil: el puente WebRTC/IAX2 y el companion pasaron meses sin completar una llamada útil. Los problemas no eran de un solo lugar: estaban repartidos entre la configuración de Asterisk, el protocolo IAX2 implementado a mano, la capa de audio de Python y el entorno de prueba en Windows.

La dificultad central fue que **cada capa parecía correcta aisladamente** y fallaba solo al interactuar con la siguiente. Por eso el avance real llegó cuando se dejó de depurar cada módulo por separado y se empezó a medir el camino completo con eco y tráfico UDP real.

---

## 3. Cronología de hitos

### 3.1 Establecimiento de la llamada IAX2 (2026-06)

El pipeline `WebSocket → AMI Originate → IAX2 NEW → ACCEPT → ANSWER → VOICE → WS` empezó a funcionar. Se corrigieron cuatro defectos de protocolo en `app/Services/WebRTCBridge/iax2.py` y `server.py`:

1. `oseqno` arrancaba en `1`; Asterisk espera el primer frame en `0`.
2. Los valores de `frametype` de `chan_iax2` difieren de RFC 5456: `CONTROL = 0x04`, `VOICE = 0x02`.
3. Faltaba el ACK de frames completos, lo que provocaba retransmisión y timeout en Asterisk.
4. El dispatch estaba invertido: `F=1` es frame completo y `F=0` es mini frame.

Se agregó soporte de mini frames V2 y se refactorizó el handler de `TXACC` para usar el `_send_ack()` nuevo.

**Aprendizaje clave:** hay que ajustarse a la implementación (`chan_iax2`), no a la especificación. La spec es el punto de partida, no la verdad operativa.

### 3.2 Codec µ-law manual (2026-07)

Python 3.13 eliminó `audioop`, y el entorno de desarrollo usa Python 3.14. Se implementaron `ulaw2lin()` y `lin2ulaw()` a mano en `companion/audio.py`, porque todo el audio IAX2 es µ-law a 8 kHz.

En esa primera implementación se usó bias `0x84` (132, para PCM de 16 bits left-justified) y un máximo de 13 bits de `8159`. Esa decisión fue correcta en su momento pero sembró el bug de escala que apareció meses después.

### 3.3 Primera llamada completa al repetidor (2026-08)

El companion logró llamar al nodo `54614` de punta a punta: `call_active=true`, frames de voz recibidos, ACKs correctos. Tres causas raíz:

1. `auth=md5` exige `AUTHREP`, que el companion no implementa. `auth=plaintext` tampoco funciona para call auth en ASL3. Solución: `auth=none` para peers de localhost.
2. Los números de llamada de los ACK deben ser `src=MY_CN(2)`, `dst=ASTERISK_CN` (tomado del frame recibido). El código del companion ya era correcto; el problema estaba en la configuración.
3. El `webrtc-bridge` en el puerto `9092` generaba `INAUTH` espurio durante las pruebas. Había que matarlo antes de testear.

Formato WS confirmado: `{"type": "call", "node": "54614"}` en `/ws`. Los frames de voz son mini frames: header de 4 bytes + payload µ-law (~164 bytes).

Quedaba un frente abierto: la entrada de audio en la Pi fallaba por desajuste ALSA (2 canales en un dispositivo mono).

### 3.4 Fix de estabilidad del codec (2026-09)

Se corrigió un `IndexError` en el encode: la tabla `_ULAW_ENCODE_SEG` tenía 256 entradas pero el índice podía llegar a 509. Además la tabla de decode generaba valores fuera de rango int16 y `struct.pack` crasheaba.

Cambios:

- Bias cambiado de `0x84` (132) a `33`, correcto para PCM de 14 bits right-justified.
- `segment = bit_length(biased) - 6` (antes `-5`).
- `mantissa = (biased >> (seg + 1)) & 0x0F` (antes `>> (seg + 2)`).
- Clip en `8191`, no `8192` (8192 = 2^13 queda fuera del segmento 7).

### 3.5 Camino de audio confiable — slice 1 (2026-11)

Commit `1fd5349` — `fix(iax2): reliable end-to-end audio path (bridge + companion)` — 8 archivos, 1026 inserciones, desde la base `f617cd4`.

Ocho causas raíz corregidas en este slice:

| # | Causa |
|---|-------|
| 1 | `CallToken` |
| 2 | `MD5 hex` |
| 3 | `VOICE/VNAK` |
| 4 | `oseqno` |
| 5 | `_voice_format_sent` |
| 6 | `app_echo.so` |
| 7 | Jitter de RX |
| 8 | Timestamp de TX |

El detalle línea por línea de cada una está en el diff del commit. El resultado medible:

- Eco `*0` con `0` frames descartados.
- **5/5 pruebas de TX** con `RPT_TXKEYED=1` en el repetidor.
- Calibración UDP de voz: **72–82 pkt/s** durante transmisión, ~1 pkt/s en reposo.

Con esto se confirmó que existía señal de TX y RX reales. El usuario lo resumió así: *"es un gran avance tener señal de TX y RX, pensé que este proyecto no llegaría a buen puerto"*.

### 3.6 Badge «Actividad» (2026-11, sin commitear al cierre de este documento)

El badge solo mostraba actividad de salida. Se agregó `app/Services/UdpRxProbe.php`:

- Umbral: **8 pkt/s promedio por ventana**.
- `MIN_WINDOW_SECONDS = 1.0`.
- Estado persistido en `data/udp_rx_state.json`.
- Se hace OR con `rx` en `NodeApiController::getNodes()` solo cuando `rx=false`.
- JavaScript sin cambios.

Verificación: RED 16 errores → GREEN 16 tests / 28 assertions → E2E en vivo como `www-data` 5/5 PASS, `php -l` limpio.

Observación del usuario tras el despliegue: *"ya se ve actividad de entrada tambien, ahunque todavia no es legible hay actividad de entrada y salida"*. Queda como follow-up la legibilidad del indicador.

### 3.7 Distorsión en ambos lados (2026-11)

El usuario reportó: *"estoy escuchando ruidos"* y luego precisó *"Distorsión en ambos lados"*.

**Primera hipótesis descartada con evidencia:** el PLC del jitter buffer. El log vivo mostraba:

```
received=990, played=990, dropped=0, plc=13, buffer_size=0, target_delay=20ms, jitter=0.2ms
```

13 frames de concealment sobre 990 no explican ruido continuo.

**Causa raíz real:** el codec estaba **4x fuera de escala**. Contrastado contra `Modules/audioop.c` de CPython 3.12:

- El encode usaba el int16 directamente sobre la escala de 14 bits → saturación al 25% (`8000`, `16000` y `32000` producían todos `0x80`).
- El decode emitía máximo `±8031` en vez de `±32124`.

**Fix en `companion/audio.py`:**

- `lin2ulaw()`: `sample >> 2` antes del bias (equivalente al `val >> 18` de CPython con el factor `GETSAMPLE32`).
- `_ULAW_DECODE_TABLE`: `decoded << 2` para pasar de 14 a 16 bits.

Validación numérica byte a byte contra CPython: `100=0xF2`, `1000=0xCE`, `4000=0xAF`, `8000=0xA0`, `16000=0x90`, `32000=0x80`. Decode de `0x80` = `32124`, roundtrip de `1000` = `988`.

Verificación: RED 5 fallos → GREEN 8/8 en `tests/companion/audio_test.py`, 37/37 en `tests/companion`.

Companion reiniciado el 2026-11-17 a las 20:38 como PID `24936` con el codec corregido cargado: `input=2`, `output=4`, WS en `127.0.0.1:9093`, sesión IAX2 contra `192.168.0.116:4569`, `All components started - ready`, stderr vacío.

**Pendiente:** validación de oído del usuario — eco `*0` y 5 tonos en el repetidor. Los tests confirman el codec; la sensación auditiva es la última prueba.

---

## 4. Puntos que hubo que salvar

Lista de decisiones y workarounds que no eran obvios y que costaron tiempo descubrir:

1. **`auth=none` para localhost.** `auth=md5` exige `AUTHREP`; el companion no lo implementa. No es una debilidad a ocultar: es la configuración correcta para un peer loopback.

2. **Los valores de frame type no siguen la RFC.** `chan_iax2` usa `CONTROL=0x04` y `VOICE=0x02`. Confiar en el estándar costó meses.

3. **Matar `webrtc-bridge` durante pruebas IAX2.** Generaba `INAUTH` espurio que contaminaba los logs.

4. **ALSA mono vs. stereo en la Pi.** Dispositivo mono con 2 canales configurados rompía la captura. En desarrollo se usa mock automático en `AslRptService.php`.

5. **`audioop` eliminado en Python 3.13+.** Implementación manual de G.711 con tablas precalculadas.

6. **Bias `33`, no `0x84`.** `0x84` es para PCM left-justified de 16 bits; en G.711 de 14 bits right-justified el bias es `33`. Confundirlos desplaza toda la escala.

7. **Shift de 2 bits en ambos sentidos.** `sample >> 2` al codificar y `decoded << 2` al decodificar. Saltarlo produce distorsión por saturación y volumen 4x demasiado bajo, respectivamente.

8. **El PLC no era el culpable.** Ante ruido, el instinto fue mirar el jitter buffer. Los contadores (`dropped=0`, `plc=13`, `jitter=0.2ms`) descartaron esa vía en dos minutos. Medir antes de teorizar.

9. **`log-call.php` excluido del commit.** Fragmento huérfano sin `<?php` y cero referencias. Se deja fuera del slice deliberadamente.

10. **Revisión nativa bloqueada en el refuter.** La transacción `review-cea984d6145a4a14` tiene 4/4 lentes capturados y admitidos, pero el capture del refuter no es posible con el contrato de transporte de este runtime (`relay_unavailable`, `immutable_review_transport_unsupported`). Los tokens exactos y el linaje están en Engram bajo `rdd/review-refuter-capture-blocked`. Regla respetada: **no ejecutar git ni inventar comandos** mientras la transacción esté abierta. El finding `R1-hardcoded-iax2-credential` fue refutado por daño especulativo.

11. **Agentes de review con modelo retirado.** Los 6 agentes `review-*` apuntaban a `opencode/minimax-m3-free`, dado de baja. Se repuntaron a `opencode/glm-5.3-flash-free` con backup en `opencode.jsonc.pre-fix-minimax-m3-free`.

12. **Trampa al escribir JSON temporales.** Un one-liner Python con `open(p, 'wb')` antes de leer truncó dos archivos de resultados. Siempre leer bytes primero, validar, y recién después escribir.

13. **Git ordenado en 5 work-units.** Sobre `main`, desde la base `1fd5349`: `6266fac` (codec µ-law + tests), `0c53cc3` (badge `UdpRxProbe` + controller + test), `2b7d1f9` (`favorites.php`), `68bc422` (`.gitignore` + `header.php`) y `cf904b0` (esta historia). Sin push.

14. **Revisión nativa abandonada dos veces por fallo del transporte.** La transacción `review-cea984d6145a4a14` quedó en `collect/provider_refuter_required` y la `review-e809445edd95ec63` en `collect/reviewer_results_required`: los subagents de revisión devolvían `ECONNRESET` sin contenido. Ambas se abandonaron con `actor=CA2IIG`, `reason=operator_disposition`; quedaron cuarentenadas, **no aprobadas**. Regla respetada: no tocar git con la transacción abierta.

15. **CSRF opcional detectado por la revisión.** La lente `review-risk` sí entregó un resultado válido y finding `R1-csrf-optional-bypass` (WARNING, determinístico) en `public/api/favorites.php`: `csrf_ok()` devolvía `true` cuando el token enviado o el de sesión estaba vacío, permitiendo un POST cross-site sin token. Corregido usando `Auth::validateCsrf()` (obligatorio, timing-safe, con rotación) y `Auth::startSession()` para endurecer la cookie. Test de regresión agregado en `tests/Auth/AuthTest.php`.

16. **PHP disponible vía Docker.** No hay `php.exe` en el host, pero la imagen `chilemon-chilemon:latest` trae PHP 8.2.30. La suite se corre con `docker run --rm -v "<repo>:/app" -w /app chilemon-chilemon:latest php phpunit.phar`.

17. **Companion apagado = Connect mudo (20/11/2026).** El widget abre `ws://127.0.0.1:9093/ws`, pero el companion es un proceso Python que debe estar corriendo en la PC; verificado: no había listener en 9093 ni proceso Python, por eso "Connect" no conectaba y el PTT quedaba inerte. Se agregaron `companion/start-companion.bat` y `companion/stop-companion.bat` para activarlo a necesidad, y el widget ahora muestra "Companion no detectado - ejecutalo en la PC" en vez de un "Disconnected" genérico, con backoff tope de 10 s para reconciliar rápido cuando el proceso aparece. El manual de descarga del dashboard también indica cómo activarlo y detenerlo. Verificado en vivo: el companion levantó el WS en `127.0.0.1:9093`, alcanzó la Pi en `192.168.0.116:4569` e inició audio (input=2, output=4).

---

## 5. Estado al cierre de este registro

- **Funcionando y medido:** señal TX y RX, 5/5 pruebas de TX, calibración UDP, codec validado contra CPython, badge con actividad de entrada detectada.
- **Sin validación de oído:** eco `*0` y tonos en el repetidor con el PID `24936` (fix µ-law cargado).
- **Git ordenado y pusheado:** 6 work-units en `main` desde `1fd5349` más el commit de docs `c8eda14`, con HEAD `c8eda14`. Push autorizado por el usuario el 20/11/2026: `f617cd4..7667100 main -> main` y `7667100..c8eda14 main -> main`. Remoto y local sincronizados. `public/api/log-call.php` excluido deliberadamente. Los cambios del hito 17 quedan sin commitear.
- **Revisión nativa:** ambas transacciones abandonadas y cuarentenadas, no aprobadas. Finding de `review-risk` corregido y cubierto por test.
- **Verificación:** suite PHP 71 tests / 124 assertiones, 0 errores (4 skipped, 6 incomplete preexistentes); pytest companion 37/37.
- **Pendiente:** pruebas de oído en la Pi y follow-up opcional del badge.

---

## 6. Próximos pasos

1. Prueba de oído en horario diurno: eco `*0` y 5 tonos en el repetidor.
2. Mejorar la legibilidad del badge de actividad de entrada (follow-up reportado por el usuario).

---

## 7. Lecciones que quedan

- **Medir antes de teorizar.** El PLC parecía el culpable hasta que los contadores dijeron `dropped=0`. El codec parecía correcto hasta que se comparó byte a byte con CPython.
- **La especificación orienta; la implementación manda.** IAX2 en `chan_iax2` no se comporta como RFC 5456.
- **Los bugs de escala son silenciosos.** No crashean, no fallan los tests viejos, simplemente suenan mal. Por eso hacen falta pruebas con valores de referencia externos, no solo tests internos.
- **Un avance se consolida cuando se documenta.** Esta historia existe para que nadie tenga que reconstruirla desde cero cuando el frente de audio vuelva a tocarse.
- **El transporte de revisión agotó su recorrido.** Dos linajes abandonados por fallos del runtime de OpenCode, no del repo. Ante un tercer ciclo con el mismo defecto, la decisión del operador fue cerrar con verificación local (suite PHP en Docker + pytest) en lugar de trabar git otra vez.
