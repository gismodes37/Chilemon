#!/usr/bin/env bash
# ==============================================================================
# ChileMon - Instalador automático principal
# ------------------------------------------------------------------------------
# Este script instala ChileMon en un nodo Debian/ASL3 con Apache + PHP + SQLite.
# Soporta instalación NUEVA y ACTUALIZACIÓN de un sistema existente.
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
BACKUP_DIR="$REPO_DIR/backups"
PUBLIC_DIR="$REPO_DIR/public"
INSTALLER_PHP="$REPO_DIR/bin/install.php"
MANAGER_CONF="/etc/asterisk/manager.conf"
ASTERISK_IAX_CONF="/etc/asterisk/iax.conf"
ASTERISK_RPT_CONF="/etc/asterisk/rpt.conf"
INSTALL_IAX_SNIPPET="$REPO_DIR/install/asterisk/iax.conf"
INSTALL_IAX_COMPANION="$REPO_DIR/install/asterisk/iax-companion.conf"
INSTALL_RPT_DOCS="$REPO_DIR/install/asterisk/rpt.conf"
INSTALL_EXTENSIONS_COMPANION="$REPO_DIR/install/asterisk/extensions-companion.conf"
ASTERISK_EXTENSIONS_CONF="/etc/asterisk/extensions.conf"
DEFAULT_NODE_PROTO="https"
ASL3_MODULES_ADDED=0
INSTALL_MODE="new"
IAX_PHONE_SECRET_VAL=""
WEBRTC_SECRET_VAL=""
WEB_ADMIN_USER=""
WEB_ADMIN_PASS=""

TOTAL_STEPS=14


# ------------------------------------------------------------------------------
# Colores de salida (seguros para set -u)
# ------------------------------------------------------------------------------
if [[ -t 1 ]]; then
    C_RESET=$'\033[0m'
    C_RED=$'\033[1;31m'
    C_GREEN=$'\033[1;32m'
    C_YELLOW=$'\033[1;33m'
    C_BLUE=$'\033[1;34m'
    C_CYAN=$'\033[1;36m'
    C_WHITE=$'\033[1;37m'
else
    C_RESET=''
    C_RED=''
    C_GREEN=''
    C_YELLOW=''
    C_BLUE=''
    C_CYAN=''
    C_WHITE=''
fi


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
    echo "Paso ${num} de ${TOTAL_STEPS}: ${msg}"
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

clear_screen() {
    # ncurses prints "'unknown': I need something more specific." when TERM is
    # unset or invalid, which is common in one-command/CI installs over ssh.
    clear >/dev/null 2>&1 || true
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

validate_php_modules() {
    info "Validando módulos PHP requeridos"

    local missing=0

    if ! php -m | grep -qi '^PDO$'; then
        echo "[ERROR] PHP no tiene cargado el módulo PDO."
        missing=1
    fi

    if ! php -m | grep -qi '^pdo_sqlite$'; then
        echo "[ERROR] PHP no tiene cargado el módulo pdo_sqlite."
        missing=1
    fi

    if ! php -m | grep -qi '^sqlite3$'; then
        echo "[ERROR] PHP no tiene cargado el módulo sqlite3."
        missing=1
    fi

    if ! php -m | grep -qi '^curl$'; then
        echo "[ERROR] PHP no tiene cargado el módulo curl."
        missing=1
    fi

    if ! php -m | grep -qi '^json$'; then
        echo "[ERROR] PHP no tiene cargado el módulo json (requerido para WebSocket)."
        missing=1
    fi

    if ! php -m | grep -qi '^mbstring$'; then
        echo "[ERROR] PHP no tiene cargado el módulo mbstring."
        missing=1
    fi

    if [[ "$missing" -ne 0 ]]; then
        echo
        echo "[ERROR] La instalación no puede continuar porque faltan módulos PHP."
        echo "[ERROR] Revise la configuración de PHP en este nodo."
        echo "[ERROR] Sugerencia inicial:"
        echo "        sudo apt install --reinstall -y php8.4-common php8.4-cli libapache2-mod-php8.4 php8.4-sqlite3 php-sqlite3 php-curl php-mbstring php-json"
        echo "        php -m | grep -E 'PDO|pdo_sqlite|sqlite3|curl|json|mbstring'"
        exit 1
    fi

    ok "PHP tiene cargados PDO, pdo_sqlite, sqlite3, curl, json y mbstring"
}

detect_server_host() {
    local detected=""

    detected="$(hostname -f 2>/dev/null || true)"
    if [[ -z "$detected" || "$detected" == "(none)" ]]; then
        detected="$(hostname 2>/dev/null || true)"
    fi
    if [[ -z "$detected" ]]; then
        detected="localhost"
    fi

    printf '%s' "$detected"
}

detect_web_proto() {

    # si Apache escucha en 443 asumimos https
    if grep -q "Listen 443" /etc/apache2/ports.conf 2>/dev/null; then
        echo "https"
        return
    fi

    # fallback
    echo "http"
}


detect_ami_user() {
    local detected=""

    if [[ -f "$MANAGER_CONF" ]]; then
        detected="$(awk '
            /^\[/ {
                section=$0
                gsub(/^\[/, "", section)
                gsub(/\]$/, "", section)
                if (section != "general" && section != "") {
                    print section
                    exit
                }
            }
        ' "$MANAGER_CONF")"
    fi

    printf '%s' "${detected:-$DEFAULT_AMI_USER}"
}

detect_ami_host() {
    local detected=""

    if [[ -f "$MANAGER_CONF" ]]; then
        detected="$(awk -F= '
            /^[[:space:]]*bindaddr[[:space:]]*=/ {
                gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2)
                sub(/[[:space:]]*;.*/, "", $2)
                print $2
                exit
            }
        ' "$MANAGER_CONF")"
    fi

    printf '%s' "${detected:-$DEFAULT_AMI_HOST}"
}

detect_ami_port() {
    local detected=""

    if [[ -f "$MANAGER_CONF" ]]; then
        detected="$(awk -F= '
            /^[[:space:]]*port[[:space:]]*=/ {
                gsub(/^[[:space:]]+|[[:space:]]+$/, "", $2)
                sub(/[[:space:]]*;.*/, "", $2)
                print $2
                exit
            }
        ' "$MANAGER_CONF")"
    fi

    if [[ ! "${detected:-}" =~ ^[0-9]+$ ]]; then
        detected="$DEFAULT_AMI_PORT"
    fi

    printf '%s' "$detected"
}

escape_php_string() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\'/\\\'}"
    printf '%s' "$value"
}

generate_random_secret() {
    local length="${1:-24}"
    if command -v openssl &>/dev/null; then
        openssl rand -hex "$length"
    else
        od -An -tx1 -N"$length" /dev/urandom | tr -d ' \n'
    fi
}

# Read a secret from an existing config/local.php without requiring PHP.
read_local_config_secret() {
    local key="$1" line
    [[ -f "$LOCAL_CONFIG" ]] || return 0
    line="$(grep -m1 "'$key'" "$LOCAL_CONFIG" 2>/dev/null || true)"
    [[ -n "$line" ]] || return 0
    sed -n "s/.*'$key'[[:space:]]*=>[[:space:]]*'\([^']*\)'.*/\1/p" <<<"$line"
}

