# Changelog

Todos los cambios importantes de este proyecto serán documentados en este archivo.

El formato está basado en **Keep a Changelog**  
https://keepachangelog.com/es-ES/1.1.0/

y este proyecto utiliza **Semantic Versioning**  
https://semver.org/lang/es/

---

# [0.5.0] - 2026-07-02

## ✨ One-Click Update + Audio Bridge Fixes

### 📡 Añadido
- **Actualización One-Click**: Botón de actualización desde el dashboard — detecta nueva versión, ejecuta git pull + restart webrtc + reload apache. Incluye badge de versión en el header.
- **Mapa comunitario interactivo**: Leaflet + OpenStreetMap con picker de coordenadas clickeable y geocoding de direcciones vía Nominatim.
- **Registro cross-origin**: Nodos agentes se registran en el hub sin sesión preexistente.
- **WS grace period**: Reconexiones breves de WebSocket no desconectan el bridge inmediatamente.

### 🔧 Corregido
- **Audio RX — Chrome fix**: AudioContext se crea solo dentro de gesto del usuario (click), evitando suspensión automática de Chrome después de ~30s.
- **Audio RX — keepalive**: Timer de keepalive cada 25s (buffer silencioso) para mantener AudioContext activo.
- **Audio RX — simplificación**: Eliminada lookahead scheduling queue, retorno a reproducción directa con stop del source anterior.
- **Audio TX — sample rate**: Corrección de mismatch entre AudioContext (48kHz) y mic hardware, eliminando error de AudioNodes cross-origin.
- **Audio TX — slow-motion fix**: Corrección de audio transmitido en cámara lenta por sample rate mismatch.
- **IAX2 auto-reoriginate**: Bridge reinicia automáticamente la llamada IAX2 si se cae.
- **send_voice return value**: Corregido `IAX2Call.send_voice()` para retornar bool y validar estado ACTIVE.
- **Installer banner**: Corregido protocolo en banner final — ahora usa el detectado (http/https) en vez de siempre https.
- **Installer auto-generate secrets**: Genera `webrtc_secret` e `iax_phone_pass` aleatorios automáticamente.
- **Installer audioop-lts**: Instalación automática de `audioop-lts` para compatibilidad Python 3.13+.

---

# [0.4.0] - 2026-06-21

## ✨ WebRTC Audio Bridge + Seguridad v0.4.x

Esta actualización marca dos grandes áreas: el nuevo **Puente de Audio WebRTC** para Push-to-Talk desde el navegador, y un **overhaul completo de seguridad**.

### 📡 Añadido
- **Puente de Audio WebRTC**: Push-to-Talk (PTT) desde el navegador vía Python bridge (aiortc + aiohttp). Audio WebRTC (OPUS) → IAX2 (ulaw) hacia Asterisk.
- **Widget PTT en Dashboard**: Botón flotante con espacio/click para transmitir, barra de volumen, indicador de estado.
- **Inversión de Dirección IAX2 (bridge-reversal)**: El bridge ahora actúa como servidor IAX2; Asterisk lo llama mediante AMI `Originate` con `Async: true`, evitando el filtro callno=0 de ASL3.
- **Cliente AMI asíncrono**: Nuevo `ami_client.py` con reconexión automática y exponential backoff.
- **Soporte DTMF**: Envío de DTMF durante llamada para key/unkey del nodo ASL (implementado inmediato, no futuro).
- **Overhaul de seguridad**: Eliminación de credenciales AMI hardcodeadas, `config/local.php` obligatorio, SRI en todos los CDN.
- **Rate limiting**: Middleware RateLimiter aplicado a todos los endpoints API (12+ endpoints).
- **Roles de usuario**: Sistema admin/user con control de acceso en acciones sensibles.
- **Protección CSRF**: Tokens por sesión con validación timing-safe en todos los formularios.
- **Panel admin**: Nuevo `/admin.php` con gestión de usuarios (crear/eliminar/promover) e info del sistema.
- **Health check**: Nuevo endpoint `/api/health.php` (30 req/min, modo degradado en fallo DB).
- **Node whitelist**: Lista configurable de nodos permitidos para connect/disconnect.
- **Rate limit whitelist**: IPs de confianza exentas de rate limiting.
- **PHPUnit scaffold**: `phpunit.xml.dist` + tests de Auth e infraestructura.

### 🔧 Corregido
- IAX2 mini frames: Corrección de ACK innecesario en mini frames de voz (fire-and-forget).
- IAX2 seqno tracking: `iseqno` ahora se actualiza condicionalmente, no se copia ciegamente.
- Código muerto: Eliminados `ami/connect.php` y `ami/disconnect.php` vacíos.
- `declare(strict_types=1)` añadido a 13 archivos PHP faltantes.
- Refactor de sesiones: Todo acceso a `$_SESSION` centralizado mediante métodos de `Auth`.

---

