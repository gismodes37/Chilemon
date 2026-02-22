# ChileMon — Architecture (Rayando la cancha)

## 1.- Objetivo

ChileMon busca convertirse en la **plataforma moderna y extensible** para monitoreo, control y (a futuro) voz en nodos **ASL3**.

Principios:
- **No romper lo operativo**: estabilidad primero.
- **Evolución progresiva**: del panel tipo Supermon → plataforma.
- **Arquitectura modular**: permitir nuevas capacidades sin reescribir el core.
- **Bajo consumo**: debe correr bien en Raspberry Pi.
- **DB por defecto: SQLite** (siempre).

---

## 2.- Alcance por capas

### 2.1 Frontend (UI)
- HTML + Bootstrap (base)
- JS (fetch) para datos dinámicos
- Nada de “lógica de negocio” en templates.
- El UI consume **API interna JSON**.

### 2.2 API Layer (PHP)
- Endpoints bajo `/api/*`
- Respuestas **JSON**
- Control de sesión y autorización en middleware simple.
- El API llama a servicios internos (no toca SQL directo en controllers).

### 2.3 Core Services
- Integración Asterisk (IAX2 / AMI / CLI) encapsulada en un servicio.
- Servicios de sistema (temperatura, uptime, IP, etc.) encapsulados.
- Repositorios para DB SQLite (users, favorites, events, config).

### 2.4 Future Layer
- **WebSocket** para tiempo real (estado live)
- **WebRTC/SIP** en navegador (cuando el stack lo permita)
- Plugin system / módulos

---

## 📂 3.- Estructura de carpetas (propuesta)

```ruby
chilemon/
│
├── ARCHITECTURE.md                 (architecture_v1.0.md → renombrado recomendado)
├── README.md
├── VERSION
├── .gitignore
│
├── config/
│   └── app.php                     (APP_NAME, BASE_URL, ROOT_PATH, etc.)
│
├── app/
│   ├── Auth/
│   │   └── Auth.php                (requireLogin(), logout, etc.)
│   │
│   ├── Core/
│   │   ├── Database.php            (singleton PDO SQLite)
│   │   ├── Response.php            (helpers JSON, cuando se formalice API)
│   │   └── Router.php              (cuando se formalice API router)
│   │
│   ├── Services/
│   │   ├── AsteriskService.php      (futuro/iteración: acciones reales)
│   │   └── SystemService.php        (futuro/iteración: temp, ip, uptime)
│   │
│   ├── Repositories/
│   │   ├── UserRepository.php       (futuro/iteración)
│   │   ├── FavoriteRepository.php   (futuro/iteración)
│   │   └── EventRepository.php      (futuro/iteración)
│   │
│   └── Middleware/
│       └── RequireAuth.php          (futuro/iteración)
│
├── public/                         ← DocumentRoot (Apache Alias /chilemon → aquí)
│   ├── index.php                   ← Controller: session + carga datos + render vista
│   ├── logout.php                  ← Logout (si ya lo tienes separado)
│   │
│   ├── api/
│   │   └── v1/                      (si ya existe en tu repo; si no, queda planificado)
│   │       ├── auth.php
│   │       ├── system.php
│   │       ├── nodes.php
│   │       └── favorites.php
│   │
│   ├── views/
│   │   ├── dashboard.php           ← HTML + PHP mínimo (sin DB pesada)
│   │   ├── login.php               ← (si aplica)
│   │   └── partials/               ← (siguiente paso de limpieza)
│   │       ├── head.php
│   │       ├── header.php
│   │       └── footer.php
│   │
│   └── assets/
│       ├── css/
│       │   └── dashboard.css         ← (siguiente paso: mover <style> inline aquí)
│       ├── js/
│       │   └── dashboard.js         ← (ya activo: toggleTheme, refresh, etc.)
│       └── img/
│           └── Flag_of_chile.svg
│
├── install/
│   ├── apache-chilemon.conf         (si lo mantienes como referencia)
│   ├── sql/
│   │   └── schema.sql
│   └── migrations/
│       ├── 001_init.sql
│       └── 002_add_events.sql
│
├── bin/
│   ├── install.php
│   ├── create-user.php
│   ├── reset-password.php
│   ├── backup.php
│   ├── status.php
│   └── version.php
│
├── data/                           ← SQLite (no versionado)
│   └── chilemon.sqlite
│
├── logs/
├── backups/
│
└── docs/
    ├── index.html                  (GitHub Pages)
    ├── img/                        (screenshots del sitio docs)
    ├── INSTALL.md
    ├── ADMIN.md
    ├── CLI.md
    ├── CONTRIBUTING.md
    └── ROADMAP.md
```



