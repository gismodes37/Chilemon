# 🇨🇱 ChileMon

**ChileMon** es una plataforma de monitoreo y visualización para nodos **AllStarLink 3 (ASL3)**, inspirada en el estilo clásico de Supermon, pero desarrollada con una arquitectura moderna, segura y mantenible en PHP.

Diseñado para ejecutarse como sub-ruta bajo el nodo ASL:

 ```ruby
 https://nodeXXXXX.local/chilemon/
 ```
 - ChileMon no reemplaza ASL ni modifica Asterisk.
 - Funciona como un módulo independiente que añade:
   - Persistencia real de datos mediante SQLite
   - Sistema de autenticación propio
   - Información avanzada del sistema
   - Actualización en tiempo real (SSE)
   - Controles de sistema seguros
   - Arquitectura profesional PSR-4 preparada para crecer
   - Instalación limpia y reproducible desde repositorio
---

## 🎯 Objetivo del Proyecto

Supermon cumple una función visual básica, pero no incorpora:
 - Base de datos persistente
 - Gestión de usuarios
 - Modularidad extensible
 - Separación de capas
 - Flujo moderno de desarrollo

ChileMon nace para cubrir esa brecha, manteniendo compatibilidad visual y conceptual con el ecosistema ASL.

---

## 🚀 Características

- Dashboard estilo Supermon moderno (100% Bootstrap 5)
- Base de datos **SQLite** (ligera, portable y sin dependencias externas)
- Compatible con Raspberry Pi
- Soporte para sub-path `/chilemon`
- **Live View con Server-Sent Events (SSE)** — actualización de nodos en tiempo real sin recargar la página
- **Buscador de nodos en vivo** — filtrado instantáneo de la tabla mientras escribes
- **Controles de sistema seguros** — Reiniciar Asterisk, Reiniciar Apache y Apagar Nodo desde el dashboard con confirmación y permisos controlados
- **Autodetección dinámica del nodo ASL** — detecta el nodo desde el hostname del sistema o `rpt.conf`
- **Autoloader PSR-4 nativo** — arquitectura profesional sin Composer ni dependencias externas
- **Wrapper seguro `chilemon-rpt`** — todas las acciones pasan por un script bash pre-autorizado con sudoers acotado
- Información del sistema (IP, temperatura CPU, Memoria, Hostname, Zona Horaria, PHP, etc.)
- Favoritos por usuario con alias y descripción personalizada
- Registro de actividad reciente (connect/disconnect/favoritos)
- Sin dependencia de MySQL/MariaDB
---

## 🧱 Filosofía

ChileMon:

  ❌ No interfiere con ASL

  ❌ No modifica configuración de Asterisk

  ❌ No altera la landing original del nodo

  ✅ Opera como módulo independiente

  ✅ Respeta el estilo Supermon

  ✅ Añade estructura, persistencia y escalabilidad

---

## 📦 Requisitos

- Debian 12 (ASL3 Pi Appliance recomendado)
- PHP 8.2+
- Apache2
- Extensiones PHP: `PDO`, `pdo_sqlite`, `sqlite3`

---

## 🛠 Instalación en Raspberry / Nodo ASL3 (Producción)

### El usuario entra al nodo y abre la terminal o entra por SSH:

### 1. Conectarse al nodo
Abra la terminal local del nodo o conéctese por SSH desde otro equipo:

```bash
ssh usuario@IP_DEL_NODO
```
---
### 2. Instalar Git

Actualice los paquetes:

```bash
sudo apt update
```
Instale Git si aún no está disponible:

```bash
sudo apt install -y git
```
---
### 3. Descargar ChileMon desde el repositorio oficial

Ubíquese en /opt y clone el proyecto:

```bash
cd /opt
sudo git clone https://github.com/gismodes37/Chilemon.git chilemon
```

### 4. Entrar a la carpeta del proyecto

```bash
cd /opt/chilemon
```
---

### 5. Ejecutar el instalador automático

```bash
sudo bash install/install_chilemon.sh
```
---

### 6. Responder los datos solicitados por el instalador

