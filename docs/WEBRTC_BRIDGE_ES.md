# Puente de Audio WebRTC — Push-to-Talk desde el Navegador para ChileMon

> **Estado**: Ciclos 1 y 2 — Puente Base + Inversión de Dirección IAX2 (v0.2.0)
> **Cambios SDD**: `webrtc-audio-bridge`, `bridge-reversal`

## Visión General

El Puente de Audio WebRTC agrega Push-to-Talk (PTT) desde el navegador a
ChileMon, permitiendo comunicación por radio desde el dashboard sin necesidad
de un radio físico. Recupera la experiencia SuperMon PTT que se perdió al
migrar a ASL3.

### Arquitectura

```
┌─────────┐     WebSocket      ┌──────────────────┐     IAX2 (UDP 4569)    ┌──────────┐
│ Browser ├───────────────────►│  Puente Python   ├───────────────────────►│ Asterisk │
│  PTT.js │◄───────────────────│ (aiortc+aiohttp) │◄──────────────────────│ app_rpt  │
└─────────┘     OPUS ↔ PCM     └────────┬─────────┘       ulaw mini-frames └──────────┘
                      │                  │
                      │ HTTP /health     │ AMI (TCP 5038)
                      ▼                  ▼
               ┌──────────────┐  ┌──────────────────┐
               │  API PHP     │  │  AMI Originate   │
               │ ptt-status   │  │  (llamar bridge) │
               │ ptt-ws-token │  └──────────────────┘
               └──────────────┘
```

- **Navegador** captura audio OPUS (WebRTC) o reproduce PCM decodificado
- **Puente Python** (`server.py`) corre en el puerto 9091, traduce entre WebRTC e IAX2
- **IAX2** se usa en vez de PJSIP porque ASL3 no compila los módulos SRTP/PJSIP
- **Asterisk app_rpt** maneja el PTT de radio vía phone mode

### ¿Por qué IAX2 y no SIP/WebRTC directamente?

ASL3 no incluye soporte para PJSIP/SRTP. Los módulos (`res_http_websocket`,
`res_srtp`, `chan_pjsip`) existen pero no pueden cargarse porque PJSIP no se
compiló. IAX2 está siempre disponible en ASL3 y soporta registro phone-mode,
lo que lo convierte en la ruta de integración más simple.

### Inversión de Dirección IAX2 (v0.2.0)

El diseño inicial del Ciclo 1 hacía que el bridge se registrara como **cliente**
IAX2 (extensión telefónica) en Asterisk. Esto falló porque ASL3 descarta los
paquetes de registro IAX2 entrantes a nivel de kernel — específicamente,
cualquier paquete con `callno=0` (nuevas llamadas) es silenciosamente filtrado
por las reglas iptables de ASL3.

**Solución**: El bridge ahora actúa como **servidor** IAX2, y Asterisk lo llama
a través de AMI `Originate` con `Async: true`. Esta inversión evita por
completo el filtro de ASL3.

Cambios clave:
- **Nuevo archivo `ami_client.py`**: Cliente AMI TCP asíncrono con reconexión
  automática y exponential backoff. Se conecta al Asterisk Manager Interface,
  inicia sesión y emite comandos `Originate` para iniciar llamadas del bridge.
- **Modificado `iax2.py`**: Se agregaron las clases `IAX2Server` e `IAX2Call`.
  El servidor escucha llamadas IAX2 entrantes de Asterisk; `IAX2Call` maneja
  el ciclo de vida completo (ACCEPT, tramas de voz, DTMF, HANGUP).
- **Modificado `server.py`**: Se reemplazó el flujo directo de `IAX2Session`
  por el flujo basado en servidor. El bridge ahora espera llamadas en lugar
  de iniciarlas.
- **Descubrimiento clave**: `IAX_CMD_ACCEPT = 0x0E` — mismo código de operación
  que `PONG` según RFC 5456, distinguido por contexto de llamada (PONG solo es
  válido en un intercambio de registro `callno=0`; ACCEPT es válido en una
  llamada activa).

## Requisitos del Sistema

| Componente | Mínimo | Recomendado |
|------------|--------|-------------|
| RAM | 1 GB | 2 GB (RPi 4/5) |
| SO | Debian 12 / Raspberry Pi OS | Mismo |
| Python | 3.10+ | 3.11 (Debian 12) |
| Asterisk | ASL3 (Asterisk 22+) | ASL3 |
| Disco libre | 100 MB | 500 MB |

> **Nota para RPi**: RPi 3 B+ (1 GB RAM) funciona para un solo usuario. Para
> uso multi-usuario o intensivo, se recomienda RPi 4/5 (2 GB+).