# Single source of truth for the WebRTC bridge credentials. Values are
# generated once and reused by config/local.php, iax.conf and the env file.
ensure_webrtc_secrets() {
    [[ -n "$IAX_PHONE_SECRET_VAL" && -n "$WEBRTC_SECRET_VAL" ]] && return 0

    if [[ -z "$IAX_PHONE_SECRET_VAL" ]]; then
        IAX_PHONE_SECRET_VAL="$(read_local_config_secret iax_phone_pass)"
    fi
    if [[ -z "$WEBRTC_SECRET_VAL" ]]; then
        WEBRTC_SECRET_VAL="$(read_local_config_secret webrtc_secret)"
    fi
    [[ -n "$IAX_PHONE_SECRET_VAL" ]] || IAX_PHONE_SECRET_VAL="$(generate_random_secret 24)"
    [[ -n "$WEBRTC_SECRET_VAL" ]] || WEBRTC_SECRET_VAL="$(generate_random_secret 24)"
}

# Update mode preserves config/local.php; add WebRTC secrets to older configs.
ensure_webrtc_secrets_in_config() {
    [[ -f "$LOCAL_CONFIG" ]] || return 0
    local key secret changed=0
    for key in iax_phone_pass webrtc_secret; do
        if ! grep -q "'$key'" "$LOCAL_CONFIG"; then
            secret="$(generate_random_secret 24)"
            sed -i "/^];/i\\    '$key' => '${secret}'," "$LOCAL_CONFIG"
            changed=1
            ok "Secreto $key generado y agregado a $LOCAL_CONFIG"
        fi
    done
    if [[ "$changed" -eq 1 ]]; then
        chown root:www-data "$LOCAL_CONFIG"
        chmod 640 "$LOCAL_CONFIG"
    fi
}

detect_lan_cidr() {
    local iface cidr
    iface="$(ip -4 route show default 2>/dev/null \
        | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}')"
    [[ -n "$iface" ]] || return 1
    cidr="$(ip -4 route show dev "$iface" 2>/dev/null \
        | awk '$1 ~ /\// {print $1; exit}')"
    [[ -n "$cidr" ]] || return 1
    printf '%s' "$cidr"
}

cidr_to_asterisk_acl() {
    local cidr="$1"
    local network="${cidr%/*}"
    local prefix="${cidr#*/}"
    local full=$(( (0xFFFFFFFF << (32 - prefix)) & 0xFFFFFFFF ))
    printf '%s/%d.%d.%d.%d' "$network" \
        $(( (full >> 24) & 255 )) $(( (full >> 16) & 255 )) \
        $(( (full >> 8) & 255 )) $(( full & 255 ))
}

# Update mode preserves config/local.php. If an older install predates the
# companion secret, add a generated one before Asterisk consumes it.
ensure_companion_secret_in_config() {
    [[ -f "$LOCAL_CONFIG" ]] || return 0
    if grep -q "'iax_companion_secret'" "$LOCAL_CONFIG"; then
        return 0
    fi

    local secret
    secret="$(generate_random_secret 24)"
    sed -i "/^];/i\\    'iax_companion_secret' => '${secret}'," "$LOCAL_CONFIG"
    chown root:www-data "$LOCAL_CONFIG"
    chmod 640 "$LOCAL_CONFIG"
    ok "Secreto IAX2 generado y agregado a $LOCAL_CONFIG"
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
    local hub_url="$9"

    mkdir -p "$(dirname "$LOCAL_CONFIG")"
    backup_if_exists "$LOCAL_CONFIG"

    # Single source of truth for the IAX2 companion peer password. Reuse it on
    # re-runs so an existing companion config.toml keeps working.
    local iax_companion_secret=""
    if [[ -f "$LOCAL_CONFIG" ]]; then
        iax_companion_secret="$(php -r "\$c = require '$LOCAL_CONFIG'; echo \$c['iax_companion_secret'] ?? '';" 2>/dev/null || true)"
    fi
    if [[ -z "$iax_companion_secret" ]]; then
        iax_companion_secret="$(generate_random_secret 24)"
    fi

    ensure_webrtc_secrets

    local safe_local_node safe_server_host safe_header_tagline
    local safe_ami_host safe_ami_user safe_ami_pass
    local safe_repo_dir safe_db_path safe_wrapper_path
    local safe_hub_url safe_iax_companion_secret safe_iax_phone_pass safe_webrtc_secret

    safe_iax_companion_secret="$(escape_php_string "$iax_companion_secret")"
    safe_iax_phone_pass="$(escape_php_string "$IAX_PHONE_SECRET_VAL")"
    safe_webrtc_secret="$(escape_php_string "$WEBRTC_SECRET_VAL")"
    safe_local_node="$(escape_php_string "$local_node")"
    safe_server_host="$(escape_php_string "$server_host")"
    safe_header_tagline="$(escape_php_string "$header_tagline")"
    safe_ami_host="$(escape_php_string "$ami_host")"
    safe_ami_user="$(escape_php_string "$ami_user")"
    safe_ami_pass="$(escape_php_string "$ami_pass")"
    safe_repo_dir="$(escape_php_string "$REPO_DIR")"
    safe_db_path="$(escape_php_string "${DATA_DIR}/chilemon.sqlite")"
    safe_wrapper_path="$(escape_php_string "$WRAPPER_PATH")"
    safe_hub_url="$(escape_php_string "$hub_url")"

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

    // URL del hub central ChileMon (opcional). Si se configura,
    // el dashboard mostrará un banner para registrar este nodo
    // en el mapa comunitario (https://github.com/gismodes37/Chilemon-Hub).
    'hub_url' => '${safe_hub_url}',

    // IAX2 companion peer secret. Generated at install time and shared with
    // the dashboard's config.toml download and /etc/asterisk/iax.conf.
    'iax_companion_secret' => '${safe_iax_companion_secret}',

    // IAX2 phone extension secret. Shared with /etc/asterisk/iax.conf and
    // /etc/default/chilemon-webrtc so the browser PTT bridge can authenticate.
    'iax_phone_user' => 'webrtc-bridge',
    'iax_phone_pass' => '${safe_iax_phone_pass}',

    // HMAC secret used to sign WebRTC WebSocket auth tokens.
    'webrtc_secret' => '${safe_webrtc_secret}',
];
PHP

    chown root:www-data "$LOCAL_CONFIG"
    chmod 640 "$LOCAL_CONFIG"
    ok "Configuración local generada en $LOCAL_CONFIG (root:www-data 0640)"
}

