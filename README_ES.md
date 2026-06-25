 <p align="center">
  <img src="docs/assets/img/chilemon-banner.svg" alt="ChileMon Banner">
</p>

<p align="center">
<img src="docs/assets/img/dashboard-main.png" width="900">
</p>

##

<p align="center">
Modern dashboard for monitoring and controlling AllStarLink nodes
</p>

<p align="center">
<img src="https://img.shields.io/badge/version-0.4.0-brightgreen">
<img src="https://img.shields.io/badge/php-8.2+-blue">
<img src="https://img.shields.io/badge/database-SQLite-green">
<img src="https://img.shields.io/badge/ASL3-compatible-green">
<img alt="Static Badge" src="https://img.shields.io/badge/Bootstrap-5-%20%237952B3">
<img alt="Static Badge" src="https://img.shields.io/badge/Java-Script-EFD81C">
<img alt="Static Badge" src="https://img.shields.io/badge/License-MIT-blue">

</p>

# 
<p align="center">
  <img src="docs/assets/img/chile-flag-brush.png" alt="ChileMon Banner" style="width:130px; height:auto;">
</p>

Dashboard moderno para monitoreo y control de nodos **AllStarLink (ASL3)**. ChileMon nace como una alternativa moderna inspirada en **Supermon**, diseñada para ofrecer una interfaz más clara, modular y fácil de instalar para operadores de nodos AllStar.

El objetivo del proyecto es proporcionar una herramienta simple y segura para visualizar y controlar la actividad de un nodo AllStarLink desde un navegador web.


# 🌐 Idiomas

🇪🇸 Español: README_ES.md  
🇬🇧 English: README.md

---

# 📡 ¿Qué es ChileMon?

ChileMon es una aplicación web que permite:

- monitorear nodos conectados
- conectar nodos remotos
- desconectar nodos
- visualizar la red de nodos enlazados
- revisar estadísticas del nodo
- administrar nodos favoritos
- visualizar actividad reciente

Todo desde una interfaz web moderna.

---

# ✨ Características principales

ChileMon actualmente incluye:

- Dashboard web para monitoreo de nodos AllStarLink
- Conexión y desconexión de nodos remotos
- Visualización de nodos conectados en tiempo real
- Estadísticas del nodo obtenidas desde Asterisk
- Modal para visualizar la red de nodos enlazados
- Gestión de nodos favoritos
- Registro de actividad reciente
- Ejecución segura de comandos mediante wrapper
- Instalador automático para sistemas ASL3
- **Sistema de autenticación con roles** (admin/user)
- **Panel de administración** para gestión de usuarios e info del sistema
- **Health check endpoint** (`/api/health.php`)
- **Rate limiting** en todos los endpoints API
- **Node whitelist** para control de connect/disconnect
- **Protección CSRF** en todos los formularios
- **Puente de Audio WebRTC** — Push-to-Talk (PTT) desde el navegador
- **Inversión de Dirección IAX2** — Bridge como servidor IAX2 para compatibilidad ASL3

---

# 🧠 Filosofía del proyecto

ChileMon fue diseñado con los siguientes principios:

- **No interferir con AllStarLink**
- **No modificar Asterisk**
- **No reemplazar Supermon**
- **Ser una herramienta modular**
- **Ser fácil de instalar**
- **Ser seguro**

ChileMon funciona como un **módulo adicional**, sin alterar la instalación base del nodo.

---

# 🏗 Arquitectura

ChileMon está construido utilizando tecnologías simples y robustas.

### Backend

- PHP 8+
- SQLite

### Frontend

- Bootstrap 5
- JavaScript
- AJAX

### Servidor

- Apache

### Puente WebRTC

- Python 3.11+ (aiortc + aiohttp + websockets)
- WebSocket para audio en tiempo real
- Protocolo IAX2 para integración con Asterisk

---

# 🔐 Seguridad

ChileMon **no ejecuta comandos Asterisk directamente desde PHP**.

En su lugar utiliza un wrapper seguro:

```
/usr/local/bin/chilemon-rpt
```

Este wrapper permite ejecutar únicamente comandos específicos:

- rpt nodes
- rpt stats
- rpt connect
- rpt disconnect

Los comandos se ejecutan mediante una regla sudo restringida para el usuario:

```
www-data
```

Esto evita la ejecución arbitraria de comandos en el sistema.

### Características de seguridad adicionales