### Dependencias

Instaladas por el instalador principal (`install/install_chilemon.sh`, Paso 12) o standalone vía:

| Paquete | Propósito |
|---------|-----------|
| `python3-aiohttp` | Servidor HTTP/WebSocket |
| `python3-aiohttp-cors` | Cabeceras CORS (uso futuro multi-origen) |
| `python3-aiortc` | Conexión WebRTC (códec OPUS) |
| `python3-websockets` | Transporte WS (fallback/utilidad) |

## Archivos

### Puente Python (`app/Services/WebRTCBridge/`)

| Archivo | Propósito |
|---------|-----------|
| `__init__.py` | Inicialización del paquete, versión 0.1.0 |
| `iax2.py` | Manejador de protocolo IAX2 — REGREQ/NEW/DTMF/HANGUP + mini voice |
| `audio.py` | Transcodificación de audio — OPUS↔PCM↔ulaw, remuestreo 16kHz↔8kHz |
| `server.py` | Demonio aiohttp — WebSocket `/ws`, health `/health`, ciclo de vida IAX2 |
| `ami_client.py` | Cliente AMI TCP asíncrono — conectar, login, originate, monitoreo de llamadas |

### Configuración Asterisk (`install/asterisk/`)

| Archivo | Propósito |
|---------|-----------|
| `iax.conf` | Plantilla de extensión telefónica IAX2 para el bridge |
| `rpt.conf` | Guía de configuración phone mode para PTT via app_rpt |

### Integración del Sistema

| Archivo | Propósito |
|---------|-----------|
| `install/chilemon-webrtc.service` | Unidad systemd — auto-inicio, Restart=always |
| `install/install_chilemon.sh` (Paso 12) | Instalador principal — incluye bridge automáticamente |
| `install/install_webrtc.sh` | Instalador standalone — dependencias apt + habilitar servicio |
| `config/app.php` | Constantes del bridge (`WEBRTC_PORT`, `IAX_PHONE_USER`, etc.) |
| `config/local.php` | Configuración local (secretos, valores por instancia) |

### Dashboard

| Archivo | Propósito |
|---------|-----------|
| `public/assets/js/ptt-widget.js` | Widget PTT en JS nativo — WS, PTT key/unkey, barra de volumen |
| `public/assets/css/dashboard.css` | Estilos del widget PTT (botón flotante, indicador de estado) |
| `public/views/dashboard.php` | Contenedor ancla para el widget |

### API

| Archivo | Propósito |
|---------|-----------|
| `public/api/ptt-status.php` | Proxy del health check del bridge → JSON para el dashboard |
| `public/api/ptt-ws-token.php` | Genera token HMAC para autenticación WebSocket |

## Instalación

### 1. Instalar dependencias en la RPi

```bash
# Copiar el script de instalación y ejecutarlo
  sudo bash install/install_chilemon.sh

O standalone:

  sudo bash install/install_webrtc.sh
```

Esto instala los paquetes Python, crea el directorio del bridge y habilita el
servicio systemd.

### 2. Configurar Asterisk

Copiar y adaptar las plantillas de configuración:

```bash
# Extensión telefónica IAX2 — agregar a /etc/asterisk/iax.conf
# Ver install/asterisk/iax.conf para la plantilla

# Phone mode — verificar /etc/asterisk/rpt.conf
# Ver install/asterisk/rpt.conf para las opciones necesarias
```

Configuración clave en `iax.conf`:
```
[webrtc-bridge]
type=friend
host=dynamic
context=radio-ptt
secret=TU_SECRETO_AQUI
disallow=all
allow=ulaw
```

Configuración clave en `rpt.conf`:
```
phonelogin=yes
phonecontext=radio-ptt
```

### 3. Configurar el bridge

Editar `config/local.php`:
```php
'webrtc_port' => 9091,
'webrtc_secret' => 'tu-secreto-hmac-aqui',
'iax_phone_user' => 'webrtc-bridge',
'iax_phone_pass' => 'tu-secreto-iax-aqui',
```

### 4. Reiniciar y verificar

```bash
sudo systemctl restart chilemon-webrtc
sudo systemctl status chilemon-webrtc

# Verificar el health endpoint
curl http://127.0.0.1:9091/health
# → {"status":"ok"}
```

## Referencia de Configuración

### Variables de Entorno (demonio del bridge)