Durante la instalación se solicitarán los siguientes datos:

 - Número de nodo ASL local
 - IP o nombre del servidor

   Ejemplo:
     - Nodo local: `12345`
     - Servidor: `192.168.1.20`  ó  `node12345`

 - Texto descriptivo del nodo
 - Host AMI (se autodetecta desde `manager.conf`)
 - Puerto AMI (se autodetecta)
 - Usuario AMI (se autodetecta)
 - Clave AMI (ingreso oculto por seguridad)

La clave AMI es la misma que configuró al instalar su nodo ASL.

---

### 7. Validar acceso al sistema desde el navegador

Al finalizar, el instalador mostrará la URL sugerida para abrir ChileMon en el navegador:

 - por ejemplo:
     ```
     http://IP_DEL_NODO/chilemon
     ```

 - Si su servidor ya tiene HTTPS activo, también puede probar:
     ```
     https://IP_DEL_NODO/chilemon
     ```

---


## 🔐 Autenticación
 - Sistema de login propio con:
   - Tabla `users` en SQLite
   - Passwords con `password_hash()`
   - Sesión PHP con tokens CSRF
   - Logout seguro
   - Protección de rutas y endpoints API
 - El usuario inicial se crea durante la instalación por consola.
---

## 🔒 Seguridad del Sistema

ChileMon implementa un modelo de seguridad por capas:

- **CSRF en formularios** — token validado en cada POST.
- **Sesión centralizada** — login/logout consistentes con endpoints protegidos.
- **Wrapper seguro `chilemon-rpt`** — script bash que actúa como única puerta de acceso a comandos de Asterisk y del sistema operativo:
  - `nodes`, `stats`, `connect`, `disconnect` → comandos Asterisk
  - `sys-restart-asterisk`, `sys-restart-apache`, `sys-poweroff` → controles de sistema
- **sudoers acotado** — solo el wrapper tiene permiso `NOPASSWD`, PHP no puede ejecutar nada más.
- **Configuración local no versionada** — `config/local.php` con credenciales AMI no se sube al repositorio.

---

## 📂 Estructura del Proyecto

```
chilemon/
├── app/
│   ├── Auth/
│   │   └── Auth.php
│   ├── Controllers/
│   │   ├── DashboardController.php
│   │   ├── NodeApiController.php
│   │   └── SystemController.php
│   ├── Core/
│   │   ├── Database.php
│   │   └── NodeLogger.php
│   ├── Services/
│   │   └── AslRptService.php
│   ├── Helpers/
│   └── autoload.php
├── bin/
│   └── install.php
├── config/
│   ├── app.php
│   └── local.php          (generado por instalador, no versionado)
├── data/
│   └── chilemon.sqlite    (generado por instalador, no versionado)
├── install/
│   ├── install_chilemon.sh
│   └── sql/
│       └── schema.sql
├── public/
│   ├── index.php
│   ├── login.php
│   ├── logout.php
│   ├── views/
│   │   ├── dashboard.php
│   │   └── partials/
│   ├── assets/
│   │   ├── js/
│   │   │   └── dashboard.js
│   │   └── css/
│   └── api/
│       ├── nodes.php
│       ├── stream_nodes.php    (SSE endpoint)
│       ├── connect.php
│       ├── disconnect.php
│       ├── favorites.php
│       └── system_action.php
├── docs/
├── logs/
├── README.md
└── .gitignore
```

---

## 🧠 Arquitectura

### ChileMon:

- NO reemplaza ASL
- NO modifica configuración de Asterisk
- NO altera la landing original del nodo
- Funciona como módulo independiente bajo Apache

### Componentes principales:

| Capa | Componente | Descripción |
|------|-----------|-------------|
| **UI** | `public/views/*` | Vistas Bootstrap 5 con tema claro/oscuro |
| **JS** | `public/assets/js/dashboard.js` | Lógica frontend: SSE, buscador, acciones |
| **API** | `public/api/*` | Endpoints REST + SSE protegidos por sesión |
| **Controllers** | `app/Controllers/*` | Controladores MVC por dominio |
| **Services** | `app/Services/AslRptService.php` | Servicio centralizado para Asterisk y sistema |
| **Auth** | `app/Auth/Auth.php` | Autenticación, sesión y CSRF |
| **DB** | SQLite | `data/chilemon.sqlite` |
| **Config** | `config/app.php` + `config/local.php` | Configuración global + local por nodo |
| **Wrapper** | `/usr/local/bin/chilemon-rpt` | Wrapper bash seguro (Asterisk + sistema) |
| **Autoloader** | `app/autoload.php` | PSR-4 nativo sin Composer |