- **Roles de usuario**: Admin y usuario regular. Panel admin (`/admin.php`) restringido a administradores
- **Rate limiting**: Middleware reutilizable que limita los endpoints API para prevenir abuso
- **Protección CSRF**: Cada formulario incluye un token CSRF por sesión validado con comparación timing-safe
- **Session hardening**: Modo estricto, cookies HTTP-only, SameSite=Lax, timeout de inactividad
- **Node whitelist**: Restringir qué nodos pueden conectarse/desconectarse desde el dashboard
- **Health check**: Endpoint público para monitoreo (`/api/health.php`)
- **Sin credenciales hardcodeadas**: Todos los secretos vienen de entorno o `config/local.php`

---

# ⚙ Cómo funciona ChileMon

ChileMon obtiene información del nodo ejecutando comandos `rpt` de Asterisk.

Ejemplo:

```bash
sudo -u www-data sudo -n chilemon-rpt nodes 494780
```


El resultado es procesado por el servicio:

```
app/Services/AslRptService.php
```

Este servicio interpreta la salida de Asterisk y extrae información como:

- nodos conectados
- estado del nodo
- estadísticas del sistema

Los datos procesados son mostrados en el dashboard web.

---

# 🖥 Dashboard

El dashboard principal permite visualizar:

- nodo local
- nodos conectados
- estado del sistema
- red de nodos enlazados
- actividad reciente

Además permite:

- conectar nodos
- desconectar nodos
- administrar favoritos

---

# 🔎 ChileMon vs Supermon

Supermon es la interfaz clásica utilizada por muchos nodos AllStarLink.

ChileMon está inspirado en ese concepto, pero introduce una arquitectura más moderna.

| Característica | Supermon | ChileMon |
|---|---|---|
| Interfaz | HTML clásico | Dashboard moderno |
| Autenticación | Login básico | Sistema de usuarios |
| Base de datos | No | SQLite |
| Instalación | Manual | Instalador automático |
| Arquitectura | Monolítica | Modular |
| Extensibilidad | Limitada | Preparado para expansión |

ChileMon **no reemplaza Supermon**, sino que ofrece una alternativa moderna para monitoreo y control del nodo.

---

# 📷 Capturas de pantalla

### Dashboard

![Dashboard](docs/assets/img/screenshot01.png)

### Red de nodos

![Network](docs/assets/img/network.png)

### Favoritos

![Favorites](docs/assets/img/favorites.png)

---

# 🚀 Opciones de Instalación

ChileMon puede instalarse directamente en un nodo **ASL3**. Se recomienda utilizar un sistema basado en Debian (como el oficial de ASL3).

### 📦 Opción 1: Instalación Automática (Recomendada)

El instalador detecta automáticamente si es una instalación **NUEVA** (setup interactivo completo) o una **ACTUALIZACIÓN** (solo agrega dependencias faltantes, preserva la configuración existente).

#### 1. Descargar ChileMon
```bash
sudo git clone https://github.com/gismodes37/Chilemon.git /opt/chilemon
```
*Esto crea la carpeta `/opt/chilemon` con todos los archivos del proyecto.*

#### 2. Ejecutar el instalador principal
```bash
cd /opt/chilemon && sudo bash install/install_chilemon.sh
```

El instalador ejecuta **pasos 13**:
1. Validar estructura del repositorio
2. Instalar dependencias base (Apache, PHP, SQLite, Python 3)
3. Configurar datos del nodo (NUEVO) o leer configuración existente (ACTUALIZACIÓN)
4. Preparar carpetas y permisos
5. Generar configuración local (NUEVO) o preservar existente (ACTUALIZACIÓN)
6. Configurar módulos ASL3 de Asterisk
7. Configurar Asterisk para WebRTC (IAX2 + rpt.conf phonelogin)
8. Instalar wrapper seguro + sudoers
9. Configurar alias de Apache
10. Habilitar proxy WebSocket de Apache
11. Validar módulos PHP + inicializar base de datos
12. **Instalar puente WebRTC** (PTT desde el navegador — aiohttp, aiortc, websockets, systemd service)
13. Crear usuario administrador (NUEVO) o ejecutar verificación (ACTUALIZACIÓN)

> ⚠️ **Instalación NUEVA**: Ten a mano tu **número de nodo** ASL y la **clave AMI** (de `/etc/asterisk/manager.conf`). El instalador te las va a pedir.

> 💡 **ACTUALIZACIÓN (sistema existente)**: El instalador detecta `config/local.php` y omite todos los prompts — solo agrega dependencias faltantes, configura WebRTC/WebSocket, y ejecuta una verificación completa.

> 🎯 **WebRTC Audio Bridge**: El puente para hablar por radio desde el navegador ya **se instala automáticamente** en el paso 12. No necesitas ejecutar un script aparte. Solo configura las credenciales en `/etc/default/chilemon-webrtc` después de la instalación.