| Variable | Por Defecto | Descripción |
|----------|-------------|-------------|
| `WEBRTC_PORT` | `9091` | Puerto HTTP/WS del bridge |
| `IAX_HOST` | `127.0.0.1` | Dirección IAX2 de Asterisk |
| `IAX_PORT` | `4569` | Puerto UDP IAX2 de Asterisk |
| `IAX_PHONE_USER` | `webrtc-bridge` | Usuario de extensión telefónica IAX2 |
| `IAX_PHONE_PASS` | *(requerido)* | Secreto de extensión telefónica IAX2 |
| `WEBRTC_SECRET` | *(requerido)* | Secreto HMAC para firmar tokens WS |
| `ASL_NODE` | `61916` | Número de nodo ASL para llamadas PTT |

### Constantes PHP (`config/app.php`)

| Constante | Por Defecto | Descripción |
|-----------|-------------|-------------|
| `WEBRTC_PORT` | `9091` | Puerto de escucha del bridge |
| `IAX_PHONE_USER` | `'webrtc-bridge'` | Usuario IAX2 |
| `IAX_PHONE_PASS` | *(requerido)* | Secreto IAX2 |
| `WEBRTC_SECRET` | *(requerido)* | Secreto HMAC |

## Uso

### Widget del Dashboard

Una vez que el bridge está corriendo, el dashboard muestra un widget PTT
flotante en la esquina inferior derecha:

- **Click sostenido** (o **Espacio** sostenido) para transmitir
- **Soltar** para dejar de transmitir
- Indicador de estado: 🟢 conectado, 🟡 transmitiendo, 🔴 no disponible
- Barra de volumen muestra el nivel de audio recibido

### Protocolo WebSocket

Los mensajes son JSON:

```json
// Cliente → Bridge
{"type": "ptt", "action": "key"}      // Activar transmisor
{"type": "ptt", "action": "unkey"}    // Desactivar transmisor

// Bridge → Cliente
{"type": "status", "registered": true, "in_call": true, "ptt_active": false}
{"type": "audio", "data": "hex_float32_audio", "rate": 16000}
```

### Flujo de Autenticación

1. El dashboard carga `GET /api/ptt-ws-token.php` (autenticado por sesión)
2. PHP devuelve `{"token": "usuario:a1b2c3...hex..."}`
3. JS se conecta a `ws://host:9091/ws?token=usuario:a1b2c3...hex...`
4. El bridge valida la firma HMAC del usuario usando `WEBRTC_SECRET`

## Limitaciones Conocidas (Ciclo 1)

| Problema | Impacto | Solución Temporal |
|----------|---------|-------------------|
| ~~Sin recuperación tras reinicio de Asterisk~~ | **Resuelto**: bridge-reversal (v0.2.0) | Cliente AMI se reconecta y re-origina automáticamente |
| DTMF enviado sin reintento | Sin reintento en caso de DTMF perdido | Generalmente confiable en localhost |
| Sin verificación de hardware | No advierte si <1GB RAM | Monitorear con `htop` |
| `audioop` obsoleto en Python 3.13+ | Actualmente en Python 3.11 (Debian 12) | Planificar migración antes de actualizar SO |
| Sin TURN/STUN | WebRTC solo en LAN | Usar tailscale/VPN para acceso remoto |
| WebSocket sin keepalive | Desconexión silenciosa en inactividad | El bridge enviará pings periódicos (planeado) |

## Roadmap

### Ciclo 2 — Preparación para Producción
- Servidor TURN/STUN para acceso externo
- Let's Encrypt para HTTPS/WSS
- Autenticación multi-usuario y gestión de sesiones
- Keepalive WebSocket con ping/pong

### Ciclo 3 — Endurecimiento
- Endpoint de métricas Prometheus
- Logs estructurados (JSON)
- Contenedor Docker
- CI/CD con GitHub Actions
- Pruebas de sistema contra Asterisk real

## Notas de Seguridad

- **Credenciales IAX2** se almacenan en `config/local.php` — mantener fuera de
  control de versiones (ya está en `.gitignore`)
- **Tokens HMAC** de corta duración (idealmente de un solo uso) y limitados a
  una sesión WebSocket
- El bridge corre en un puerto separado (9091) — las reglas de firewall deben
  restringir el acceso solo al servidor web
- Los endpoints PHP usan autenticación de sesión existente + rate limiting

## Depuración

```bash
# Ver logs del bridge
sudo journalctl -u chilemon-webrtc -f

# Probar registro IAX2 manualmente
sudo tcpdump -i lo -nn port 4569

# Verificar que Asterisk ve el bridge
sudo asterisk -rx "iax2 show peers"

# Verificar health
curl http://127.0.0.1:9091/health

# Reiniciar bridge
sudo systemctl restart chilemon-webrtc
```
