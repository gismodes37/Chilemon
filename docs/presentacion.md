# 🇨🇱 ChileMon — Presentación técnica

> Documento preparado para presentación y material audiovisual.
> Versión: v0.4.0 — Junio 2026

---

## Índice

1. [¿Qué es ChileMon?](#1-¿qué-es-chilemon)
2. [ChileMon Agent](#2-chilemon-agent)
3. [Arquitectura General](#3-arquitectura-general)
4. [Dashboard de Monitoreo](#4-dashboard-de-monitoreo)
5. [Puente de Audio WebRTC — TX y RX](#5-puente-de-audio-webrtc--tx-y-rx)
   - [Visión General](#51-visión-general)
   - [Cadena de Transmisión (TX)](#52-cadena-de-transmisión-tx)
   - [Cadena de Recepción (RX)](#53-cadena-de-recepción-rx)
   - [Componentes del Puente](#54-componentes-del-puente)
   - [Flujo de Establecimiento de Llamada](#55-flujo-de-establecimiento-de-llamada)
   - [Formato de Audio y Transcodificación](#56-formato-de-audio-y-transcodificación)
   - [Protocolo WebSocket](#57-protocolo-websocket)
   - [Autenticación WebSocket](#58-autenticación-websocket)
6. [Mapa Comunitario (Hub)](#6-mapa-comunitario-hub)
7. [Seguridad](#7-seguridad)
8. [Instalación y Despliegue](#8-instalación-y-despliegue)
9. [Roadmap](#9-roadmap)

---

## 1. ¿Qué es ChileMon?

ChileMon es un **dashboard web moderno** para monitorear y controlar nodos
**AllStarLink (ASL3)**. Nace como una alternativa moderna a **Supermon**,
diseñada para ser más clara, modular, segura y fácil de instalar.

### Origen

Creado por **CA2IIG** (radioaficionado chileno) ante la necesidad de tener una
herramienta moderna para operar nodos ASL3 desde el navegador. Lo que comenzó
como un reemplazo de Supermon ha evolucionado a un ecosistema completo.

### Filosofía del proyecto

| Principio | Descripción |
|-----------|-------------|
| **No interfiere con AllStarLink** | ChileMon corre junto al nodo, sin modificarlo |
| **No modifica Asterisk** | Usa APIs existentes (AMI, IAX2, rpt) |
| **No reemplaza Supermon** | Es una alternativa moderna, no un reemplazo forzado |
| **Modular** | Cada componente es independiente y reemplazable |
| **Fácil de instalar** | Instalador automático en un solo comando |
| **Seguro por diseño** | Wrapper, rate limiting, CSRF, roles, sin credenciales hardcodeadas |

### ¿Qué permite hacer?

- Monitorear nodos conectados en tiempo real
- Conectar y desconectar nodos remotos
- Visualizar la red de nodos enlazados
- **Hablar por radio desde el navegador** (WebRTC PTT)
- Gestionar nodos favoritos
- Ver actividad reciente y estadísticas
- **Registrarse en el mapa comunitario** de ChileMon

---

## 2. ChileMon Agent

### ¿Qué es el Agent?

El **ChileMon Agent** es la aplicación principal que se instala en cada nodo
ASL3 (generalmente una **Raspberry Pi**). Es el componente que:

1. **Se conecta a Asterisk** localmente vía AMI y comandos `rpt`
2. **Sirve el dashboard web** en Apache + PHP + SQLite
3. **Ejecuta el puente WebRTC** para audio en el navegador
4. **Se comunica con el Hub** central para el mapa comunitario

Cada instalación de ChileMon es un **Agent**. No existe un servidor central
obligatorio — cada nodo es autónomo.

### Componentes del Agent

```
/opt/chilemon/                     ← Raíz del Agent
├── app/                           ← Lógica PHP
│   ├── Asterisk/                  ← AslRptService, NodeTracker
│   ├── Auth/                      ← Auth (login, CSRF, roles, rate limiting)
│   ├── Controllers/               ← Dashboard, NodeApi, MapController
│   ├── Core/                      ← Database (PDO singleton), RateLimiter
│   └── Services/
│       └── WebRTCBridge/          ← Puente Python (server, iax2, audio, ami)
│
├── public/                        ← Document root (Apache)
│   ├── index.php                  ← Dashboard principal (requiere login)
│   ├── login.php                  ← Página de inicio de sesión
│   ├── admin.php                  ← Panel de administración
│   ├── api/                       ← Endpoints JSON
│   │   ├── map/                   ← Registro en mapa comunitario
│   │   ├── ami/                   ← Estado AMI
│   │   ├── ptt-status.php         ← Estado del puente WebRTC
│   │   └── ptt-ws-token.php       ← Token WebSocket para el bridge
│   └── views/
│       ├── dashboard.php          ← HTML del dashboard (con modal de registro)
│       └── partials/              ← head, header, footer, scripts
│
├── config/
│   ├── app.php                    ← Constantes globales
│   ├── database.php               ← Config SQLite
│   └── local.php                  ← Config local (NO versionada)
│
├── data/
│   └── chilemon.sqlite            ← Base de datos local
│
├── install/                       ← Instaladores
│   ├── install_chilemon.sh        ← Instalador principal
│   └── asterisk/                  ← Plantillas IAX2, rpt.conf
│
├── bin/                           ← Scripts CLI
│   ├── install.php                ← Inicialización DB
│   └── create-user.php            ← Crear usuario admin
│
└── tests/                         ← Tests PHPUnit
```

### Tecnologías del Agent

| Componente | Tecnología | Versión |
|-----------|-----------|---------|
| Servidor web | Apache | 2.4+ |
| Backend | PHP | 8.2+ (strict_types=1) |
| Base de datos | SQLite | 3.40+ (WAL mode) |
| Frontend | Bootstrap 5 + Vanilla JS | 5.3 |
| Mapas | Leaflet + OpenStreetMap | 1.9.4 |
| Geocoding | Nominatim OSM | API gratuita |
| Audio bridge | Python | 3.11+ |
| WebRTC | aiortc | 1.4+ |
| WebSocket | aiohttp + websockets | — |

---

## 3. Arquitectura General

```
                    ┌─────────────────────────────────────────────────┐
                    │            ChileMon Agent (RPi)                 │
                    │                                                  │
                    │  ┌──────────┐    ┌───────────┐                  │
                    │  │  Apache  │───▶│  PHP 8.2  │─── SQLite ────  │
                    │  │  :80     │    │  (FAPI)   │                  │
                    │  └────┬─────┘    └─────┬─────┘                  │
                    │       │                │                         │
                    │       ▼                ▼                         │
                    │  ┌──────────────────────────────────────┐      │
                    │  │    WebRTC Bridge (Python :9091)      │      │
                    │  │  ┌────────┐ ┌────────┐ ┌──────────┐ │      │
                    │  │  │ server │ │  iax2  │ │ ami_cli  │ │      │
                    │  │  │ .py    │ │  .py   │ │ ent.py   │ │      │
                    │  │  └────┬───┘ └───┬────┘ └─────┬────┘ │      │
                    │  └───────┼─────────┼────────────┼──────┘      │
                    │          │         │            │              │
                    │          ▼         ▼            ▼              │
                    │  ┌──────────────────────────────────────┐      │
                    │  │         Asterisk (ASL3)              │      │
                    │  │  ┌─────────┐ ┌────────┐ ┌─────────┐  │      │
                    │  │  │ app_rpt │ │  IAX2  │ │  AMI    │  │      │
                    │  │  │ (radio) │ │(UDP 4569)│(TCP 5038)│  │      │
                    │  │  └─────────┘ └────────┘ └─────────┘  │      │
                    │  └──────────────────────────────────────┘      │
                    │              │                                  │
                    │              ▼                                  │
                    │    ┌─────────────────┐                        │
                    │    │  Radiofrecuencia │                        │
                    │    │  (RTL-SDR / MMDVM│                        │
                    │    └─────────────────┘                        │
                    └─────────────────────────────────────────────────┘
                                      │
                                      │ HTTPS (opcional)
                                      ▼
                    ┌─────────────────────────────────────────────────┐
                    │            ChileMon Hub (Proxmox LXC)          │
                    │                                                  │
                    │  ┌──────────┐    ┌───────────┐                  │
                    │  │  Apache  │───▶│  PHP 8.2  │─── SQLite ────  │
                    │  │  :80     │    │  (FAPI)   │  (hub.sqlite)    │
                    │  └────┬─────┘    └─────┬─────┘                  │
                    │       │                │                         │
                    │       ▼                ▼                         │
                    │  ┌──────────────────────────────────────┐      │
                    │  │  Mapa público Leaflet (/map.php)     │      │
                    │  │  API REST (register, data, check)    │      │
                    │  │  Panel de administración             │      │
                    │  └──────────────────────────────────────┘      │
                    └─────────────────────────────────────────────────┘
```

### Flujo de datos entre componentes

```
            Agent                          Hub
    ┌──────────────────┐         ┌──────────────────┐
    │  Dashboard (PHP) │──POST──▶│  register.php    │
    │  Modal registro  │  registro│  (almacena en DB)│
    └──────────────────┘         └────────┬─────────┘
                                          │
    ┌──────────────────┐         ┌────────▼─────────┐
    │  Dashboard (PHP) │──GET────▶  check.php       │
    │  (verifica banner)│ node_id │  (consulta DB)   │
    └──────────────────┘         └──────────────────┘
                                          │
    ┌──────────────────┐         ┌────────▼─────────┐
    │  Navegador       │──GET────▶  data.php        │
    │  Mapa público    │  GeoJSON│  (solo approved)  │
    └──────────────────┘         └──────────────────┘
```

---

## 4. Dashboard de Monitoreo

El dashboard es el corazón visual de ChileMon. Se accede vía
`http://<ip>/chilemon/` y requiere autenticación.

### Secciones del dashboard

| Sección | Descripción |
|---------|-------------|
| **Nodo local** | Información del nodo ASL local (número, uptime, versión) |
| **Nodos conectados** | Tabla en tiempo real de nodos remotos conectados |
| **Red de nodos** | Modal con visualización de la red enlazada |
| **Estadísticas** | Métricas de tráfico, usuarios activos, tiempo enlace |
| **Actividad reciente** | Log de eventos (conexiones, desconexiones) |
| **Nodos favoritos** | Lista personalizable de nodos favoritos |
| **Botón ChileMon Map** | Acceso directo al mapa comunitario |
| **Widget PTT** | Botón flotante para Push-to-Talk desde el navegador |
| **Banner de registro** | Invitación a registrarse en el mapa comunitario |

### APIs del dashboard

| Endpoint | Método | Propósito |
|----------|--------|-----------|
| `/api/nodes.php` | GET | Lista de nodos conectados |
| `/api/stats.php` | GET | Estadísticas del nodo local |
| `/api/connect.php` | POST | Conectar a nodo remoto |
| `/api/disconnect.php` | POST | Desconectar nodo remoto |
| `/api/ami/status.php` | GET | Estado AMI detallado |
| `/api/system_action.php` | POST | Acciones del sistema (restart, poweroff) |
| `/api/health.php` | GET | Health check público (rate limited) |
| `/api/ptt-status.php` | GET | Estado del puente WebRTC |
| `/api/ptt-ws-token.php` | GET | Token WebSocket para bridge |

---

## 5. Puente de Audio WebRTC — TX y RX

> **Esta es la sección más importante técnicamente.**
> El Puente de Audio WebRTC permite **transmitir y recibir audio de radio
> directamente desde el navegador web**, sin necesidad de un radio físico.

### 5.1 Visión General

```
BROWSER (Chrome/Firefox)
    │
    │  WebSocket (puerto 9091)
    │  ▲ Opus (TX) │ Opus ▼ (RX)
    ▼              │
┌─────────────────────────────────────┐
│       WebRTC Bridge (Python)        │
│          server.py :9091            │
│                                     │
│  ┌─────────┐    ┌────────────────┐  │
│  │ WebRTC  │    │   IAX2 Server  │  │
│  │ (aiortc)│◄──▶│   (iax2.py)    │  │
│  │ OPUS    │    │   ulaw/PCM     │  │
│  └─────────┘    └───────┬────────┘  │
│                         │           │
│  ┌──────────────────┐   │           │
│  │  AMI Client      │   │           │
│  │  (ami_client.py) │───┘           │
│  │  → Originate     │               │
│  └──────────────────┘               │
└──────────┬──────────────────────────┘
           │
           │  IAX2 (UDP 4569)
           │  AMI (TCP 5038)
           ▼
┌─────────────────────────────────────┐
│          Asterisk (ASL3)            │
│                                     │
│  ┌──────────────┐  ┌─────────────┐  │
│  │   app_rpt    │  │   AMI       │  │
│  │   (radio)    │◄─┤  manager    │  │
│  └──────┬───────┘  └─────────────┘  │
│         │                           │
│         ▼                           │
│    Radiofrecuencia                  │
│    (RTL-SDR / MMDVM / placa sonido)│
└─────────────────────────────────────┘
```

### 5.2 Cadena de Transmisión (TX)

> **TX = Browser → Radio.** El operador habla por el micrófono del
> navegador y su voz sale por el repetidor/radio.

```
PASO 1: CAPTURA
┌──────────┐
│ Browser  │
│          │
│ getUserMedia()
│   → Micrófono
│   → PCM 48kHz
│   → Opus encoder
└─────┬────┘
      │ Opus frames (20ms)
      │ WebSocket
      ▼
PASO 2: PUENTE
┌──────────────┐
│ server.py    │
│              │
│  Recibe Opus │
│  → decode a PCM 48kHz
│  → resample a 8kHz
│  → encode a ulaw
│  → encapsular en mini-frame IAX2
└──────┬───────┘
       │ ulaw mini-frames (20ms)
       │ IAX2 UDP 4569
       ▼
PASO 3: ASTERISK
┌──────────────┐
│ Asterisk     │
│ app_rpt      │
│              │
│  Recibe IAX2 │
│  → phone mode
│  → app_rpt processa DTMF/audio
│  → inyecta al nodo ASL
└──────┬───────┘
       │
       ▼
PASO 4: RADIO
┌──────────────┐
│ RF Salida    │
│              │
│  app_rpt     │
│  → modula a RF
│  → sale por el repetidor
└──────────────┘
```

**Resumen TX:**
```
Micrófono → PCM 48kHz → Opus → WebSocket → 
  server.py → PCM → 8kHz → ulaw → IAX2 UDP → 
  Asterisk app_rpt → Radiofrecuencia
```

### 5.3 Cadena de Recepción (RX)

> **RX = Radio → Browser.** El audio que llega del repetidor/radio se
> escucha en los parlantes del navegador.

```
PASO 1: RADIO
┌──────────────┐
│ RF Entrada   │
│              │
│  app_rpt     │
│  demodula RF │
│  → audio PCM 8kHz
│  → envía a extensión IAX2
└──────┬───────┘
       │ ulaw mini-frames (20ms)
       │ IAX2 UDP 4569
       ▼
PASO 2: PUENTE
┌──────────────┐
│ server.py    │
│              │
│  Recibe IAX2 │
│  → mini-frame ulaw
│  → decode a PCM 8kHz
│  → resample a 16kHz
│  → encode a Opus
└──────┬───────┘
       │ Opus frames (20ms)
       │ WebSocket
       ▼
PASO 3: BROWSER
┌──────────────┐
│ Browser      │
│ ptt-widget.js│
│              │
│  Recibe Opus │
│  → decode a PCM 48kHz
│  → encolar en AudioBuffer
│  → Web Audio API → parlantes
└──────────────┘
```

**Resumen RX:**
```
Radiofrecuencia → Asterisk app_rpt → 
  IAX2 ulaw → server.py → PCM → 16kHz → Opus → 
  WebSocket → Browser → parlantes
```

### 5.4 Componentes del Puente

El puente WebRTC está implementado en **Python** y consta de 4 archivos
principales:

#### `server.py` — Orquestador principal

Demonio aiohttp que corre en el puerto **9091**. Responsabilidades:

- **Servidor WebSocket** (`/ws`): recibe conexiones de los navegadores,
  maneja mensajes PTT (key/unkey), envía y recibe flujo de audio
- **Servidor HTTP** (`/health`): endpoint de health check
- **Ciclo de vida IAX2**: cuando un navegador se conecta y hace PTT key,
  server.py coordina:
  1. Obtener token de llamada del AMI
  2. Enviar llamada IAX2 a Asterisk vía AMI Originate
  3. Cuando Asterisk responde (llamada IAX2 entrante), aceptar y empezar
     flujo de audio
  4. Enviar DTMF para key (PTT activo) o unkey (PTT liberado)
- **Transcodificación**: conecta el flujo de audio WebRTC (Opus) con el
  flujo IAX2 (ulaw) mediante las funciones de `audio.py`

#### `iax2.py` — Protocolo IAX2

Implementa el protocolo **IAX2** (Inter-Asterisk eXchange v2, RFC 5456)
a nivel de aplicación. Contiene:

- **`IAX2Server`**: Escucha en UDP 4569, acepta llamadas entrantes de
  Asterisk, maneja registro, autenticación y ciclo de vida de llamadas
- **`IAX2Call`**: Maneja el estado de una llamada activa — envía ACCEPT,
  recibe/envía mini-frames de voz, procesa DTMF, maneja HANGUP
- **Soporte mini-frame**: Los mini-frames IAX2 son paquetes UDP ligeros
  que transportan 20ms de audio ulaw sin cabecera completa

**Inversión de dirección IAX2**: El bridge actúa como **servidor** IAX2,
no como cliente. Asterisk llama al bridge vía AMI `Originate`. Esto
evita el filtro de ASL3 que descarta paquetes IAX2 con `callno=0`
(nuevas llamadas entrantes).

#### `audio.py` — Transcodificación

Módulo de procesamiento de audio digital. Realiza:

| Operación | De | A | Propósito |
|-----------|-----|-----|-----------|
| Decode Opus | Opus (WebRTC) | PCM float 48kHz | Audio entrante del browser |
| Resample | 48kHz | 8kHz | Reducir para IAX2/ulaw |
| Encode ulaw | PCM 8kHz | ulaw 8kHz | Audio para Asterisk |
| **Decode ulaw** | ulaw 8kHz | PCM 8kHz | Audio de Asterisk |
| Resample | 8kHz | 16kHz | Mejorar calidad para Opus |
| Encode Opus | PCM 16kHz | Opus (WebRTC) | Audio para el browser |

```
TX: Opus ──▶ PCM 48kHz ──▶ resample 8kHz ──▶ ulaw
RX: ulaw ──▶ PCM 8kHz ──▶ resample 16kHz ──▶ Opus
```

#### `ami_client.py` — Cliente AMI

Cliente TCP asíncrono para el **Asterisk Manager Interface** (puerto
5038). Responsabilidades:

- **Conexión**: Se conecta a Asterisk vía TCP, maneja reconexión
  automática con exponential backoff
- **Login**: Autentica con usuario/contraseña AMI
- **Originate**: Envía comando `Originate` para que Asterisk llame al
  bridge por IAX2 (`Async: true` para no bloquear)
- **Monitoreo**: Escucha eventos AMI (Hangup, Newchannel, etc.)

### 5.5 Flujo de Establecimiento de Llamada

```
Browser              server.py              ami_client.py         Asterisk
   │                     │                      │                    │
   │  WS Connect         │                      │                    │
   │────────────────────▶│                      │                    │
   │                     │                      │                    │
   │  {"type":"ptt",     │                      │                    │
   │   "action":"key"}   │                      │                    │
   │────────────────────▶│                      │                    │
   │                     │   AMI Originate      │                    │
   │                     │─────────────────────▶│                    │
   │                     │                      │  Originate         │
   │                     │                      │───────────────────▶│
   │                     │                      │                    │
   │                     │                      │   IAX2 NEW (call)  │
   │                     │  IAX2 ACCEPT ◄───────┼────────────────────│
   │                     │◄─────────────────────│                    │
   │                     │                      │                    │
   │                     │  IAX2 mini-frames    │                    │
   │                     │  (audio ulaw ◄───────┼────────────────────│
   │                     │   bidireccional)     │                    │
   │                     │                      │                    │
   │  Audio frames Opus  │                      │                    │
   │◄────────────────────▶                      │                    │
   │                     │                      │                    │
   │                     │  DTMF *3 (key)       │                    │
   │                     │─────────────────────▶│───────────────────▶│
   │                     │                      │  (app_rpt activa   │
   │                     │                      │   transmisor)      │
   │                     │                      │                    │
   │  TX: Mic → Opus ───▶─── ulaw ────────────▶│                   │
   │  RX: Opus ◄────────── ulaw ◄──────────────│                    │
   │                     │                      │                    │
```

### 5.6 Formato de Audio y Transcodificación

```
TX PATH (Browser → Radio):
┌────────────┐   Opus 48kHz   ┌──────────┐   ulaw 8kHz   ┌──────────┐
│ Browser    │───────────────▶│ server   │───────────────▶│ Asterisk │
│ mic → Opus │   WebSocket    │ .py      │   IAX2 UDP     │ app_rpt  │
└────────────┘                └──────────┘                └──────────┘

RX PATH (Radio → Browser):
┌──────────┐   ulaw 8kHz    ┌──────────┐   Opus 16kHz   ┌────────────┐
│ Asterisk │───────────────▶│ server   │───────────────▶│ Browser    │
│ app_rpt  │   IAX2 UDP     │ .py      │   WebSocket    │ speakers   │
└──────────┘                └──────────┘                └────────────┘
```

**Detalle de la transcodificación:**

| Etapa | Códec | Frecuencia | Bits | Tamaño frame | Bitrate |
|-------|-------|-----------|------|-------------|---------|
| Micrófono → WebRTC | Opus | 48 kHz | float 32 | 20 ms | ~32 kbps |
| WebRTC → Bridge | Opus (WebSocket) | 48 kHz | float 32 | 20 ms | ~32 kbps |
| Bridge → Asterisk | ulaw | 8 kHz | 8 bit | 20 ms (160 bytes) | 64 kbps |
| Asterisk → Bridge | ulaw | 8 kHz | 8 bit | 20 ms (160 bytes) | 64 kbps |
| Bridge → WebRTC | Opus (WebSocket) | 16 kHz | float 32 | 20 ms | ~24 kbps |
| WebRTC → Browser | PCM | 48 kHz | float 32 | variable | — |

### 5.7 Protocolo WebSocket

La comunicación entre el navegador y el bridge se hace por WebSocket en el
puerto **9091**, ruta `/ws?token=<token>`.

**Mensajes Cliente → Bridge (TX):**

```json
// Activar PTT (key)
{"type": "ptt", "action": "key"}

// Desactivar PTT (unkey)
{"type": "ptt", "action": "unkey"}
```

**Mensajes Bridge → Cliente (RX + estado):**

```json
// Estado de la conexión
{"type": "status", "registered": true, "in_call": true, "ptt_active": false}

// Audio RX (Opus en hex)
{"type": "audio", "data": "hex_encoded_opus_frame", "rate": 16000}
```

### 5.8 Autenticación WebSocket

El acceso al WebSocket del bridge está protegido por **tokens HMAC**:

```
1. Dashboard carga GET /api/ptt-ws-token.php
   → PHP verifica sesión activa
   → Genera token: HMAC-SHA256(usuario + timestamp, WEBRTC_SECRET)
   → Devuelve: {"token": "usuario:<hex>"}

2. JS conecta: ws://host:9091/ws?token=usuario:<hex>
   → Bridge valida HMAC con mismo WEBRTC_SECRET
   → Bridge verifica timestamp < 30 segundos
   → Conexión aceptada o rechazada

3. Si el token expira o es inválido → 401 Unauthorized
```

---

## 6. Mapa Comunitario (Hub)

### ¿Qué es el Hub?

El **ChileMon Hub** es un servidor central opcional donde los operadores
registran voluntariamente su nodo para aparecer en un **mapa comunitario**.

A diferencia del Agent, el Hub es un proyecto separado:
[ChileMon Hub](https://github.com/gismodes37/Chilemon-Hub).

### ¿Por qué separado?

| Agent (RPi) | Hub (Proxmox LXC) |
|-------------|-------------------|
| Dashboard de comunicaciones | Registro comunitario |
| WebRTC bridge + PTT | Mapa público Leaflet |
| Monitoreo de nodos | API REST de registro |
| Control de conexiones | Panel de administración |
| **Esencial para el nodo** | **Opcional, comunitario** |

### Flujo de registro

```
Agent (RPi)                              Hub
──────────                            ────────
    │                                      │
    │  (1) Banner: "Registrá tu            │
    │      instalación"                    │
    │                                      │
    │  (2) Modal horizontal:               │
    │  ┌───────┬──────────────┐            │
    │  │Form:  │   Mapa       │            │
    │  │- Calle│   Leaflet    │            │
    │  │- Dir  │   interactivo│            │
    │  │- Lat/ │   con         │            │
    │  │  Lng  │   geocoding   │            │
    │  └───────┴──────────────┘            │
    │                                      │
    │  (3) POST /api/map/register.php ─────▶─── (4) Guarda en SQLite
    │    {callsign, lat, lng,              │     (auto-approved)
    │     registration_token}              │
    │                                      │
    │  (5) Recarga página                  │
    │  → Banner desaparece                 │
    │                                      │
    │                              (6) Admin puede
    │                              deslistar desde
    │                              /admin/registrations.php
```

### Mapa público

El mapa público se sirve en `http://<hub>/map.php`:

- **Full-screen**: sin cabeceras, solo el mapa
- **Footer mínimo**: barra oscura de 28px
- **Marcadores estilo Google Pin**: forma de gota azul con ícono broadcast
- **Popups**: callsign, ubicación, última actualización
- **Auto-zoom**: ajusta para mostrar todos los nodos
- **Sin autenticación**: acceso público
- **Geocoding**: el modal de registro busca direcciones automáticamente
  vía Nominatim (OpenStreetMap), filtrado a Chile

### API del Hub

| Endpoint | Método | Auth | Propósito |
|----------|--------|------|-----------|
| `/api/map/register.php` | POST | Token o sesión | Registrar nodo |
| `/api/map/data.php` | GET | Público | GeoJSON de nodos aprobados |
| `/api/map/check.php` | GET | Público | Verificar si nodo ya registrado |
| `/admin/registrations.php` | GET | Admin | Panel de moderación |

---

## 7. Seguridad

### Principios de seguridad

1. **No ejecutar comandos Asterisk desde PHP directamente**
2. **Wrapper restringido**: solo 4 comandos permitidos (nodes, stats,
   connect, disconnect)
3. **Rate limiting** en todos los endpoints
4. **CSRF obligatorio** en todos los formularios
5. **Roles de usuario**: admin y user
6. **Sesiones endurecidas**: httponly, samesite=Lax, strict_mode
7. **Sin credenciales hardcodeadas**: todo via `config/local.php`
8. **SRI** (Subresource Integrity) en todos los CDN

### Mecanismos de seguridad

| Capa | Mecanismo | Implementación |
|------|-----------|---------------|
| Sistema | Wrapper + sudoers | `/usr/local/bin/chilemon-rpt` + `/etc/sudoers.d/chilemon` |
| Red | Rate limiting | `RateLimiter` con SQLite (5 intentos/10 min login, 60 req/60s API) |
| Web | CSRF | Token por sesión con `hash_equals()`, rotación post-login |
| Web | Sesiones | `use_strict_mode`, `httponly`, `samesite=Lax`, timeout 30 min |
| Web | Roles | Admin panel restringido, whitelist de nodos |
| Código | SQL injection | PDO prepared statements en todas las queries |
| Código | PHP moderno | `declare(strict_types=1)` en todos los archivos |
| Audio | Token WebSocket | HMAC con timestamp + WEBRTC_SECRET, expiración 30s |
| Dependencias | SRI | Integridad verificada en CSS/JS de CDN |

### Arquitectura del wrapper

```
PHP (www-data)
  │
  │ sudo -n /usr/local/bin/chilemon-rpt nodes <id>
  ▼
/bin/bash (wrapper)
  │
  │ sanitiza parámetros (elimina ' " `)
  │
  │ case $CMD:
  │   nodes)       /usr/sbin/asterisk -rx "rpt nodes $LOCAL"
  │   stats)       /usr/sbin/asterisk -rx "rpt stats $LOCAL"
  │   connect)     /usr/sbin/asterisk -rx "rpt fun $LOCAL *3$REMOTE"
  │   disconnect)  /usr/sbin/asterisk -rx "rpt fun $LOCAL *1$REMOTE"
  │   *)           exit 1 (comando inválido)
  ▼
Asterisk (root)
```

---

## 8. Instalación y Despliegue

### Instalación del Agent (RPi)

```bash
# Requisitos: Raspberry Pi con ASL3 + Debian 12

# 1. Clonar
sudo git clone https://github.com/gismodes37/Chilemon.git /opt/chilemon

# 2. Ejecutar instalador
cd /opt/chilemon && sudo bash install/install_chilemon.sh

# 3. Acceder al dashboard
# http://<ip>/chilemon/
```

El instalador es **interactivo** y detecta automáticamente:
- Si es instalación nueva o actualización
- La configuración AMI desde `/etc/asterisk/manager.conf`
- Módulos ASL3 necesarios para WebRTC

**Pasos del instalador (13 pasos):**

| Paso | Acción |
|------|--------|
| 1 | Validar estructura del repositorio |
| 2 | Instalar dependencias (Apache, PHP, SQLite, Python) |
| 3 | Configurar datos del nodo (número ASL, AMI, etc.) |
| 4 | Preparar carpetas y permisos |
| 5 | Generar configuración local (`config/local.php`) |
| 6 | Configurar módulos ASL3 |
| 7 | Configurar Asterisk para WebRTC (IAX2, rpt.conf) |
| 8 | Instalar wrapper seguro + sudoers |
| 9 | Configurar Apache (alias /chilemon) |
| 10 | Habilitar proxy WebSocket en Apache |
| 11 | Validar PHP + inicializar base de datos |
| 12 | **Instalar puente WebRTC** (Python, systemd) |
| 13 | Crear usuario admin (nuevo) o verificar (actualización) |

### Instalación del Hub (Proxmox LXC)

```bash
# Requisitos: Proxmox host + Debian 12 LXC

# Crear contenedor
pct create 105 local:vztmpl/debian-12-standard_12.12-1_amd64.tar.zst \
  --hostname chilemon-hub --storage local-lvm \
  --memory 256 --cores 1 \
  --net0 name=eth0,bridge=vmbr0,ip=dhcp

# Instalar dependencias y clonar
pct start 105
pct exec 105 -- apt install -y apache2 php php-sqlite3 git
pct exec 105 -- git clone https://github.com/gismodes37/Chilemon-Hub.git /opt/chilemon-hub

# Configurar Apache y crear usuario admin
# Ver README del Hub para detalles
```

### Configuración post-instalación

**`config/local.php` (Agent):**
```php
<?php
return [
    'local_node' => '494780',              // N° de nodo ASL
    'ami_host' => '127.0.0.1',
    'ami_port' => 5038,
    'ami_user' => 'admin',
    'ami_pass' => 'contraseña_ami',
    'hub_url' => 'http://192.168.0.111',    // Opcional: hub central
    'registration_token' => 'abc123...',    // Opcional: token de registro
];
```

### Despliegue de actualizaciones

```bash
# En máquina de desarrollo:
git add . && git commit -m "feat: descripción"
git push origin main

# En la RPi (Agent):
ssh stg@192.168.0.116
cd /opt/chilemon && sudo git pull

# En el Hub:
ssh root@192.168.0.106
pct exec 105 -- bash -c 'cd /opt/chilemon-hub && git pull'
```

---

## 9. Roadmap

### v0.4.0 (Actual)
- ✅ WebRTC Audio Bridge — PTT desde el navegador
- ✅ Bridge IAX2 Direction Reversal
- ✅ Instalación mapa comunitario (Agent + Hub)
- ✅ Modal de registro con mapa interactivo + geocoding
- ✅ Mapa público full-screen con Google-style pins
- ✅ Seguridad: rate limiting, CSRF, roles, SRI, wrapper
- ✅ PHPUnit scaffold con tests
- ✅ Instalador automático completo

### v0.5.x (Próximo)
- 🔲 Validación end-to-end en producción
- 🔲 TURN/STUN para acceso remoto WebRTC
- 🔲 HTTPS/WSS con Let's Encrypt
- 🔲 Sesiones multi-usuario en el bridge
- 🔲 GitHub Actions CI

### v1.0 (Estable)
- 🔲 Producción lista
- 🔲 Documentación completa
- 🔲 Probado por la comunidad

---

> **ChileMon** — Desarrollado por CA2IIG
>
> 🇨🇱 Parte del ecosistema de radioafición chileno
>
> 📧 https://www.qsl.net/ca2iig/
>
> 💻 https://github.com/gismodes37/Chilemon
>
> 🗺️ Hub: https://github.com/gismodes37/Chilemon-Hub