---

## 🔄 Flujo de Desarrollo

 - Se desarrolla siempre en local:

   - PC → GitHub → Raspberry

- Nunca modificar producción directamente.
- Branches recomendadas:
    
     - main → estable

     - dev → desarrollo

- Tags semánticos:

     - v0.4.0
     - v0.5.0
     - v1.0.0
---

## 🚀 Estado del Proyecto

### Milestone 1 – ✅ Completado (Core seguro)

 - Base de datos SQLite estable
 - Eliminación total de MySQL/MariaDB
 - Dashboard estilo Supermon funcional
 - Soporte sub-path /chilemon
 - Información del sistema (CPU, IP, Hostname, etc.)
 - Login de usuarios implementado
 - Logout funcional
 - Permisos productivos configurados
 - Flujo Local → GitHub → Producción definido

---

### Milestone 2 – ✅ Completado (Supermon+ UX)

 - Botón y ventana modal de Favoritos desde el header
 - CRUD de favoritos por usuario (Nodo / Alias / Descripción editable)
 - Acción "Conectar" desde favoritos con confirmación
 - Botón "Desconectar" operativo desde el dashboard
 - Registro de actividad reciente (connect / disconnect / favorite*)
 - APIs protegidas por sesión (Unauthorized si no hay login)
 - Integración UI consistente (header + dashboard + estado)

---

### Milestone 3 – ✅ Completado (Integración operativa real)

 - **Conexión real a nodos ASL** vía Asterisk CLI (wrapper seguro `chilemon-rpt`)
 - **Autoloader PSR-4 nativo** — carga automática de clases sin Composer
 - **Controladores MVC** — `DashboardController`, `NodeApiController`, `SystemController`
 - **Servicio centralizado** — `AslRptService` para todas las operaciones con Asterisk
 - **Live View con SSE** — actualización en tiempo real de la tabla de nodos
 - **Buscador de nodos en vivo** — filtrado instantáneo sin depender del servidor
 - **Controles de sistema seguros** — Reiniciar Asterisk, Reiniciar Apache, Apagar Nodo
 - **Autodescubrimiento del nodo** — detección automática desde hostname o `rpt.conf`
 - **Corrección de rutas API** — endpoints `connect.php`, `disconnect.php`, `stream_nodes.php` funcionales
 - **Protección CSRF** en todas las acciones POST

---

### Milestone 4 – 🧭 En planificación (Voice / control avanzado)

Objetivo: ampliar las capacidades de control de audio y telemetría avanzada.

 - Funciones de llamada por etapas
 - Monitor / Transmit con permisos
 - Mejoras de telemetría avanzada
 - Hardening extra según experiencia real de operación

---

## 🔮 Proyección Futura – Sistema de Versionado y Actualización

ChileMon contempla, en fases posteriores de desarrollo, la incorporación de un sistema formal de versionado visible y detección de nuevas versiones disponibles.

Este sistema podría incluir:

 - Visualización de versión instalada en la interfaz.

 - Verificación controlada de versiones disponibles.

 - Notificación discreta de actualizaciones.

 - Mecanismo seguro y supervisado de actualización.

Esta funcionalidad no será implementada hasta que exista una infraestructura clara de distribución y control, priorizando siempre:

 - Estabilidad del nodo.

 - No interferencia con ASL.

 - Control total por parte del administrador.

 - Integridad del repositorio y del sistema instalado.

ChileMon no incorporará mecanismos automáticos que comprometan el entorno productivo sin consentimiento explícito del administrador.

Esta característica formará parte de un milestone específico dedicado al ciclo de vida del producto.

## 👨‍💻 Autor

<ul>
 <li> Desarrollado en La Serena, Chile</li>
 <li> CA2IIG – Guillermo Ismodes López</li>
 <li> Servicios Tecnológicos Generales SpA</li>
 <li> La Serena - Chile</li>
</ul>
---

## 📜 Licencia

 - Licencia MIT para proyecto comunitario.
---