## 4.- Reglas de oro (no negociables)

### 4.1 Separación UI vs Lógica
- `public/index.php` **no** ejecuta lógica pesada.
- Acciones (conectar/desconectar, favoritos, etc.) pasan por **API**.

### 4.2 DB SQLite es la base
- `data/chilemon.sqlite` es el default.
- No se versiona.
- Cambios de esquema deben ir por **migraciones** (ver 7).

### 4.3 Endpoints consistentes
- API siempre JSON:
  - `success: true/false`
  - `data: {}`
  - `error: {code, message}`
- 200 → éxito
- 400 → error validación
- 401 → no autenticado
- 403 → no autorizado
- 500 → error interno

### 🔐 4.4 Seguridad
- Todo endpoint (excepto login) requiere sesión válida.
- Passwords: `password_hash()` / `password_verify()`
- CSRF (Fase 2): token mínimo para acciones POST.
- Validación estricta de inputs.

### 4.5 No acoplar Asterisk a la UI
- Cualquier interacción Asterisk pasa por `AsteriskService`.
- Nada de “shell_exec” directo en templates.

---

## 5.- Contratos API (v1)

### 5.1 Auth
- `POST /api/v1/auth/login`
  - body: `{username, password}`
  - resp: `{success, data: {username}}`
- `POST /api/auth/logout`

### 5.2 System
- `GET /api/system/status`
  - resp: `{success, data: {ip, temp, uptime, load, version}}`

### 5.3 Nodes
- `GET /api/nodes`
  - resp: `{success, data: [{node, status, talker, lastSeen, ...}] }`
- `POST /api/nodes/connect`
  - body: `{node}`
- `POST /api/nodes/disconnect`
  - body: `{node}`

### 5.4 Favorites
- `GET /api/favorites`
- `POST /api/favorites/add` body `{node}`
- `POST /api/favorites/remove` body `{node}`

---

## 6.- Modelo de datos (mínimo)

Tablas mínimas recomendadas:
- `users(id, username, password_hash, created_at)`
- `favorites(id, user_id, node, created_at)`
- `events(id, user_id, type, payload_json, created_at)`
- `settings(id, key, value, updated_at)`

Notas:
- `payload_json` se usa para eventos operativos sin crear 10 tablas nuevas.
- `settings` guarda parámetros de ChileMon (no de Asterisk).

---

## 7.- Migraciones (para no romper instalaciones)

ChileMon debe soportar upgrades sin reinstalar.

Propuesta:
- `/install/migrations/001_init.sql`
- `/install/migrations/002_add_events.sql`
- etc.

El CLI `bin/install.php` debe:
- crear DB si no existe
- aplicar migraciones pendientes
- registrar versión de esquema

---

## 8.- Observabilidad / Logging

- Logs en `/logs/` (rotación simple si es necesario)
- Nivel INFO por defecto
- Nivel DEBUG solo habilitable por config

Eventos operativos importantes deben ir a:
- tabla `events` (DB)
- y/o log (según configuración)

---

## 9.- Roadmap técnico (orientado a arquitectura)

### Milestone 2 (cerrar base)
- UI consistente
- API v1 (auth/system/nodes/favorites)
- CLI estable (install, users, backup, status)

### Milestone 3 (diferenciación real)
- Estado live (WebSocket)
- Integración Asterisk mejorada (AMI si aplica)
- Acción “Call” como flujo real (aún sin WebRTC)

### Milestone 4 (ChileMon Voice)
- WebRTC/SIP endpoint por usuario
- Cliente en navegador
- Roles/permisos

---

## 10.- Decisiones explícitas

- PHP sigue como **core** por simplicidad y footprint.
- JS crece de forma incremental (fetch → live updates → WebRTC).
- Servicios que requieran tiempo real pueden separarse como proceso aparte.
- SQLite es default. Si un día se soporta otra DB, será opcional.
- ChileMon v1 es un monolito modular optimizado para entornos ligeros (Raspberry Pi).
- La separación en servicios externos se evaluará cuando el crecimiento lo requiera.

---

## 11.- Definición de “hecho”
Un cambio se considera “done” si:
- No rompe instalación limpia.
- Tiene endpoint API consistente (si aplica).
- Tiene documentación (README o docs).
- No introduce acoplamiento UI↔Asterisk directo.
- Pasa checklist básico de seguridad (auth + validación).

---