write_wrapper_if_missing() {
    # Install the maintained wrapper from the repository. The previous inline
    # copy drifted: it lacked the git-* verbs used by UpdateService and exposed
    # destructive system verbs to the NOPASSWD sudoers rule.
    local wrapper_src="${REPO_DIR}/bin/chilemon-rpt"

    if [[ ! -f "$wrapper_src" ]]; then
        warn "No se encontró $wrapper_src — no se puede instalar el wrapper"
        return 1
    fi

    install -m 755 -o root -g root "$wrapper_src" "$WRAPPER_PATH"
    ok "Wrapper instalado desde bin/chilemon-rpt en $WRAPPER_PATH"
}

write_sudoers() {
    backup_if_exists "$SUDOERS_FILE"
    cat > "$SUDOERS_FILE" <<EOF2
www-data ALL=(root) NOPASSWD: ${WRAPPER_PATH}
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

enable_apache_websocket() {
    info "Habilitando proxy WebSocket para Apache"

    # Enable required Apache modules
    if ! apache2ctl -M 2>/dev/null | grep -q 'proxy_wstunnel_module'; then
        a2enmod proxy_wstunnel >/dev/null 2>&1
        ok "Apache module mod_proxy_wstunnel habilitado"
    else
        ok "Apache module mod_proxy_wstunnel ya habilitado"
    fi

    if ! apache2ctl -M 2>/dev/null | grep -q 'proxy_module'; then
        a2enmod proxy >/dev/null 2>&1
        ok "Apache module mod_proxy habilitado"
    else
        ok "Apache module mod_proxy ya habilitado"
    fi

    # Add WebSocket proxy config
    local ws_conf="/etc/apache2/conf-available/chilemon-websocket.conf"
    backup_if_exists "$ws_conf"

    cat > "$ws_conf" <<'EOF2'
# -----------------------------------------------------------------------------
# ChileMon — WebSocket Proxy para WebRTC Audio Bridge
# Archivo generado por el instalador automático.
# Redirige /ws al bridge Python en el puerto 9091.
# -----------------------------------------------------------------------------

ProxyPass /chilemon/ws ws://127.0.0.1:9091/ws
ProxyPassReverse /chilemon/ws ws://127.0.0.1:9091/ws
EOF2

    a2enconf chilemon-websocket >/dev/null 2>&1 || true
    ok "WebSocket proxy configurado en $ws_conf"
}

run_php_installer() {
    if [[ ! -f "$INSTALLER_PHP" ]]; then
        warn "No existe bin/install.php. Se omite inicialización PHP."
        return
    fi

    sudo -u www-data env CHILEMON_NON_INTERACTIVE=1 php "$INSTALLER_PHP" </dev/null
    ok "Inicialización PHP ejecutada correctamente como www-data"
}

run_php_user_creation() {
    if [[ ! -f "$INSTALLER_PHP" ]]; then
        warn "No existe bin/install.php. Se omite creación de usuario."
        return
    fi

    echo
    echo "Se creará el primer usuario administrador de ChileMon."
    echo

    if [[ -t 0 ]]; then
        php "$INSTALLER_PHP"
        ok "Creación de usuario completada"
        return
    fi

    # One-command installs have no TTY. Generate credentials and let install.php
    # create the first admin non-interactively, then report them in the summary.
    WEB_ADMIN_USER="admin"
    WEB_ADMIN_PASS="$(generate_random_secret 20)"
    if CHILEMON_ADMIN_USER="$WEB_ADMIN_USER" CHILEMON_ADMIN_PASS="$WEB_ADMIN_PASS" \
        php "$INSTALLER_PHP" </dev/null; then
        ok "Usuario administrador creado de forma no interactiva"
    else
        WEB_ADMIN_USER=""
        WEB_ADMIN_PASS=""
        warn "No se pudo crear el usuario administrador — créelo con: sudo php $INSTALLER_PHP"
    fi
}


configure_asl3_modules() {
    local modules_conf="${ASL_MODULES_CONF:-/etc/asterisk/modules.conf}"
    local module_dir="${ASTERISK_MODULE_DIR:-}"
    local -a required_modules=(res_http_websocket res_aeap res_sorcery_config \
        res_pjproject res_srtp res_rtp_asterisk bridge_softmix chan_bridge_media)
    local added=0

    if [[ -z "$module_dir" ]] && command -v asterisk &>/dev/null; then
        module_dir=$(asterisk -rx 'core show settings' 2>/dev/null \
            | awk -F: '/Module directory/ {gsub(/^[ \t]+/, "", $2); print $2; exit}' \
            || true)
    fi
    if [[ -z "$module_dir" ]]; then
        local candidate
        for candidate in /usr/lib/asterisk/modules /usr/lib/*/asterisk/modules \
                         /usr/lib64/asterisk/modules; do
            if [[ -d "$candidate" ]]; then
                module_dir="$candidate"
                break
            fi
        done
    fi
    if [[ -z "$module_dir" ]]; then
        warn "No se pudo ubicar el directorio de módulos de Asterisk — se omiten load =>"
        return 0
    fi

    # ASL3 writes 'autoload=no' on older releases and 'autoload = no' on
    # Debian 13. Both mean the same thing: explicit load lines are required.
    if [[ ! -f "$modules_conf" ]] || \
       ! grep -qE '^[[:space:]]*autoload[[:space:]]*=[[:space:]]*no([[:space:]]|$)' "$modules_conf"; then
        warn "ASL3 no detectado — se omite configuración de módulos"
        return 0
    fi

    info "ASL3 detectado en $modules_conf"
    backup_if_exists "$modules_conf"

    # Accept both legacy 'load = module.so' and 'load => module' spellings.
    local existing
    existing=$(grep -E '^[[:space:]]*load[[:space:]]*=>?[[:space:]]*' "$modules_conf" \
        | sed -E 's/^[[:space:]]*load[[:space:]]*=>?[[:space:]]*//; s/\.so.*$//; s/[[:space:]]*$//' \
        || true)

    for mod in "${required_modules[@]}"; do
        if echo "$existing" | grep -qxF "$mod"; then
            continue
        fi
        # Newer ASL/Asterisk builds these modules into the core and ships no
        # .so file: adding a load line would prevent Asterisk from starting.
        if [[ -d "$module_dir" && ! -f "$module_dir/${mod}.so" ]]; then
            info "  $mod compilado en el core — se omite"
            continue
        fi
        echo "load => $mod" >> "$modules_conf"
        added=$((added + 1))
        info "  load => $mod agregado"
    done

    if [[ "$added" -gt 0 ]]; then
        ok "Módulos Asterisk: $added load => agregados"
        warn "Reinicie Asterisk manualmente: systemctl restart asterisk"
    else
        ok "Todos los módulos ya estaban presentes"
    fi

    ASL3_MODULES_ADDED="$added"
}

