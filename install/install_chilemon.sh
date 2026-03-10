#!/usr/bin/env bash
# ==============================================================================
# ChileMon - Instalador automático principal
# ------------------------------------------------------------------------------
# Este script instala ChileMon en un nodo Debian/ASL3 con Apache + PHP + SQLite.
# Está pensado para ejecutarse después de clonar el repositorio en /opt/chilemon.
#
# Uso:
#   sudo bash install/install_chilemon.sh
# ==============================================================================

set -Eeuo pipefail

# ------------------------------------------------------------------------------
# Variables generales del instalador.
# ------------------------------------------------------------------------------
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTALL_TS="$(date +%Y%m%d_%H%M%S)"
APACHE_CONF="/etc/apache2/conf-available/chilemon.conf"
SUDOERS_FILE="/etc/sudoers.d/chilemon-www-data"
WRAPPER_PATH="/usr/local/bin/chilemon-rpt"
LOCAL_CONFIG="$REPO_DIR/config/local.php"
LOG_DIR="$REPO_DIR/logs"
DATA_DIR="$REPO_DIR/data"
PUBLIC_DIR="$REPO_DIR/public"
INSTALLER_PHP="$REPO_DIR/bin/install.php"

# Valores recomendados por defecto para AMI.
DEFAULT_AMI_HOST="127.0.0.1"
DEFAULT_AMI_PORT="5038"
DEFAULT_AMI_USER="admin"
DEFAULT_AMI_TIMEOUT="3"

# ------------------------------------------------------------------------------
# Manejo centralizado de errores para mostrar mensajes claros al usuario final.
# ------------------------------------------------------------------------------
on_error() {
    local exit_code="$?"
    local line_no="${1:-desconocida}"
    echo
    echo "[ERROR] La instalación se detuvo en la línea ${line_no}."
    echo "[ERROR] Revise el mensaje mostrado antes de este bloque."
    echo "[ERROR] No se eliminaron archivos del proyecto."
    exit "$exit_code"
}
trap 'on_error $LINENO' ERR

# ------------------------------------------------------------------------------
# Funciones auxiliares para impresión amigable.
# ------------------------------------------------------------------------------
step() {
    local num="$1"
    local msg="$2"
    echo
    echo "============================================================"
    echo "Paso ${num}: ${msg}"
    echo "============================================================"
}

info() {
    echo "[INFO] $1"
}

ok() {
    echo "[OK]   $1"
}

warn() {
    echo "[WARN] $1"
}

require_root() {
    if [[ "$EUID" -ne 0 ]]; then
        echo "[ERROR] Este instalador debe ejecutarse con sudo o como root."
        exit 1
    fi
}

backup_if_exists() {
    local target="$1"
    if [[ -e "$target" ]]; then
        local backup="${target}.bak.${INSTALL_TS}"
        cp -a "$target" "$backup"
        ok "Respaldo creado: $backup"
    fi
}

check_repo_structure() {
    [[ -d "$PUBLIC_DIR" ]] || { echo "[ERROR] No existe la carpeta public/ en $REPO_DIR"; exit 1; }
    [[ -f "$INSTALLER_PHP" ]] || { echo "[ERROR] No existe bin/install.php en $REPO_DIR"; exit 1; }
}

ensure_package() {
    local pkg="$1"
    if ! dpkg -s "$pkg" >/dev/null 2>&1; then
        apt-get install -y "$pkg"
    fi
}

validate_php_sqlite() {
    info "Validando módulos PHP requeridos para SQLite"

    local missing=0

    if ! php -m | grep -q '^PDO$'; then
        echo "[ERROR] PHP no tiene cargado el módulo PDO."
        missing=1
    fi

    if ! php -m | grep -q '^pdo_sqlite$'; then
        echo "[ERROR] PHP no tiene cargado el módulo pdo_sqlite."
        missing=1
    fi

    if ! php -m | grep -q '^sqlite3$'; then
        echo "[ERROR] PHP no tiene cargado el módulo sqlite3."
        missing=1
    fi

    if [[ "$missing" -ne 0 ]]; then
        echo
        echo "[ERROR] La instalación no puede continuar porque PHP/SQLite no está listo."
        echo "[ERROR] Revise la configuración de PHP en este nodo."
        echo "[ERROR] Sugerencia inicial:"
        echo "        sudo apt install --reinstall -y php8.4-common php8.4-cli libapache2-mod-php8.4 php8.4-sqlite3 php-sqlite3"
        echo "        php -m | grep -E 'PDO|pdo_sqlite|sqlite3'"
        exit 1
    fi

    ok "PHP tiene cargados PDO, pdo_sqlite y sqlite3"
}

escape_php_string() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\\\'}"
    printf '%s' "$value"
}

