# ChileMon - Agent Instructions

## Lo que va bien (What works)

- **Seguridad básica**: Wrapper `bin/chilemon-rpt` ejecuta solo comandos permitidos (nodes, stats, connect, disconnect)
- **Rate limiting**: Implementado en `Auth.php` (MAX_ATTEMPTS = 5 por 10 min)
- **CSRF protection**: Token por sesión en `Auth::csrfToken()` y `Auth::validateCsrf()`
- **SQLite con WAL mode**: Mejor concurrencia, definido en `Database.php:47`
- **Sesiones endurecidas**: `use_strict_mode`, `use_only_cookies`, `httponly`, `samesite=Lax`
- **Password hashing**: Usa `password_verify()` nativa de PHP
- **CSRF timing-safe**: `hash_equals()` en `Auth.php:270`

---

## Arquitectura

### Entry points
- `public/index.php` → Dashboard principal (require login)
- `public/login.php` → Login form
- `public/api/ami/*.php` → Endpoints JSON (require auth)

### Stack
- PHP 8.2+ (strict_types=1 requerido)
- SQLite (driveronly, WAL mode)
- Bootstrap 5 + vanilla JS
- Apache en ASL3

### Paths clave
```
ROOT_PATH     → dirname(__DIR__) de config/app.php
PUBLIC_PATH  → ROOT_PATH/public
DATA_PATH   → ROOT_PATH/data  (SQLite aqui)
LOG_PATH    → ROOT_PATH/logs
BASE_PATH   → Calculado dinámicamente (soporta subdirectorio /chilemon)
```

### Constants requeridas
- `ASL_NODE` → ID del nodo local (ej: 494780)
- `AMI_HOST`, `AMI_PORT`, `AMI_USER`, `AMI_PASS` → Conexión AMI

---

## Comandos útiles

### Installation (ASL3)
```bash
sudo git clone -b v0.1.0 https://github.com/gismodes37/Chilemon.git /opt/chilemon
cd /opt/chilemon && sudo bash install/install_chilemon.sh
```

### Development (Windows)
- Ejecutar desde XAMPP/Apache
- `AslRptService.php` devuelve datos mockeados automáticamente (detecta Windows)

### Database
```bash
php bin/install.php      # Inicializa schema
php bin/create-user.php # Crea usuario admin
php bin/reset-password.php
```

### Testing
```bash
php bin/test_user.php # Verifica conexión DB
php public/test-db.php # Test endpoint
```

---

## Seguridad (CRITICAL)

### Credentials por defecto (PROBLEMA)
- `config/app.php:226-228` tiene credenciales AMI hardcodeadas
- Eliminar valores por defecto, strictly require desde `config/local.php`

### Recomendaciones de seguridad
1. **1.1** Eliminar hardcoded credentials en `config/app.php`
2. **1.2** Agregar whitelist de nodos en `SystemController.php`
3. **1.3** Implementar rate limiting en APIs (`public/api/ami/*.php`)
4. **1.4** Agregar verificación de rol admin antes de acciones destructivas (`system_action.php`)
5. **1.5** Usar Subresource Integrity (SRI) en CDN Bootstrap

### system_action.php (acciones peligrosas)
- `restart-asterisk` → Reinicia Asterisk
- `restart-apache` → Reinicia Apache
- `poweroff` → Apaga el equipo

---

## Setup requirements

### config/local.php (requerido)
```php
<?php
return [
    'local_node' => '494780',
    'ami_host' => '127.0.0.1',
    'ami_port' => 5038,
    'ami_user' => 'admin',
    'ami_pass' => 'TU_PASSWORD_REAL',
    'ami_timeout' => 3,
];
```

### Wrapper permissions (ASL3)
```bash
# /etc/sudoers.d/chilemon
www-data ALL=(root) NOPASSWD: /usr/local/bin/chilemon-rpt
```

---

## Testing quirks

- **No hay tests unitarios**: No existe `tests/`
- **Mock automático**: `AslRptService.php:262-285` devuelve datos mock en Windows
- **DB schema**: `install/sql/create_tables_sqlite.sql` + migraciones automáticas en `Database.php`

---

## Estilo de código

- `declare(strict_types=1)` obligatorio en todos los archivos PHP
- Namespaces: `App\*` (PSR-4 manual en `app/autoload.php`)
- Return types requeridos
- Code mixto español/inglés → Preferir inglés (estándar industria)
- Sin Composer → Autoload manual nativo

---

## Roadmap recomendado

```
FASE 1 ( inmedi ata)
├── [1.1] Eliminar credenciales por defecto
├── [1.2] Agregar whitelist comandos
└── [1.3] Agregar verificación de rol admin

FASE 2 (2 semanas)
├── [3.1] Agregar return types completos
├── [3.2] Agregar phpstan config
└── [4.1] Setup PHPUnit

FASE 3 (1 mes)
├── [5.1] Crear Dockerfile
├── [5.2] Setup GitHub Actions
├── [5.3] Endpoint health
└── [2.1] Refactor MVC
```

---

## Config file references

- `config/app.php` → Constantes globales
- `config/local.php` → Configuración local (NO versionar)
- `config/database.php` → Driver SQLite
- `.gitignore` → Excluye data/, logs/, config/local.php, *.sqlite