---

## 🧪 Opción 2: Rama Main (Último desarrollo)

Clonar y ejecutar el instalador — igual que la Opción 1:

```bash
sudo git clone https://github.com/gismodes37/Chilemon.git /opt/chilemon
cd /opt/chilemon && sudo bash install/install_chilemon.sh
```

El instalador configura automáticamente Apache, PHP, SQLite, el wrapper de seguridad, soporte WebRTC/WebSocket, y ejecuta una verificación. ChileMon queda listo en `http://tu_ip/chilemon`.

---

## 🖥️ Opción 3: Máquina Virtual con Debian 13 + ASL3 + ChileMon

Para quienes quieren un sistema limpio y dedicado sin tocar su instalación actual. Ideal para probar ChileMon en un entorno aislado o como servidor permanente.

### 3.1 Crear la Máquina Virtual
- **VirtualBox**, **Proxmox VE**, o **VMware** — el que prefieras
- **SO invitado**: Debian 13 (Trixie) — descargar desde [debian.org](https://www.debian.org)
- **Mínimo**: 1 CPU, 1 GB RAM, 10 GB disco
- **Red**: Bridged o NAT con reenvío de puertos (80/443 para el dashboard, 4569 para IAX2, 5038 para AMI)

### 3.2 Instalar Debian 13
Sigue la instalación estándar de Debian 13. Recomendación: solo entorno base (sin desktop) para máxima compatibilidad con ASL3.

### 3.3 Instalar ASL3
```bash
sudo apt-get update && sudo apt-get upgrade -y
sudo apt-get install -y wget
wget https://apt.allstarlink.org/repos/install
sudo bash install
```

### 3.4 Instalar ChileMon
```bash
cd /opt
sudo git clone -b v0.4.0 https://github.com/gismodes37/Chilemon.git chilemon
cd chilemon
sudo bash install/install_chilemon.sh
```
> El instalador detecta si es sistema nuevo o actualización. En modo NUEVO te pedirá el número de nodo ASL y la clave AMI.

### 3.5 Configurar WebRTC Bridge
El bridge se instala automáticamente (Paso 12). Solo edita las credenciales reales:
```bash
sudo nano /etc/default/chilemon-webrtc
# Cambiar IAX_PHONE_PASS y WEBRTC_SECRET
sudo systemctl restart chilemon-webrtc
```

### 3.6 Acceder al Dashboard
Abrí `http://<ip-de-tu-vm>/chilemon` — PTT desde el navegador incluido 🇨🇱

---

# 📂 Estructura del proyecto

    chilemon/
      │
      ├── app/
      │   ├── Asterisk/         ← AslRptService, NodeTracker
      │   ├── Auth/             ← Auth (login, csrf, roles)
      │   ├── Controllers/      ← Dashboard, NodeApi
      │   ├── Core/             ← Database, RateLimiter
      │   ├── Services/         ← AslRptService (legacy compat), WebRTCBridge
      │   │   └── WebRTCBridge/ ← Python bridge (ami_client, iax2, audio, server)
      │   └── Views/            ← auth/login.view.php
      │
      ├── config/
      │   ├── app.php           ← Bootstrap + AMI + seguridad
      │   └── database.php
      │
      ├── data/
      │   └── chilemon.sqlite   (no versionar)
      │
      ├── logs/
      │
      ├── public/
      │   ├── index.php         ← Dashboard
      │   ├── login.php
      │   ├── logout.php
      │   ├── admin.php         ← Panel admin (usuarios + sistema)
      │   ├── api/
      │   │   ├── health.php    ← Health check endpoint
      │   │   ├── nodes.php
      │   │   ├── stats.php
      │   │   ├── connect.php
      │   │   ├── disconnect.php
      │   │   ├── delete_node.php
      │   │   ├── system_action.php
      │   │   ├── favorites/    ← CRUD favoritos
      │   │   └── ami/          ← AMI status endpoints
      │   └── views/
      │       └── partials/     ← head, header, footer, scripts
      │
      ├── tests/                ← Pruebas PHPUnit
      ├── install/
      ├── bin/
      ├── phpunit.xml.dist
      ├── README.md
      ├── README_ES.md
      ├── CHANGELOG.md
      ├── LICENSE.md
      └── .gitignore


---

# 📈 Roadmap

<img alt="Static Badge" src="https://img.shields.io/badge/Version-0.4.0-brightgreen">

**Release Actual** — Overhaul de seguridad, PTT Audio Bridge, inversión IAX2, rate limiting, CSRF, roles, panel admin, health check

<img alt="Static Badge" src="https://img.shields.io/badge/Version-0.5.x-blue">

**Siguiente** — Despliegue y pruebas en producción, TURN/STUN para PTT remoto, HTTPS/WSS, sesiones multiusuario, imagen production

<img alt="Static Badge" src="https://img.shields.io/badge/Version-1.0-green">

**Release estable** — Listo para producción, documentación completa, probado por la comunidad

---

# 📦 Releases

## v0.4.0
- **Puente de Audio WebRTC**: Push-to-Talk (PTT) desde el navegador vía puente Python — audio WebRTC desde el dashboard a Asterisk IAX2
- **Inversión IAX2**: El bridge ahora actúa como servidor IAX2; Asterisk lo llama vía AMI Originate (corrige filtro callno=0 de ASL3)
- **Cliente AMI**: Nuevo cliente TCP asíncrono para Asterisk Manager Interface con reconexión y backoff exponencial
- **Soporte DTMF**: DTMF en llamada para keying/unkeying de nodos ASL (inmediato, no futuro)
- **Overhaul de seguridad**: Eliminación de credenciales hardcodeadas, local.php obligatorio, SRI en todos los CDN
- **Rate limiting**: Middleware RateLimiter aplicado a todos los endpoints API (12+ endpoints)
- **Roles de usuario**: Sistema admin/user con control de acceso en acciones sensibles
- **Protección CSRF**: Tokens por sesión con validación timing-safe en todos los formularios
- **Panel admin**: Nuevo `/admin.php` con gestión de usuarios (crear/eliminar/promover) e info del sistema
- **Health check**: Nuevo endpoint `/api/health.php` (30 req/min, modo degradado en fallo DB)
- **Node whitelist**: Lista configurable de nodos permitidos para connect/disconnect
- **Calidad**: `declare(strict_types=1)` en 13 archivos, require_once unificado a ROOT_PATH
- **PHPUnit scaffold**: `phpunit.xml.dist` + directorio tests con pruebas de Auth e infraestructura

## v0.3.1
- **Entorno Local (Docker)**: Soporte completo para desarrollo local mediante Docker Compose.
- **Mock de Comandos**: Script simulador para emular actividad RX/TX en entornos locales.

## v0.3.0
- **Integración total de Favoritos**: Visualización de estrellas y alias en el dashboard.
- **Botón de Toggle rápido**: Gestión directa de favoritos desde la tabla.
- **Instalador simplificado**: Nuevo proceso de instalación en un solo bloque.

## v0.2.x
- **Monitoreo en tiempo real**: Indicadores RX (verde) y TX (rojo) dinámicos.
- **Soporte EchoLink**: Identificación automática de conexiones EchoLink.
- **Wrapper v0.2.3**: Mejoras de seguridad y limpieza de parámetros.

## v0.1.0 (Legacy)

Primer release funcional de ChileMon.

Incluye:

- Dashboard operativo
- Conexión y desconexión de nodos
- Visualización de red de nodos
- Gestión de favoritos
- Actividad reciente
- Instalador automático
- Integración con AllStarLink

---

# ❤️ Apoyar ChileMon

ChileMon es un proyecto independiente desarrollado para la comunidad de radioaficionados y usuarios de **AllStarLink**. Si este proyecto te resulta útil, puedes apoyar su desarrollo con una **donación voluntaria**.

### ¿En qué ayudan las donaciones?

El desarrollo de ChileMon se realiza de forma independiente. Las donaciones ayudan a sostener el proyecto y permiten seguir mejorándolo.

- Desarrollo continuo del dashboard
- Pruebas en nodos reales AllStarLink
- Mejoras de seguridad y estabilidad
- Documentación y sitio web del proyecto
- Mantenimiento y evolución futura

### Formas de apoyar

- **PayPal:** [Apoyar Chilemon](https://www.paypal.com/ncp/payment/J56JZF5CPRBVG)
- **GitHub Sponsors:** [gismodes37](https://github.com/sponsors/gismodes37)

También puedes apoyar el proyecto colaborando con código, reportando errores o probando ChileMon en nodos reales.

> ChileMon es un proyecto comunitario para radioaficionados. Las donaciones son completamente voluntarias y ayudan a sostener el desarrollo del proyecto.

---


# 🤝 Contribuciones

Las contribuciones son bienvenidas.

Si deseas colaborar:

1. Haz un fork del repositorio
2. Crea una rama nueva
3. Envía un pull request

---

## 📄 Licencia

ChileMon se distribuye bajo licencia [MIT](LICENSE).

![License](https://img.shields.io/badge/license-MIT-blue)


---

# 🇨🇱 ChileMon

Project developed for the Spanish-speaking AllStarLink community.