# Render the [webrtc-bridge] IAX2 peer with the same secret stored in
# config/local.php. Called from configure_webrtc_asterisk and from tests.
install_iax_bridge_snippet() {
    ensure_webrtc_secrets

    if [[ ! -f "$INSTALL_IAX_SNIPPET" || ! -f "$ASTERISK_IAX_CONF" ]]; then
        warn "Snippet IAX2 o iax.conf no encontrado — se omite [webrtc-bridge]"
        return 0
    fi

    if grep -q '^\[webrtc-bridge\]' "$ASTERISK_IAX_CONF"; then
        backup_if_exists "$ASTERISK_IAX_CONF"
        sed -i "/^\[webrtc-bridge\]/,/^\[/{s|^secret=.*|secret=${IAX_PHONE_SECRET_VAL}|;}" \
            "$ASTERISK_IAX_CONF"
        ok "Peer [webrtc-bridge] sincronizado con config/local.php"
    else
        backup_if_exists "$ASTERISK_IAX_CONF"
        echo "" >> "$ASTERISK_IAX_CONF"
        sed "s|CHANGEME_PHONE_SECRET|${IAX_PHONE_SECRET_VAL}|g" \
            "$INSTALL_IAX_SNIPPET" >> "$ASTERISK_IAX_CONF"
        ok "Snippet IAX2 ([webrtc-bridge]) agregado con secreto generado"
    fi
}

# Render the systemd env file with real credentials and the prompted node.
# Escape a value for a double-quoted systemd EnvironmentFile assignment.
escape_env_value() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//\$/\\\$}"
    value="${value//\`/\\\`}"
    printf '%s' "$value"
}

write_webrtc_env_file() {
    local local_node="$1"
    ensure_webrtc_secrets
    local node_val="${local_node:-494780}"

    local ami_host_val ami_port_val ami_user_val ami_pass_val
    ami_host_val="$(read_local_config_secret ami_host)"
    ami_port_val="$(read_local_config_secret ami_port)"
    ami_user_val="$(read_local_config_secret ami_user)"
    ami_pass_val="$(read_local_config_secret ami_pass)"
    [[ -n "$ami_host_val" ]] || ami_host_val="127.0.0.1"
    [[ -n "$ami_port_val" ]] || ami_port_val="5038"
    [[ -n "$ami_user_val" ]] || ami_user_val="admin"

    local ami_pass_file="\"$(escape_env_value "$ami_pass_val")\""
    local ami_pass_sed="${ami_pass_file//\\/\\\\}"
    ami_pass_sed="${ami_pass_sed//&/\\&}"
    ami_pass_sed="${ami_pass_sed//|/\\|}"

    if [[ ! -f "$DEFAULT_ENV_FILE" ]]; then
        cat > "$DEFAULT_ENV_FILE" <<EOF
# ChileMon WebRTC Bridge — Configuration
# This file is sourced by the chilemon-webrtc systemd service.
# Generated by install/install_chilemon.sh; values come from config/local.php.

# Port for the bridge HTTP/WS server
WEBRTC_PORT=9091

# Asterisk IAX2 credentials (must match iax.conf phone extension)
IAX_PHONE_USER=webrtc-bridge
IAX_PHONE_PASS=${IAX_PHONE_SECRET_VAL}

# HMAC secret for WebSocket auth (must match config/local.php webrtc_secret)
WEBRTC_SECRET=${WEBRTC_SECRET_VAL}

# Local ASL node number
ASL_NODE=${node_val}

# Asterisk IAX2 connection
IAX_HOST=127.0.0.1
IAX_PORT=4569

# Asterisk AMI credentials (required by the bridge to originate calls)
AMI_HOST=${ami_host_val}
AMI_PORT=${ami_port_val}
AMI_USER=${ami_user_val}
AMI_PASS=${ami_pass_file}

# Log level: DEBUG, INFO, WARNING, ERROR
LOG_LEVEL=INFO
EOF
        chmod 600 "$DEFAULT_ENV_FILE"
        ok "Configuración WebRTC creada en $DEFAULT_ENV_FILE"
    else
        sed -i \
            -e "s|^IAX_PHONE_PASS=.*|IAX_PHONE_PASS=${IAX_PHONE_SECRET_VAL}|" \
            -e "s|^WEBRTC_SECRET=.*|WEBRTC_SECRET=${WEBRTC_SECRET_VAL}|" \
            -e "s|^ASL_NODE=.*|ASL_NODE=${node_val}|" \
            -e "s|^AMI_HOST=.*|AMI_HOST=${ami_host_val}|" \
            -e "s|^AMI_PORT=.*|AMI_PORT=${ami_port_val}|" \
            -e "s|^AMI_USER=.*|AMI_USER=${ami_user_val}|" \
            -e "s|^AMI_PASS=.*|AMI_PASS=${ami_pass_sed}|" \
            "$DEFAULT_ENV_FILE"
        ok "Configuración WebRTC sincronizada en $DEFAULT_ENV_FILE"
    fi
}