write_local_config() {
    local local_node="$1"
    local server_host="$2"
    local header_tagline="$3"
    local ami_host="$4"
    local ami_port="$5"
    local ami_user="$6"
    local ami_pass="$7"
    local ami_timeout="$8"

    mkdir -p "$(dirname "$LOCAL_CONFIG")"
    backup_if_exists "$LOCAL_CONFIG"

    local safe_local_node safe_server_host safe_header_tagline
    local safe_ami_host safe_ami_user safe_ami_pass
    local safe_repo_dir safe_db_path safe_wrapper_path

    safe_local_node="$(escape_php_string "$local_node")"
    safe_server_host="$(escape_php_string "$server_host")"
    safe_header_tagline="$(escape_php_string "$header_tagline")"
    safe_ami_host="$(escape_php_string "$ami_host")"
    safe_ami_user="$(escape_php_string "$ami_user")"
    safe_ami_pass="$(escape_php_string "$ami_pass")"
    safe_repo_dir="$(escape_php_string "$REPO_DIR")"
    safe_db_path="$(escape_php_string "${DATA_DIR}/chilemon.sqlite")"
    safe_wrapper_path="$(escape_php_string "$WRAPPER_PATH")"

    cat > "$LOCAL_CONFIG" <<PHP
<?php
/**
 * Configuración local de ChileMon.
 *
 * Archivo generado por install/install_chilemon.sh
 * Fecha: ${INSTALL_TS}
 */

return [
    // Nodo local ASL configurado para este servidor.
    'local_node' => '${safe_local_node}',

    // Texto mostrado bajo el título principal de la aplicación.
    'header_tagline' => '${safe_header_tagline}',

    // Host sugerido para construir URLs del dashboard.
    'server_host' => '${safe_server_host}',

    // Ruta raíz del proyecto instalada en el servidor.
    'project_root' => '${safe_repo_dir}',

    // Ruta de la base de datos SQLite.
    'database_path' => '${safe_db_path}',

    // Ruta del wrapper seguro de Asterisk.
    'wrapper_path' => '${safe_wrapper_path}',

    // Parámetros de conexión AMI.
    'ami_host' => '${safe_ami_host}',
    'ami_port' => ${ami_port},
    'ami_user' => '${safe_ami_user}',
    'ami_pass' => '${safe_ami_pass}',
    'ami_timeout' => ${ami_timeout},
];
PHP

    ok "Configuración local generada en $LOCAL_CONFIG"
}

write_wrapper_if_missing() {
    if [[ -x "$WRAPPER_PATH" ]]; then
        warn "El wrapper ya existe en $WRAPPER_PATH"
        return
    fi

    cat > "$WRAPPER_PATH" <<'BASH'
#!/usr/bin/env bash
# ==============================================================================
# ChileMon - Wrapper seguro para comandos rpt de Asterisk
# ------------------------------------------------------------------------------
# Uso:
#   chilemon-rpt nodes <nodo_local>
#   chilemon-rpt stats <nodo_local>
#   chilemon-rpt connect <nodo_local> <nodo_remoto>
#   chilemon-rpt disconnect <nodo_local> <nodo_remoto>
# ==============================================================================

set -Eeuo pipefail

usage() {
    echo "Uso: chilemon-rpt {nodes|stats|connect|disconnect} <nodo_local> [nodo_remoto]" >&2
    exit 1
}

is_number() {
    [[ "${1:-}" =~ ^[0-9]+$ ]]
}

main() {
    local action="${1:-}"
    local local_node="${2:-}"
    local remote_node="${3:-}"

    [[ -n "$action" && -n "$local_node" ]] || usage
    is_number "$local_node" || usage

    case "$action" in
        nodes)
            exec /usr/sbin/asterisk -rx "rpt nodes ${local_node}"
            ;;
        stats)
            exec /usr/sbin/asterisk -rx "rpt stats ${local_node}"
            ;;
        connect)
            is_number "$remote_node" || usage
            exec /usr/sbin/asterisk -rx "rpt fun ${local_node} *3${remote_node}"
            ;;
        disconnect)
            is_number "$remote_node" || usage
            exec /usr/sbin/asterisk -rx "rpt fun ${local_node} *1${remote_node}"
            ;;
        *)
            usage
            ;;
    esac
}

main "$@"
BASH

    chmod 755 "$WRAPPER_PATH"
    ok "Wrapper instalado en $WRAPPER_PATH"
}

write_sudoers() {
    backup_if_exists "$SUDOERS_FILE"
    cat > "$SUDOERS_FILE" <<EOF2
www-data ALL=(ALL) NOPASSWD: ${WRAPPER_PATH}
EOF2
    chmod 440 "$SUDOERS_FILE"
    visudo -cf "$SUDOERS_FILE" >/dev/null
    ok "Sudoers configurado en $SUDOERS_FILE"
}

