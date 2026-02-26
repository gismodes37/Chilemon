## 🇨🇱 ChileMon — Avance Técnico
## 🔐 Security Phase 1 (Hardening Base)

## 1. Objetivo

Establecer una base de seguridad sólida para ChileMon antes de continuar con nuevas funcionalidades (Milestone 2+).

Fase 1 busca:

- Proteger autenticación
- Reducir superficie de ataque
- Asegurar integridad de sesiones
- Formalizar buenas prácticas operativas
- Preparar el proyecto para crecimiento público

Esta fase NO incluye aún:
- CSRF tokens completos (Fase 2)
- WebSocket hardening
- Roles avanzados
- WebRTC security

---

## 2. Principios

1. Seguridad proporcional al entorno (Raspberry + ASL3)
2. Sin romper instalación limpia
3. Sin sobre-ingeniería innecesaria
4. Bajo consumo de recursos
5. Compatible con SQLite

---

## 3. Autenticación (Login Hardening)

### 3.1 Passwords

- `password_hash()` (DEFAULT)
- `password_verify()`
- No almacenar passwords en texto plano
- No logs con credenciales

---

### 3.2 Session Security

Requisitos:

- `session_regenerate_id(true)` tras login
- `httponly = true`
- `secure = true` (si HTTPS)
- `samesite = Lax`
- Path restringido a `/chilemon/`

Ejemplo mínimo esperado:
   ```php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/chilemon/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
   ```

---

## 4. Rate Limiting (Protección contra fuerza bruta)
      
   ### 4.1 Nueva tabla requerida

   ```php
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT,
    ip_address TEXT NOT NULL,
    success INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
   ```

---

   ### 4.2 Política mínima

 - Máx 5 intentos fallidos
 - Ventana: 15 minutos
 - Bloqueo temporal: 15 minutos

Regla:

 Si existen >5 intentos fallidos para:
 - mismo username OR
 - misma IP
 
 → rechazar login antes de consultar password.

 ---

   ## 5. API Versionada Obligatoria

Toda API debe vivir bajo:

v1.
   ```php
   /api/v1/
   ```

Ejemplos correctos:

   ```php
   POST /api/v1/auth/login
   POST /api/v1/auth/logout
   GET  /api/v1/system/status
   GET  /api/v1/nodes
   POST /api/v1/nodes/connect
   ```

No se permiten endpoints fuera de versión.

Motivo:
Permitir `/api/v2` sin romper clientes existentes.

---

   ## 6. Protección de Endpoints

Regla obligatoria:

Todo endpoint excepto login debe:

 - Validar sesión activa
 - Retornar 401 si no autenticado
 - Retornar JSON consistente
 
 Formato obligatorio:
   ```php
{
  "success": false,
  "error": {
    "code": 401,
    "message": "Unauthorized"
  }
}
   ```

---

   ## 7. Separación UI vs Lógica

Prohibido:

 - Ejecutar shell_exec en templates
 - Ejecutar SQL directo en vistas
 - Acceso directo a DB desde public/index.php
 
 Toda lógica debe pasar por:

 - Services
 - Repositories
 - API layer

---

   ## 8. Permisos del Sistema (Producción Raspberry)
      
   ### 8.1 Estructura esperada

   ```php
   /opt/chilemon
   /opt/chilemon/data
   /opt/chilemon/logs
   /opt/chilemon/backups
   ```

   ### 8.2 Permisos recomendados

   ```php
   sudo chown -R www-data:stg /opt/chilemon  
   sudo find /opt/chilemon -type d -exec chmod 2775 {} \;
   sudo find /opt/chilemon -type f -exec chmod 664 {} \;
   ```

   ### 8.3 Reglas

 - data/ debe ser escribible por www-data
 - logs/ debe ser escribible por www-data
 - backups/ debe ser escribible por www-data
 - chilemon.sqlite NO debe ser 777

---

   ## 9. SQLite

Reglas:

 - DB no se versiona
 - Se crea vía bin/install.php
 - Migraciones futuras obligatorias
 - Nunca modificar manualmente en producción

Ubicación oficial:
   
   ```php
   /opt/chilemon/data/chilemon.sqlite
   ```
---

   ## 10. Logging

Nivel por defecto: INFO

Logs deben ir en:

No registrar:

 - Passwords
 - okens
 - ession IDs

Eventos críticos que deben loguearse
 - Login fallido
 - ogin exitoso
 - loqueo por rate limit
 - rrores internos 500

---

   ## 11. Backup Base

   Debe existir:

   ```php
    bin/backup.php
   ```

---

   ## 12. Modelo mínimo de roles (preparación futura)

Aunque aún no se implementen:

Roles reservados:

 - admin
 - operator

Por defecto:

 - Primer usuario creado = admin

Esto evita refactor completo más adelante.

---

   ## 13. Checklist de Seguridad (Definition of Done)

Un cambio se considera seguro si:

 - No rompe instalación limpia
 - No expone credenciales
 - Respeta /api/v1
 - Requiere sesión válida
 - No acopla UI ↔ Asterisk
 - Pasa validación básica de inputs

---

   ## 14. Fuera de alcance (Fase 2)

 - CSRF tokens obligatorios
 - CSP headers
 - WebSocket auth
 - WebRTC security
 - 2FA
 - Rate limit distribuido

---

   ## Estado tras aplicar Fase 1

ChileMon queda:

 - Seguro para entorno Raspberry expuesto a Internet
 - Preparado para crecimiento
 - Sin deuda técnica crítica
 - Con arquitectura protegida

---

   ## Fin — Security Phase 1

   ```php

   # 🎯 Resultado

   Este avance:

      - Endurece login
      - Formaliza permisos
      - Obliga versionado API
      - Introduce rate limiting
      - Prepara roles
      - Formaliza backup
      - Alinea arquitectura
   ```