configure_webrtc_asterisk() {
    info "Configurando Asterisk para WebRTC Audio Bridge"

    local local_node="$1"

    # Scope every IAX2 peer to the detected LAN. A previous release widened
    # calltokenoptional to 0.0.0.0/0, which let any host reach the radio peer.
    local lan_cidr lan_acl companion_secret
    if lan_cidr="$(detect_lan_cidr)"; then
        lan_acl="$(cidr_to_asterisk_acl "$lan_cidr")"
    else
        warn "No se pudo detectar la subred LAN — IAX2 queda restringido a loopback"
        lan_cidr="127.0.0.1/32"
        lan_acl="127.0.0.1/255.255.255.255"
    fi

    companion_secret=""
    if [[ -f "$LOCAL_CONFIG" ]]; then
        companion_secret="$(php -r "\$c = require '$LOCAL_CONFIG'; echo \$c['iax_companion_secret'] ?? '';" 2>/dev/null || true)"
    fi

    # Step 1: Append IAX2 snippet to iax.conf with the generated credential.
    install_iax_bridge_snippet

    # Ensure [general] has calltokenoptional scoped to the LAN only.
    # Without it ASL3 drops frames lacking IE_CALLTOKEN, but 0.0.0.0/0
    # exposed the radio peer to every host that could reach UDP 4569.
    if [[ -f "$ASTERISK_IAX_CONF" ]]; then
        local general_has_ct
        general_has_ct=$(awk '/^\[general\]/{found=1} found && /calltokenoptional/{print "yes"; exit} /^\[/{if(found && !/general/)exit}' "$ASTERISK_IAX_CONF")
        if [[ "$general_has_ct" != "yes" ]]; then
            backup_if_exists "$ASTERISK_IAX_CONF"
            if grep -q 'requirecalltoken=no' "$ASTERISK_IAX_CONF"; then
                sed -i "/^requirecalltoken=no/a calltokenoptional=${lan_cidr}" "$ASTERISK_IAX_CONF"
                ok "calltokenoptional=${lan_cidr} agregado a [general] de iax.conf"
            else
                warn "No se encontró requirecalltoken=no en iax.conf — calltokenoptional no agregado"
            fi
        elif ! grep -q "calltokenoptional=${lan_cidr}" "$ASTERISK_IAX_CONF"; then
            # Replace a previously widened or stale value.
            sed -i -E "s/^calltokenoptional=.*/calltokenoptional=${lan_cidr}/" "$ASTERISK_IAX_CONF"
            ok "calltokenoptional limitado a ${lan_cidr}"
        fi
    else
        warn "$ASTERISK_IAX_CONF no existe — se omite ajuste de calltokenoptional"
    fi

    # Step 1b: Append companion-app peer snippet to iax.conf
    if [[ -f "$INSTALL_IAX_COMPANION" ]]; then
        if grep -q '^\[companion-app\]' "$ASTERISK_IAX_CONF"; then
            if [[ -n "$companion_secret" ]]; then
                backup_if_exists "$ASTERISK_IAX_CONF"
                sed -i "/^\[companion-app\]/,/^\[/{s|^secret=.*|secret=${companion_secret}|; s|^permit=.*|permit=${lan_acl}|;}" "$ASTERISK_IAX_CONF"
                ok "Peer [companion-app] sincronizado con secreto y ACL vigentes"
            else
                warn "No se pudo leer iax_companion_secret; peer [companion-app] queda sin sincronizar"
            fi
        else
            if [[ -z "$companion_secret" ]]; then
                companion_secret="$(generate_random_secret 24)"
                warn "Secreto IAX2 generado: agreguelo a config/local.php => iax_companion_secret"
            fi
            backup_if_exists "$ASTERISK_IAX_CONF"
            echo "" >> "$ASTERISK_IAX_CONF"
            sed -e "s|__COMPANION_SECRET__|${companion_secret}|g" \
                -e "s|__IAX_LAN_ACL__|${lan_acl}|g" \
                "$INSTALL_IAX_COMPANION" >> "$ASTERISK_IAX_CONF"
            ok "Peer [companion-app] agregado con secreto generado y ACL ${lan_acl}"
        fi
    else
        warn "Snippet companion-app no encontrado en $INSTALL_IAX_COMPANION"
    fi

    # Step 1c: Install the dialplan context the companion peer points at.
    # Without it the peer exists but calls have no valid destination.
    if [[ -f "$INSTALL_EXTENSIONS_COMPANION" && -f "$ASTERISK_EXTENSIONS_CONF" ]]; then
        if grep -q '^\[radio-companion\]' "$ASTERISK_EXTENSIONS_CONF"; then
            ok "Contexto [radio-companion] ya existe — preservado"
        else
            backup_if_exists "$ASTERISK_EXTENSIONS_CONF"
            echo "" >> "$ASTERISK_EXTENSIONS_CONF"
            sed "s|__LOCAL_NODE__|${local_node}|g" \
                "$INSTALL_EXTENSIONS_COMPANION" >> "$ASTERISK_EXTENSIONS_CONF"
            ok "Contexto [radio-companion] agregado para el nodo ${local_node}"
        fi
    else
        warn "Dialplan companion o extensions.conf no encontrado — contexto [radio-companion] no instalado"
    fi

    # Step 2: Add phonelogin directives to rpt.conf node block
    if [[ -f "$ASTERISK_RPT_CONF" ]]; then
        # Detect the first [NODE_NUMBER] section (ASL node)
        local node_section
        node_section=$(awk '/^\[[0-9]+\]/ { gsub(/[\[\]]/, ""); print; exit }' "$ASTERISK_RPT_CONF")

        if [[ -n "$node_section" ]]; then
            if grep -q 'phonelogin=yes' "$ASTERISK_RPT_CONF"; then
                ok "phonelogin=yes ya configurado en $ASTERISK_RPT_CONF"
            else
                backup_if_exists "$ASTERISK_RPT_CONF"
                # Insert phonelogin and phonecontext after the node section line
                sed -i "/^\[${node_section}\]$/a phonelogin=yes\\nphonecontext=radio-ptt" "$ASTERISK_RPT_CONF"
                ok "phonelogin=yes y phonecontext=radio-ptt agregados al bloque [${node_section}]"
            fi
        else
            warn "No se encontró bloque [NODE] en $ASTERISK_RPT_CONF — se omite"
        fi
    else
        warn "$ASTERISK_RPT_CONF no existe — se omite configuración de phonelogin"
    fi
}


run_verification() {
    info "Verificación final de la instalación"

    local pass=0
    local fail=0

    # Check PHP extensions
    for mod in PDO pdo_sqlite sqlite3 curl json mbstring; do
        if php -m | grep -qi "^${mod}$"; then
            ok "PHP módulo: $mod"
            pass=$((pass + 1))
        else
            warn "PHP módulo faltante: $mod"
            fail=$((fail + 1))
        fi
    done

    # Check Python packages
    if command -v python3 &>/dev/null; then
        ok "Python3 instalado: $(python3 --version 2>&1)"
        pass=$((pass + 1))
    else
        warn "Python3 no encontrado"
        fail=$((fail + 1))
    fi

    if python3 -c "import aiohttp" &>/dev/null 2>&1; then
        ok "Python paquete: aiohttp"
        pass=$((pass + 1))
    else
        warn "Python paquete faltante: aiohttp"
        fail=$((fail + 1))
    fi

    # Check Asterisk config files
    if [[ -f "$ASTERISK_IAX_CONF" ]]; then
        ok "Asterisk config: iax.conf existe"
        pass=$((pass + 1))
    else
        warn "Asterisk config faltante: iax.conf"
        fail=$((fail + 1))
    fi

    if [[ -f "$ASTERISK_RPT_CONF" ]]; then
        ok "Asterisk config: rpt.conf existe"
        pass=$((pass + 1))
    else
        warn "Asterisk config faltante: rpt.conf"
        fail=$((fail + 1))
    fi

    # Check Apache modules
    if apache2ctl -M 2>/dev/null | grep -q 'proxy_wstunnel_module'; then
        ok "Apache módulo: proxy_wstunnel"
        pass=$((pass + 1))
    else
        warn "Apache módulo faltante: proxy_wstunnel"
        fail=$((fail + 1))
    fi

    if apache2ctl -M 2>/dev/null | grep -q 'rewrite_module'; then
        ok "Apache módulo: rewrite"
        pass=$((pass + 1))
    else
        warn "Apache módulo faltante: rewrite"
        fail=$((fail + 1))
    fi

    # Check systemd service (chilemon-webrtc)
    if systemctl list-unit-files 2>/dev/null | grep -q 'chilemon-webrtc.service'; then
        ok "Servicio systemd: chilemon-webrtc registrado"
        pass=$((pass + 1))
    else
        warn "Servicio chilemon-webrtc no registrado"
        fail=$((fail + 1))
    fi

    # Check WebRTC bridge env file
    if [[ -f "/etc/default/chilemon-webrtc" ]]; then
        ok "WebRTC config: /etc/default/chilemon-webrtc existe"
        pass=$((pass + 1))
    else
        warn "WebRTC config faltante: /etc/default/chilemon-webrtc"
        fail=$((fail + 1))
    fi

    # Check Python websockets package
    if python3 -c "import websockets" &>/dev/null 2>&1; then
        ok "Python paquete: websockets"
        pass=$((pass + 1))
    else
        warn "Python paquete faltante: websockets"
        fail=$((fail + 1))
    fi

    # Check ChileMon database
    if [[ -f "${DATA_DIR}/chilemon.sqlite" ]]; then
        ok "Base de datos SQLite: chilemon.sqlite existe"
        pass=$((pass + 1))
    else
        warn "Base de datos faltante: chilemon.sqlite"
        fail=$((fail + 1))
    fi

    echo
    info "Verificación completada: ${pass} OK, ${fail} pendientes"

    if [[ "$fail" -gt 0 ]]; then
        warn "Algunos componentes requieren atención. Revise los mensajes anteriores."
    fi
}


