# 🛠️ Soporte Técnico — ChileMon ASL3

> Plataforma de monitoreo para nodos AllStarLink 3 | PHP 8.2+ · Bootstrap 5 · SQLite · Apache2  
> Desarrollado por **CA2IIG – Guillermo Ismodes López** · La Serena, Chile

---

## 📋 Índice

1. [Antes de abrir un issue](#1-antes-de-abrir-un-issue)
2. [Diagnóstico rápido](#2-diagnóstico-rápido)
3. [Errores conocidos y soluciones](#3-errores-conocidos-y-soluciones)
4. [Preguntas frecuentes (FAQ)](#4-preguntas-frecuentes-faq)
5. [Cómo reportar un bug](#5-cómo-reportar-un-bug)
6. [Cómo solicitar una mejora](#6-cómo-solicitar-una-mejora)
7. [Información del entorno (plantilla)](#7-información-del-entorno-plantilla)
8. [Contacto y comunidad](#8-contacto-y-comunidad)

---

## 1. Antes de abrir un issue

Verifica que tu entorno cumple los requisitos mínimos:

| Requisito        | Versión mínima          |
|------------------|-------------------------|
| Sistema operativo| Debian 12 (Bookworm)    |
| PHP              | 8.2+                    |
| Apache           | 2.4+                    |
| SQLite           | 3.x                     |
| Git              | cualquier versión actual |

Comandos de verificación rápida:

```bash
# Versión de PHP
php -v

# Extensiones SQLite activas
php -m | grep -i sqlite

# Estado de Apache
sudo systemctl status apache2

# Módulo rewrite habilitado
apache2ctl -M | grep rewrite

# Permisos sobre el directorio de datos
ls -la /opt/chilemon/data/
```

---

## 2. Diagnóstico rápido

### 2.1 El dashboard no carga (pantalla en blanco o error 500)

```bash
# Revisar log de errores de Apache
sudo tail -50 /var/log/apache2/error.log

# Revisar log propio de ChileMon
sudo tail -50 /opt/chilemon/logs/app.log
```

### 2.2 Verificar wrapper `chilemon-rpt`

```bash
# Ejecutar manualmente como www-data
sudo -u www-data sudo -n /usr/local/bin/chilemon-rpt nodes YOUR_NODE

# Verificar permisos del wrapper
ls -la /usr/local/bin/chilemon-rpt

# Verificar entrada en sudoers
sudo cat /etc/sudoers.d/chilemon
```

### 2.3 Verificar conexión con Asterisk

```bash
# Consulta directa a Asterisk (sin pasar por ChileMon)
sudo asterisk -rx "rpt nodes YOUR_NODE"

# Verificar que Asterisk está activo
sudo systemctl status asterisk
```

### 2.4 Verificar base de datos SQLite

```bash
# Integridad de la base de datos
sqlite3 /opt/chilemon/data/chilemon.sqlite "PRAGMA integrity_check;"

# Listar tablas existentes
sqlite3 /opt/chilemon/data/chilemon.sqlite ".tables"

# Ver usuarios registrados
sqlite3 /opt/chilemon/data/chilemon.sqlite "SELECT id, username, created_at FROM users;"
```

### 2.5 Configuración Rápida de EchoLink (Opcional)

Si tu nodo no tiene EchoLink configurado, puedes usar este bloque para crearlo rápidamente. **Importante:** Debes cambiar los campos `call`, `pwd` y `astnode` antes de ejecutarlo.

```bash
# 1. Crear el archivo de configuración (Cambia los datos marcados abajo)
cat << 'EOF' | sudo tee /etc/asterisk/echolink.conf > /dev/null
[general]
call = TU_CALLSIGN-L      ; << CAMBIA ESTO (Ej: CA2IIG-L)
pwd = TU_PASSWORD        ; << CAMBIA ESTO (Tu pass de EchoLink)
name = ChileMon Node
qth = Quilpue, Chile
email = soporte@chilemon.cl
maxstns = 10
rtcpport = 5199
server1 = nasouth.echolink.org
server2 = naeast.echolink.org
server3 = servers.echolink.org
astnode = 494780          ; << CAMBIA ESTO (Tu nodo local ASL)
context = radio-secure    ; O "echolink-in"
[el0]
conf = no
EOF

# 2. Habilitar el módulo en Asterisk
sudo sed -i 's/noload => chan_echolink.so/load => chan_echolink.so/g' /etc/asterisk/modules.conf

# 3. Reiniciar Asterisk para aplicar cambios
sudo systemctl restart asterisk
```

---

## 3. Errores conocidos y soluciones

### ❌ `Wrapper execution failed (NULL output)`

**Causa:** `shell_exec()` devuelve `NULL` cuando el comando no produce salida en stdout (comportamiento normal en comandos `rpt fun`, `rpt cmd`, `rpt nodes`).

**Solución:** Reemplazar `shell_exec()` por `exec()` en `AslRptService.php` y validar por `exit code`:

```php
// ✅ Correcto — valida por código de salida, no por stdout
exec($cmd, $output, $exitCode);

if ($exitCode === 0) {
    // Comando ejecutado correctamente (aunque output esté vacío)
    return true;
}
// exit code distinto de 0 = error real
return false;
```

---

### ❌ Error 403 al acceder a `/chilemon/`

**Causa:** Falta configuración `AllowOverride All` en Apache o `.htaccess` ausente.

**Solución:**

```apacheconf
# En /etc/apache2/sites-available/default-ssl.conf
Alias /chilemon "/opt/chilemon/public"

<Directory "/opt/chilemon/public">
    AllowOverride All
    Require all granted
</Directory>
```

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

### ❌ `SQLSTATE[HY000]: unable to open database file`

**Causa:** El directorio `data/` no existe o `www-data` no tiene permisos de escritura.

**Solución:**

```bash
sudo mkdir -p /opt/chilemon/data
sudo mkdir -p /opt/chilemon/logs
sudo chown -R www-data:www-data /opt/chilemon
sudo find /opt/chilemon -type d -exec chmod 2775 {} \;
sudo find /opt/chilemon -type f -exec chmod 664 {} \;
```

---

### ❌ Login falla sin mensaje de error

**Causa:** La base de datos no fue inicializada o el usuario no fue creado.

**Solución:**

```bash
# Re-ejecutar el instalador
cd /opt/chilemon
sudo -u www-data php bin/install.php

# O crear un usuario adicional
sudo -u www-data php bin/create-user.php
```

---

### ❌ La API responde `{"error":"Unauthorized"}`

**Causa:** La sesión PHP expiró o no se inició sesión.

**Solución:** Volver a hacer login en `https://nodeXXXXX.local/chilemon/login.php`. Las APIs están protegidas por sesión PHP y no aceptan peticiones sin autenticación previa.

---

### ❌ Nodos conectados no se actualizan en el dashboard

**Causa:** El polling automático JavaScript falla silenciosamente (error CORS, endpoint 500, o sesión expirada).

**Diagnóstico desde el navegador:**

```javascript
// Abrir DevTools (F12) → Console → ejecutar:
fetch('/chilemon/api/ami/nodes.php')
  .then(r => r.json())
  .then(console.log)
  .catch(console.error);
```

Respuesta esperada:

```json
{
  "ok": true,
  "count": 3,
  "nodes": ["1001", "52764", "54614"]
}
```

---

## 4. Preguntas frecuentes (FAQ)

**¿ChileMon modifica la configuración de Asterisk?**  
No. ChileMon opera únicamente como módulo web bajo Apache. Nunca escribe en archivos de configuración de Asterisk ni altera ASL.

**¿Puedo instalar ChileMon en un nodo ASL3 en producción?**  
Sí. Está diseñado para ejecutarse como sub-ruta (`/chilemon`) del mismo servidor Apache que usa ASL3.

**¿Funciona sin Raspberry Pi?**  
Funciona en cualquier Debian 12 con PHP 8.2+. La Raspberry Pi es la plataforma de referencia.

**¿Puedo usar MySQL en lugar de SQLite?**  
No está soportado. ChileMon usa SQLite deliberadamente por portabilidad y por no añadir dependencias de servidor de base de datos.

**¿Qué pasa si reinicio la Raspberry? ¿Pierdo datos?**  
No. SQLite persiste en disco (`/opt/chilemon/data/chilemon.sqlite`). Los datos sobreviven reinicios.

**¿Cómo actualizo ChileMon a una nueva versión?**  
```bash
cd /opt/chilemon
sudo git pull origin main
# Revisar si hay migraciones disponibles antes de cada actualización
```

**¿El archivo `chilemon.sqlite` debe estar en el repositorio?**  
No. Está excluido por `.gitignore`. Contiene datos de producción (usuarios, favoritos, actividad).

---

## 5. Cómo reportar un bug

Antes de reportar, busca en los [issues existentes](https://github.com/gismodes37/chilemon/issues) para evitar duplicados.

Al abrir un nuevo issue de tipo **Bug**, incluye obligatoriamente:

- Descripción concreta del problema (qué ocurre vs. qué se espera)
- Pasos exactos para reproducirlo
- Salida del log relevante (`/var/log/apache2/error.log` o `/opt/chilemon/logs/app.log`)
- Información del entorno (ver [sección 7](#7-información-del-entorno-plantilla))

**Título sugerido:** `[BUG] Descripción breve del problema`

---

## 6. Cómo solicitar una mejora

Al abrir un issue de tipo **Feature Request**, incluye:

- Descripción de la funcionalidad deseada
- Caso de uso concreto (¿para qué lo necesitas?)
- Si es posible, propuesta de implementación

**Título sugerido:** `[FEATURE] Descripción breve de la mejora`

---

## 7. Información del entorno (plantilla)

Copia y completa este bloque al reportar cualquier problema:

```
## Entorno del sistema

- SO: Debian 12 / Raspberry Pi OS / otro: ___
- PHP: (php -v)
- Apache: (apache2 -v)
- SQLite: (sqlite3 --version)
- Versión de ChileMon: (git log --oneline -1)
- Nodo ASL: XXXXX
- URL de acceso: https://nodeXXXXX.local/chilemon/

## Descripción del problema

<!-- Describe aquí el problema -->

## Pasos para reproducirlo

1.
2.
3.

## Comportamiento esperado

<!-- Qué debería ocurrir -->

## Comportamiento actual

<!-- Qué ocurre realmente -->

## Logs relevantes

```
<!-- Pega aquí el fragmento de log -->
```
```

---

## 8. Contacto y comunidad

| Canal | Detalle |
|-------|---------|
| **Issues GitHub** | [github.com/gismodes37/chilemon/issues](https://github.com/gismodes37/chilemon/issues) |
| **Autor** | CA2IIG – Guillermo Ismodes López |
| **Organización** | Servicios Tecnológicos Generales SpA |
| **E-mail** | ca2iig@sql.net |
| **Ubicación** | La Serena, Chile |

> ChileMon es un proyecto comunitario open source bajo licencia MIT.  
> Las contribuciones son bienvenidas mediante Pull Request hacia la rama `dev`.

---

*Última actualización: Marzo 2026 — ChileMon ASL3*
