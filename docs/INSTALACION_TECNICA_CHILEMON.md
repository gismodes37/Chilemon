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
1. Clonar o actualizar el repositorio en `/opt/chilemon`.
2. Ejecutar `sudo bash install/install_chilemon.sh`.
3. Ingresar nodo local y host del servidor.
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

## Wrapper seguro
El wrapper acepta únicamente estas acciones:
- `nodes <nodo_local>`
- `stats <nodo_local>`
- `connect <nodo_local> <nodo_remoto>`
- `disconnect <nodo_local> <nodo_remoto>`

Internamente ejecuta `/usr/sbin/asterisk -rx`.

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