print_final_banner() {
    local local_node="$1"
    local server_host="$2"
    local ami_user="$3"
    local web_user="${4:-no definido}"
    local web_proto="${5:-http}"
    local web_pass="${6:-}"

    echo
    echo -e "${C_CYAN}================================================================${C_RESET}"
    echo
    echo -e "${C_BLUE}   ██████╗██╗  ██╗██╗██╗     ███████╗███╗   ███╗ ██████╗ ███╗   ██╗${C_RESET}"
    echo -e "${C_BLUE}  ██╔════╝██║  ██║██║██║     ██╔════╝████╗ ████║██╔═══██╗████╗  ██║${C_RESET}"
    echo -e "${C_BLUE}  ██║     ███████║██║██║     █████╗  ██╔████╔██║██║   ██║██╔██╗ ██║${C_RESET}"
    echo -e "${C_BLUE}  ██║     ██╔══██║██║██║     ██╔══╝  ██║╚██╔╝██║██║   ██║██║╚██╗██║${C_RESET}"
    echo -e "${C_BLUE}  ╚██████╗██║  ██║██║███████╗███████╗██║ ╚═╝ ██║╚██████╔╝██║ ╚████║${C_RESET}"
    echo -e "${C_BLUE}   ╚═════╝╚═╝  ╚═╝╚═╝╚══════╝╚══════╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═══╝${C_RESET}"
    echo
    echo -e "${C_YELLOW}                     ChileMon instalado correctamente${C_RESET}"
    echo -e "${C_CYAN}================================================================${C_RESET}"
    echo
    echo -e "${C_WHITE}Tome nota de los siguientes datos importantes:${C_RESET}"
    echo
    echo -e " ${C_GREEN}Nodo ASL local :${C_RESET} ${local_node}"
    echo -e " ${C_GREEN}Usuario AMI    :${C_RESET} ${ami_user}"
    echo
    echo -e " ${C_GREEN}Dirección nodo :${C_RESET} ${web_proto}://${server_host}"
    echo -e " ${C_GREEN}Acceso ChileMon:${C_RESET} ${web_proto}://${server_host}/chilemon"
    echo
    echo -e " ${C_GREEN}Usuario web    :${C_RESET} ${web_user}"
    if [[ -n "$web_pass" ]]; then
        echo -e " ${C_GREEN}Contraseña web :${C_RESET} ${web_pass}"
        echo -e " ${C_YELLOW}Guarde esta contraseña: no se muestra nuevamente.${C_RESET}"
    fi
    echo -e " ${C_GREEN}Módulos ASL3   :${C_RESET} ${ASL3_MODULES_ADDED:-0} load => agregados"
    echo -e " ${C_GREEN}WebRTC bridge  :${C_RESET} Si (PTT desde el navegador)"
    echo
    echo -e "${C_WHITE}Probar puente WebRTC:${C_RESET}"
    echo "  curl http://localhost:${WEBRTC_PORT:-9091}/health"
    echo
    echo -e "${C_CYAN}----------------------------------------------------------------${C_RESET}"
    echo -e "${C_WHITE}Pruebas rápidas:${C_RESET}"
    echo
    echo " sudo -u www-data sudo -n ${WRAPPER_PATH} nodes ${local_node}"
    echo
    echo " Abra en su navegador:"
    echo " ${web_proto}://${server_host}/chilemon"
    echo -e "${C_CYAN}----------------------------------------------------------------${C_RESET}"
    echo
    echo -e "${C_YELLOW} Gracias por usar ChileMon 🇨🇱${C_RESET}"
    echo -e "${C_CYAN}================================================================${C_RESET}"
}


validate_installation() {
    local local_node="$1"
    local server_host="$2"
    local ami_user="$3"
    local web_user="$4"
    local web_proto="$5"
    local web_pass="${6:-}"

    echo
    echo "Resumen técnico"
    echo "---------------"
    echo "Modo     : ${INSTALL_MODE}"
    echo "Proyecto : $REPO_DIR"
    echo "Wrapper  : $WRAPPER_PATH"
    echo "Config   : $LOCAL_CONFIG"

    print_final_banner "$local_node" "$server_host" "$ami_user" "$web_user" "$web_proto" "$web_pass"
}


# ------------------------------------------------------------------------------
# WebRTC Audio Bridge installation
# ------------------------------------------------------------------------------
ensure_webrtc_service_user() {
    local service_user="chilemon"

    if ! id -u "$service_user" &>/dev/null; then
        useradd --system --no-create-home --home-dir /nonexistent \
            --shell /usr/sbin/nologin "$service_user"
        ok "Usuario de servicio $service_user creado sin shell ni login"
    else
        ok "Usuario de servicio $service_user ya existe"
    fi

    # logs/ queda en root:www-data 770; el grupo da escritura al bridge sin
    # necesidad de otorgar escritura sobre el código fuente.
    usermod -aG www-data "$service_user" 2>/dev/null || \
        warn "No se pudo agregar $service_user al grupo www-data"
}