write_apache_config() {
    backup_if_exists "$APACHE_CONF"
    cat > "$APACHE_CONF" <<EOF2
# -----------------------------------------------------------------------------
# Alias de ChileMon para Apache.
# Archivo generado por el instalador automático.
# -----------------------------------------------------------------------------
Alias /chilemon ${PUBLIC_DIR}

<Directory ${PUBLIC_DIR}>
    AllowOverride All
    Require all granted
    DirectoryIndex index.php
</Directory>
EOF2

    a2enconf chilemon >/dev/null
    a2enmod rewrite >/dev/null
    systemctl reload apache2
    ok "Apache configurado con alias /chilemon"
}

run_php_installer() {
    if [[ ! -f "$INSTALLER_PHP" ]]; then
        warn "No existe bin/install.php. Se omite inicialización PHP."
        return
    fi

    sudo -u www-data env CHILEMON_NON_INTERACTIVE=1 php "$INSTALLER_PHP" </dev/null
    ok "Inicialización PHP ejecutada correctamente"
}

validate_installation() {
    local local_node="$1"
    local server_host="$2"

    echo
    echo "Resumen final"
    echo "-------------"
    echo "Proyecto : $REPO_DIR"
    echo "Nodo ASL : $local_node"
    echo "URL      : http://${server_host}/chilemon"
    echo "Wrapper  : $WRAPPER_PATH"
    echo "Config   : $LOCAL_CONFIG"
    echo
    echo "Pruebas rápidas sugeridas:"
    echo "  sudo -u www-data sudo -n ${WRAPPER_PATH} nodes ${local_node}"
    echo "  sudo -u www-data php ${INSTALLER_PHP}"
    echo "  abrir en navegador: http://${server_host}/chilemon"
}

main() {
    require_root

    clear || true
    echo "ChileMon - Instalador automático"
    echo "--------------------------------"
    echo "Proyecto detectado en: $REPO_DIR"
    echo "Este script no elimina el proyecto existente."
    echo
    read -r -p "¿Desea continuar con la instalación? [s/N]: " confirm
    [[ "$confirm" =~ ^[sS]$ ]] || { echo "Instalación cancelada."; exit 0; }

    step "1 de 8" "Validando estructura del repositorio"
    check_repo_structure
    ok "Estructura del repositorio validada"

    step "2 de 8" "Instalando dependencias base"
    apt-get update
    ensure_package git
    ensure_package apache2
    ensure_package php
    ensure_package php-sqlite3
    ensure_package sqlite3
    ensure_package sudo
    ok "Dependencias instaladas o ya presentes"

    step "3 de 8" "Solicitando datos básicos del nodo"
    local local_node=""
    while [[ -z "$local_node" ]]; do
        read -r -p "Ingrese su nodo ASL local: " local_node
        [[ "$local_node" =~ ^[0-9]+$ ]] || { echo "Debe ingresar solo números."; local_node=""; }
    done

    local server_host=""
    read -r -p "Ingrese IP o nombre del servidor [localhost]: " server_host
    server_host="${server_host:-localhost}"

    local header_tagline=""
    read -r -p "Ingrese texto descriptivo del nodo [Nodo local ChileMon]: " header_tagline
    header_tagline="${header_tagline:-Nodo local ChileMon}"

    echo
    info "Se aplicará la configuración AMI recomendada para una instalación local:"
    info "  Host    : ${DEFAULT_AMI_HOST}"
    info "  Puerto  : ${DEFAULT_AMI_PORT}"
    info "  Usuario : ${DEFAULT_AMI_USER}"
    info "  Timeout : ${DEFAULT_AMI_TIMEOUT} segundos"
    echo

    local ami_host="$DEFAULT_AMI_HOST"
    local ami_port="$DEFAULT_AMI_PORT"
    local ami_user="$DEFAULT_AMI_USER"
    local ami_timeout="$DEFAULT_AMI_TIMEOUT"

    local ami_pass=""
    echo "Ingrese la clave AMI configurada en su instalación de ASL/Asterisk."
    echo "Si la cambió durante la instalación, debe usar esa misma clave."
    read -r -s -p "Ingrese clave AMI [Enter para usar valor por defecto actual]: " ami_pass
    echo
    ami_pass="${ami_pass:-angE29angE64}"

    ok "Nodo local capturado: $local_node"

    step "4 de 8" "Preparando carpetas y permisos"
    mkdir -p "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR" "$REPO_DIR/config"
    chown -R www-data:www-data "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR"
    chmod -R 775 "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR"
    ok "Carpetas preparadas y permisos aplicados"

    step "5 de 8" "Generando configuración local"
    write_local_config \
        "$local_node" \
        "$server_host" \
        "$header_tagline" \
        "$ami_host" \
        "$ami_port" \
        "$ami_user" \
        "$ami_pass" \
        "$ami_timeout"

    step "6 de 8" "Instalando wrapper y sudoers"
    write_wrapper_if_missing
    write_sudoers

    step "7 de 8" "Configurando Apache"
    write_apache_config

    step "8 de 8" "Validando PHP e inicializando ChileMon"
    validate_php_sqlite
    run_php_installer

    validate_installation "$local_node" "$server_host"
}

main "$@"