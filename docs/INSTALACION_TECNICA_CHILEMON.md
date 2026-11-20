# Instalación técnica de ChileMon

## Objetivo
Instalar ChileMon sobre Debian/ASL3 con Apache, PHP y SQLite, dejando el nodo local configurable y evitando hardcodear un nodo del desarrollador.

## Componentes instalados
- Proyecto en `/opt/chilemon`
- Alias Apache `/chilemon`
- Wrapper seguro `/usr/local/bin/chilemon-rpt`
- Regla sudoers para `www-data`
- Configuración local en `config/local.php`
- Base SQLite en `data/chilemon.sqlite`

## Flujo recomendado
1. Ejecutar el comando de instalación automática:
   ```bash
   sudo git clone -b v0.1.0 https://github.com/gismodes37/Chilemon.git /opt/chilemon
   cd /opt/chilemon && sudo bash install/install_chilemon.sh
   ```
2. Ingresar nodo local y host del servidor cuando se solicite.
4. Verificar URL final y wrapper con `www-data`.

## Dependencias instaladas por el script
```bash
apt-get update
apt-get install -y git apache2 php php-sqlite3 sqlite3 sudo
```

## Archivo de configuración local
El instalador genera `config/local.php` con:
- `local_node`
- `server_host`
- `project_root`
- `database_path`
- `wrapper_path`

## Wrapper seguro (v0.3.0)
El wrapper (`/usr/local/bin/chilemon-rpt`) se instala desde `bin/chilemon-rpt` del repositorio (no se genera una copia inline que pueda divergir):
- **Validación de parámetros**: ambos IDs de nodo deben coincidir con `^[0-9]+$`; cualquier otro valor se rechaza con `exit 1` antes de invocar a Asterisk.
- **Soporte EchoLink**: IDs de 7 dígitos con prefijo `3`, también numéricos.
- **Acciones soportadas**:
    - `nodes <local_node>`
    - `stats <local_node>`
    - `connect <local_node> <remote_node>`
    - `disconnect <local_node> <remote_node>`
    - `git-fetch`, `git-compare`, `git-pull`
    - `sys-restart-webrtc`, `sys-reload-apache`
- **Acciones deliberadamente NO soportadas**: `sys-poweroff`, `sys-restart-asterisk`, `sys-restart-apache`. Se ejecutan por SSH con sudo real, nunca desde la web.

Internamente ejecuta `/usr/sbin/asterisk -rx` con los parámetros validados.

## Sudoers
El instalador escribe:

```text
www-data ALL=(ALL) NOPASSWD: /usr/local/bin/chilemon-rpt
```

Archivo generado:

```text
/etc/sudoers.d/chilemon-www-data
```

## Apache
El instalador crea:

```text
/etc/apache2/conf-available/chilemon.conf
```

Con el alias:

```apache
Alias /chilemon /opt/chilemon/public
```

Luego ejecuta:

```bash
a2enconf chilemon
a2enmod rewrite
systemctl reload apache2
```

## Respaldos automáticos
Si ya existían estos archivos, el instalador crea respaldo con sufijo `.bak.YYYYMMDD_HHMMSS`:
- `config/local.php`
- `/etc/apache2/conf-available/chilemon.conf`
- `/etc/sudoers.d/chilemon-www-data`

## Validaciones finales sugeridas
```bash
sudo -u www-data sudo -n /usr/local/bin/chilemon-rpt nodes 12345
sudo -u www-data php /opt/chilemon/bin/install.php
```

## Problemas comunes
### No existe `public/` o `bin/install.php`
El repositorio local no coincide con la estructura esperada. No continuar hasta corregir eso.

### Apache no muestra `/chilemon`
Revisar:
```bash
apachectl configtest
systemctl status apache2
```

### `www-data` no puede ejecutar el wrapper
Revisar:
```bash
visudo -cf /etc/sudoers.d/chilemon-www-data
sudo -u www-data sudo -n /usr/local/bin/chilemon-rpt nodes 12345
```

### Asterisk no responde a comandos `rpt`
Validar que ASL3 esté operativo y que `/usr/sbin/asterisk` exista en el servidor.