install_webrtc_bridge() {
    info "Instalando puente WebRTC para PTT desde el navegador"
    info "Esto permite hablar por radio SIN equipos externos 🇨🇱"

    SERVICE_SRC="$REPO_DIR/install/chilemon-webrtc.service"
    SERVICE_DST="/etc/systemd/system/chilemon-webrtc.service"
    DEFAULT_ENV_FILE="/etc/default/chilemon-webrtc"

    # Step A: Verify Python 3.11+
    if ! command -v python3 &>/dev/null; then
        warn "python3 no encontrado — se omite WebRTC bridge"
        return
    fi

    PYTHON_VERSION=$(python3 -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')
    info "Python detectado: ${PYTHON_VERSION}"

    if ! command -v bc &>/dev/null; then
        apt-get install -y bc >/dev/null 2>&1
    fi

    if [[ $(echo "$PYTHON_VERSION < 3.11" | bc -l) -eq 1 ]]; then
        warn "Python ${PYTHON_VERSION} — se requiere 3.11+ para audioop, se omite WebRTC bridge"
        return
    fi

    ok "Python ${PYTHON_VERSION} cumple requisito mínimo (3.11+)"

    # Step B: Install Python packages
    apt-get install -y python3-aiohttp python3-aiohttp-cors python3-websockets python3-venv

    # aiortc may need pip
    if dpkg -s python3-aiortc &>/dev/null 2>&1; then
        ok "python3-aiortc instalado vía apt"
    else
        info "python3-aiortc no disponible en apt — intentando pip"
        local pip_cmd=""
        if command -v pip3 &>/dev/null; then
            pip_cmd="pip3"
        elif command -v pip &>/dev/null; then
            pip_cmd="pip"
        fi

        if [[ -n "$pip_cmd" ]]; then
            local pip_flags="--ignore-installed"
            if "$pip_cmd" install --help 2>/dev/null | grep -q -- '--break-system-packages'; then
                pip_flags="--break-system-packages $pip_flags"
                info "PEP 668 detectado — usando $pip_flags"
            fi
            if "$pip_cmd" install $pip_flags "aiortc>=1.4.0"; then
                ok "aiortc instalado vía $pip_cmd"
            else
                warn "No se pudo instalar aiortc con $pip_cmd — WebRTC PTT puede no funcionar"
            fi

            # Python 3.13 removed audioop; the bridge falls back to audioop_lts.
            if ! python3 -c "import audioop" &>/dev/null; then
                info "Python sin módulo audioop — instalando audioop-lts"
                if "$pip_cmd" install $pip_flags audioop-lts; then
                    ok "audioop-lts instalado vía $pip_cmd"
                else
                    warn "No se pudo instalar audioop-lts — el bridge no arrancará"
                fi
            fi
        else
            warn "pip no disponible — WebRTC deshabilitado (se requiere aiortc)"
        fi
    fi

    # Verify critical imports
    local import_fail=0
    if python3 -c "import aiohttp" &>/dev/null; then
        ok "python3-aiohttp verificada"
    else
        warn "python3-aiohttp no se importa — WebRTC puede fallar"
        import_fail=1
    fi
    if python3 -c "import websockets" &>/dev/null; then
        ok "python3-websockets verificada"
    else
        warn "python3-websockets no importable — WebSocket podría no funcionar"
        import_fail=1
    fi
    if python3 -c "import aiortc" &>/dev/null; then
        ok "python3-aiortc verificada"
    else
        warn "python3-aiortc no importable — el PTT desde el navegador no funcionará"
        import_fail=1
    fi
    if python3 -c "import audioop" &>/dev/null || python3 -c "import audioop_lts" &>/dev/null; then
        ok "audioop disponible"
    else
        warn "audioop no importable — el bridge no arrancará en Python 3.13+"
        import_fail=1
    fi

    if [[ "$import_fail" -ne 0 ]]; then
        warn "Dependencias críticas faltantes — WebRTC bridge puede no funcionar"
    fi

    # Step C: Create the unprivileged service user, then install the unit
    ensure_webrtc_service_user

    if [[ ! -f "$SERVICE_SRC" ]]; then
        warn "No se encuentra $SERVICE_SRC — se omite unidad systemd"
    else
        if [[ -f "$SERVICE_DST" ]]; then
            info "Unidad systemd ya existe — preservando"
        else
            cp "$SERVICE_SRC" "$SERVICE_DST"
            chmod 644 "$SERVICE_DST"
            ok "Unidad systemd instalada: $SERVICE_DST"
        fi
    fi

    # Step D: Create or synchronize the env file with the generated secrets.
    write_webrtc_env_file "$local_node"

    # Step E: Enable and start service
    systemctl daemon-reload
    systemctl enable chilemon-webrtc.service 2>/dev/null || true

    if systemctl is-active --quiet chilemon-webrtc.service 2>/dev/null; then
        ok "Servicio chilemon-webrtc activo"
    else
        systemctl restart chilemon-webrtc.service 2>/dev/null || true
        if systemctl is-active --quiet chilemon-webrtc.service 2>/dev/null; then
            ok "Servicio chilemon-webrtc iniciado"
        else
            warn "Servicio chilemon-webrtc no activo — revise con: systemctl status chilemon-webrtc"
        fi
    fi

    if [[ "$import_fail" -eq 0 ]]; then
        ok "Puente WebRTC instalado — PTT desde el navegador disponible"
    else
        warn "Puente WebRTC instalado pero incompleto — revise los avisos anteriores"
    fi
}


configure_git_permissions() {
    # Updates run as root through the sudo wrapper, so www-data does NOT need
    # write access to the source tree. The previous whole-repo group write let
    # any web-level foothold edit code executed as root (privilege escalation).
    info "Endureciendo permisos del repositorio y directorios de ejecución"

    local repo_owner
    repo_owner="$(stat -c '%U' "$REPO_DIR/.git/HEAD" 2>/dev/null || echo root)"

    # Restore source ownership to its deploy user and drop group write/setgid.
    chown -R "${repo_owner}:${repo_owner}" "$REPO_DIR" 2>/dev/null || \
        warn "No se pudo normalizar el dueño de $REPO_DIR"
    chmod -R g-w "$REPO_DIR" 2>/dev/null || true
    find "$REPO_DIR" -type d -exec chmod g-s {} + 2>/dev/null || true

    # config/local.php holds the generated secrets: readable by www-data,
    # never writable by it, and restored after the repo-wide ownership reset.
    if [[ -f "$LOCAL_CONFIG" ]]; then
        chown root:www-data "$LOCAL_CONFIG"
        chmod 640 "$LOCAL_CONFIG"
    fi

    # Only runtime directories need to be writable by the web user.
    local runtime_dir
    for runtime_dir in data logs backups; do
        mkdir -p "$REPO_DIR/$runtime_dir"
        chown root:www-data "$REPO_DIR/$runtime_dir"
        chmod 770 "$REPO_DIR/$runtime_dir"
    done

    # SQLite needs group write on the database file and its WAL sidecars.
    if [[ -f "$REPO_DIR/data/chilemon.sqlite" ]]; then
        chown root:www-data "$REPO_DIR/data/chilemon.sqlite"
        chmod 660 "$REPO_DIR/data/chilemon.sqlite"
    fi

    ok "Permisos endurecidos: código no escribible por www-data; escritura solo en data/, logs/, backups/"
}


main() {
    require_root

    clear_screen
    echo "ChileMon - Instalador automático"
    echo "--------------------------------"
    echo "Proyecto detectado en: $REPO_DIR"

    # ----------------------------------------------------------
    # Detection: NEW install vs UPDATE
    # ----------------------------------------------------------
    if [[ -f "$LOCAL_CONFIG" ]]; then
        INSTALL_MODE="update"
        echo
        echo "[UPDATE] Se detectó configuración existente en $LOCAL_CONFIG"
        echo "[UPDATE] Este modo SOLO instala dependencias faltantes."
        echo "[UPDATE] NO se sobrescribirán configuraciones existentes."
        echo
        read -r -p "¿Desea continuar con la actualización? [s/N]: " confirm
        [[ "$confirm" =~ ^[sS]$ ]] || { echo "Instalación cancelada."; exit 0; }
    else
        INSTALL_MODE="new"
        echo
        echo "[NUEVA] No se detectó configuración previa. Se realizará una instalación completa."
        echo "Este script no elimina el proyecto existente."
        echo
        read -r -p "¿Desea continuar con la instalación? [s/N]: " confirm
        [[ "$confirm" =~ ^[sS]$ ]] || { echo "Instalación cancelada."; exit 0; }
    fi

        step "1" "Validando estructura del repositorio"
    check_repo_structure
    ok "Estructura del repositorio validada"

    step "2" "Instalando dependencias base"
    apt-get update
    ensure_package git
    ensure_package apache2
    ensure_package php
    ensure_package php-sqlite3
    ensure_package php-curl
    ensure_package php-mbstring
    ensure_package sqlite3
    ensure_package sudo
    ensure_package python3
    ensure_package python3-pip
    ensure_package python3-venv
    ok "Dependencias instaladas o ya presentes"

    # ----------------------------------------------------------
    # Variables for configuration (populated differently per mode)
    # ----------------------------------------------------------
    local local_node=""
    local server_host=""
    local web_proto=""
    local header_tagline=""
    local ami_host=""
    local ami_port=""
    local ami_user=""
    local ami_pass=""
    local ami_timeout="$DEFAULT_AMI_TIMEOUT"
    local hub_url=""

    if [[ "$INSTALL_MODE" == "new" ]]; then
        # ----------------------------------------------------------
        # NEW INSTALLATION — interactive prompts
        # ----------------------------------------------------------
        step "3" "Detectando datos del nodo y solicitando confirmación"

        while [[ -z "$local_node" ]]; do
            read -r -p "Ingrese su N° de nodo ASL local: " local_node
            [[ "$local_node" =~ ^[0-9]+$ ]] || { echo "Debe ingresar solo números."; local_node=""; }
        done

        detected_server_host="$(detect_server_host)"

        read -r -p "Nombre DNS o IP del servidor [${detected_server_host}]: " server_host
        server_host="${server_host:-$detected_server_host}"

        web_proto="$(detect_web_proto)"

        read -r -p "Ingrese texto descriptivo del nodo [Nodo local ChileMon]: " header_tagline
        header_tagline="${header_tagline:-Nodo local ChileMon}"

        echo
        echo "--- Mapa comunitario ChileMon (opcional) ---"
        echo "Si tienes un hub central, puedes registrar este nodo en el mapa comunitario."
        echo "Ejemplo: http://192.168.0.111"
        read -r -p "URL del hub ChileMon (dejar vacío para omitir): " hub_url
        echo

        ami_host="$(detect_ami_host)"
        ami_port="$(detect_ami_port)"
        ami_user="$(detect_ami_user)"

        echo
        info "Se detectó la siguiente configuración AMI:"
        info "  Host    : ${ami_host}"
        info "  Puerto  : ${ami_port}"
        info "  Usuario : ${ami_user}"
        info "  Timeout : ${ami_timeout} segundos"
        echo

        echo "Ingrese la clave AMI configurada en /etc/asterisk/manager.conf."
        echo "El usuario AMI corresponde al nombre del bloque detectado, "
        echo "por ejemplo: [admin] => usuario admin."
        while [[ -z "$ami_pass" ]]; do
            read -r -s -p "Ingrese clave AMI (obligatorio): " ami_pass
            echo ""
            if [[ -z "$ami_pass" ]]; then
                echo "[ERROR] La clave AMI es obligatoria. Revisa /etc/asterisk/manager.conf"
            fi
        done

        ok "Nodo local capturado: $local_node"
        ok "Servidor detectado/configurado: $server_host"
        ok "Usuario AMI detectado: $ami_user"

    else
        # ----------------------------------------------------------
        # UPDATE MODE — read existing config, skip prompts
        # ----------------------------------------------------------
        step "3" "Leyendo configuración existente"

        # Extract values from existing local.php (which returns an array)
        local_node="$(php -r "\$cfg = require '$LOCAL_CONFIG'; echo \$cfg['local_node'] ?? '';")"
        server_host="$(php -r "\$cfg = require '$LOCAL_CONFIG'; echo \$cfg['server_host'] ?? '';")"
        header_tagline="$(php -r "\$cfg = require '$LOCAL_CONFIG'; echo \$cfg['header_tagline'] ?? '';")"
        ami_host="$(php -r "\$cfg = require '$LOCAL_CONFIG'; echo \$cfg['ami_host'] ?? '';")"
        ami_port="$(php -r "\$cfg = require '$LOCAL_CONFIG'; echo \$cfg['ami_port'] ?? '';")"
        ami_user="$(php -r "\$cfg = require '$LOCAL_CONFIG'; echo \$cfg['ami_user'] ?? '';")"

        web_proto="$(detect_web_proto)"

        if [[ -z "$local_node" ]]; then
            warn "No se pudo extraer local_node de $LOCAL_CONFIG"
            warn "Continuando con modo actualización sin valores de configuración"
        else
            ok "Configuración existente detectada:"
            ok "  Nodo       : $local_node"
            ok "  Servidor   : $server_host"
            ok "  Usuario AMI: $ami_user"
        fi
    fi

    step "4" "Preparando carpetas y permisos"
    mkdir -p "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR" "$REPO_DIR/config"
    chown -R www-data:www-data "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR"
    chmod -R 775 "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR"
    ok "Carpetas preparadas y permisos aplicados"

    step "5" "Configurando permisos git para actualizaciones desde el dashboard"
    configure_git_permissions

    step "6" "Generando configuración local"
    if [[ "$INSTALL_MODE" == "update" ]]; then
        info "Modo actualización — configuración existente preservada"
        ensure_companion_secret_in_config
        ensure_webrtc_secrets_in_config
        ok "Archivo de configuración mantenido: $LOCAL_CONFIG"
    else
        write_local_config \
            "$local_node" \
            "$server_host" \
            "$header_tagline" \
            "$ami_host" \
            "$ami_port" \
            "$ami_user" \
            "$ami_pass" \
            "$ami_timeout" \
            "$hub_url"
    fi

    step "7" "Configurando módulos de Asterisk para ASL3"
    configure_asl3_modules

    step "8" "Configurando Asterisk para WebRTC"
    configure_webrtc_asterisk "$local_node"

    step "9" "Instalando wrapper y sudoers"
    write_wrapper_if_missing
    write_sudoers

    step "10" "Configurando Apache"
    write_apache_config

    step "11" "Habilitando proxy WebSocket para Apache"
    enable_apache_websocket

    step "12" "Validando PHP e inicializando ChileMon"
    validate_php_modules
    run_php_installer

    step "13" "Instalando puente WebRTC"
    install_webrtc_bridge

    if [[ "$INSTALL_MODE" == "new" ]]; then
        step "14" "Creando usuario administrador de ChileMon"
        run_php_user_creation
    else
        step "14" "Verificación de la instalación"
        run_verification
    fi

    local web_user
    if [[ "$INSTALL_MODE" == "new" ]]; then
        if [[ -n "$WEB_ADMIN_USER" && -n "$WEB_ADMIN_PASS" ]]; then
            web_user="$WEB_ADMIN_USER"
        else
            web_user="NO CREADO — ejecute: sudo php $INSTALLER_PHP"
        fi
    else
        web_user="existente (sin cambios)"
    fi

    validate_installation "$local_node" "$server_host" "$ami_user" "$web_user" "$web_proto" "$WEB_ADMIN_PASS"
}

main "$@"