# [0.3.1] - 2026-04-27

## ✨ Entorno Local y Docker

### 📡 Añadido
- **Entorno Local (Docker)**: Soporte completo para desarrollo local mediante Docker Compose, sin necesidad de un nodo AllStarLink físico.
- **Mock de Comandos**: Script simulador de `chilemon-rpt` para emular actividad RX/TX, nodos conectados y estadísticas en entornos Windows/Mac/Linux.
- **Documentación Bilingüe**: Actualización de los `README` (Inglés y Español) integrando los pasos para arrancar y probar el contenedor.

---

# [0.3.0] - 2026-03-21

## ✨ Integración de Favoritos y Simplificación (v0.3.x)

Esta actualización marca la integración total del sistema de favoritos y la optimización radical de la experiencia de instalación para usuarios finales.

### 📡 Añadido
- **Favoritos en Dashboard**: Ahora los nodos favoritos muestran una **estrella amarilla** y su **Alias personalizado** directamente en la tabla principal.
- **Toggle Rápido de Favoritos**: Nuevo botón en la columna de acciones para agregar o quitar favoritos con un solo clic, sin abrir paneles adicionales.
- **Instalación "Un Solo Paso"**: Documentación actualizada con un comando único de `git clone` y ejecución automática para usuarios no técnicos.
- **Refresco Inteligente**: El sistema de actualización en tiempo real (SSE) ahora refresca automáticamente el estado de favoritos y los alias si cambian.

### 🔧 Corregido
- **Sincronización de Nombres**: Se corrigió el problema donde los alias de favoritos no se mostraban en la carga inicial de la página.
- **Consistencia de UI**: Los botones de acción ahora tienen títulos (tooltips) claros para facilitar el uso a nuevos operadores.

---

# [0.2.3] - 2026-03-20

## ✨ Novedades v0.2.x (Dashboard Activo)

Esta actualización transforma el Dashboard de una vista estática a un centro de monitoreo vivo y robusto.

### 📡 Añadido
- **Monitoreo en Tiempo Real**: Indicadores visuales de **RX (Verde)** y **TX (Rojo)** que parpadean según la actividad del nodo.
- **Soporte EchoLink Nativo**: El Dashboard ahora reconoce y procesa conexiones de la red EchoLink (Prefijos 8 o 3 automáticos).
- **Limpieza de Prefijos**: La tabla de nodos ahora es más legible, eliminando prefijos técnicos de AllStarLink (`T`, `C`, `M`).
- **Instalador v0.2.3**: Nuevo proceso de instalación automatizada (`bash install/install_chilemon.sh`) con configuración de permisos de DB y wrapper robusto.

### 🔧 Corregido
- **Compatibilidad PHP 8.2+**: Arreglado el error de `ob_implicit_flush` que congelaba el refresco del Dashboard.
- **Wrapper Seguro**: Limpieza de parámetros (`tr -d "'\""`) para evitar fallos de ejecución desde PHP.
- **Parser de Tiempo**: Ahora se procesan correctamente las estadísticas con milisegundos de ASL3.
- **Estabilidad DB**: Corrección de bloqueos de SQLite mediante limpieza manual de archivos journal.

---

# [0.1.0] - 2026-03-13

## 🚀 Primer release funcional

Primera versión pública de ChileMon.

Este release introduce el dashboard inicial para monitoreo y control de nodos AllStarLink.

---

## ✨ Añadido

- Dashboard web para monitoreo de nodos AllStarLink
- Visualización de nodos conectados mediante `rpt nodes`
- Lectura de estadísticas del nodo mediante `rpt stats`
- Conexión de nodos remotos desde el dashboard
- Desconexión de nodos remotos desde el dashboard
- Visualización de red de nodos enlazados mediante modal
- Sistema de autenticación de usuarios
- Gestión de nodos favoritos
- Registro de actividad reciente
- Instalador automático para sistemas ASL3
- Wrapper seguro `chilemon-rpt` para ejecución controlada de comandos Asterisk
- Base de datos SQLite para almacenamiento local
- Interfaz moderna basada en Bootstrap

---

## 🔐 Seguridad

- Uso de wrapper seguro para comandos `rpt`
- Restricción de ejecución mediante sudo para usuario `www-data`
- Protección básica de autenticación

---

## ⚙ Instalación

- Script de instalación automática `install_chilemon.sh`
- Configuración automática de Apache
- Inicialización automática de base SQLite
- Creación de usuario administrador

---

## 📌 Notas

ChileMon está inspirado en el concepto de **Supermon**, pero implementado con una arquitectura moderna y modular.

Este proyecto **no modifica ni reemplaza Supermon**, y puede coexistir con instalaciones existentes de AllStarLink.

---

## 🔮 Próximas mejoras

- Indicadores de actividad RX / TX en tiempo real
- Mejoras visuales en el dashboard
- Optimización de refresco automático
- Eventos extendidos del nodo