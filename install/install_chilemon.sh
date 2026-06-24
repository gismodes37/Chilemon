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
INSTALL_RPT_DOCS="$REPO_DIR/install/asterisk/rpt.conf"
DEFAULT_NODE_PROTO="https"
ASL3_MODULES_ADDED=0
INSTALL_MODE="new"

TOTAL_STEPS=12


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
# ChileMon - Wrapper seguro para comandos rpt de Asterisk (v0.3.1)
# ==============================================================================
# Limpieza de parámetros para evitar inyecciones y fallos de comillas desde PHP
CMD=$(echo ${1:-} | tr -d "'\"")
LOCAL=$(echo ${2:-} | tr -d "'\"")
REMOTE=$(echo ${3:-} | tr -d "'\"")

# LOG DE DEPURACIÓN (Ayuda a ver qué llega desde la web)
# echo "[$(date)] WEB_CMD: $CMD LOCAL:$LOCAL REMOTE:$REMOTE" >> /tmp/chilemon.log

case "$CMD" in
    nodes) /usr/sbin/asterisk -rx "rpt nodes $LOCAL" ;;
    stats) /usr/sbin/asterisk -rx "rpt stats $LOCAL" ;;
    connect)
        # Conectar a nodo remoto (*3 = transceive)
        # Funciona tanto para ASL como EchoLink (prefijo 3xxxxxx)
        /usr/sbin/asterisk -rx "rpt fun $LOCAL *3$REMOTE"
        ;;
    disconnect) /usr/sbin/asterisk -rx "rpt fun $LOCAL *1$REMOTE" ;;
    sys-restart-asterisk) systemctl restart asterisk ;;
    sys-restart-apache) systemctl restart apache2 ;;
    sys-poweroff) poweroff ;;
    *) echo "Comando invalido: $CMD" >> /tmp/chilemon.log; exit 1 ;;
esac
BASH

    chmod 755 "$WRAPPER_PATH"
    chown root:root "$WRAPPER_PATH"
    ok "Wrapper robusto instalado en $WRAPPER_PATH"
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

ProxyPass /ws ws://127.0.0.1:9091/ws
ProxyPassReverse /ws ws://127.0.0.1:9091/ws
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

    php "$INSTALLER_PHP"
    ok "Creación de usuario completada"
}


configure_asl3_modules() {
    local modules_conf="/etc/asterisk/modules.conf"
    local -a required_modules=(res_http_websocket res_aeap res_sorcery_config \
        res_pjproject res_srtp res_rtp_asterisk bridge_softmix chan_bridge_media)
    local added=0

    if [[ ! -f "$modules_conf" ]] || ! grep -q '^autoload=no' "$modules_conf"; then
        warn "ASL3 no detectado — se omite configuración de módulos"
        return 0
    fi

    info "ASL3 detectado en $modules_conf"
    backup_if_exists "$modules_conf"

    local existing
    existing=$(grep -E '^load\s*=>\s*' "$modules_conf" | sed 's/.*=>\s*//' | tr -d ' ')

    for mod in "${required_modules[@]}"; do
        if ! echo "$existing" | grep -qxF "$mod"; then
            echo "load => $mod" >> "$modules_conf"
            added=$((added + 1))
            info "  load => $mod agregado"
        fi
    done

    if [[ "$added" -gt 0 ]]; then
        ok "Módulos Asterisk: $added load => agregados"
        warn "Reinicie Asterisk manualmente: systemctl restart asterisk"
    else
        ok "Todos los módulos ya estaban presentes"
    fi

    ASL3_MODULES_ADDED="$added"
}


configure_webrtc_asterisk() {
    info "Configurando Asterisk para WebRTC Audio Bridge"

    # Step 1: Append IAX2 snippet to iax.conf
    if [[ -f "$INSTALL_IAX_SNIPPET" ]]; then
        if [[ -f "$ASTERISK_IAX_CONF" ]]; then
            # Check if [webrtc-bridge] section already exists in target
            if grep -q '^\[webrtc-bridge\]' "$ASTERISK_IAX_CONF"; then
                ok "Sección [webrtc-bridge] ya existe en $ASTERISK_IAX_CONF — se omite"
            else
                backup_if_exists "$ASTERISK_IAX_CONF"
                echo "" >> "$ASTERISK_IAX_CONF"
                cat "$INSTALL_IAX_SNIPPET" >> "$ASTERISK_IAX_CONF"
                ok "Snippet IAX2 ([webrtc-bridge]) agregado a $ASTERISK_IAX_CONF"
                warn "IMPORTANTE: Cambie CHANGEME_PHONE_SECRET en $ASTERISK_IAX_CONF"
            fi
        else
            warn "$ASTERISK_IAX_CONF no existe — se omite snippet IAX2"
        fi
    else
        warn "Snippet IAX2 no encontrado en $INSTALL_IAX_SNIPPET"
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

    # Check systemd service (if webrtc installer has been run)
    if systemctl list-unit-files | grep -q 'chilemon-webrtc.service'; then
        ok "Servicio systemd: chilemon-webrtc registrado"
        pass=$((pass + 1))
    else
        info "Servicio chilemon-webrtc no registrado (instale con install_webrtc.sh)"
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
    echo -e " ${C_GREEN}Dirección nodo :${C_RESET} ${DEFAULT_NODE_PROTO}://${server_host}"
    echo -e " ${C_GREEN}Acceso ChileMon:${C_RESET} ${DEFAULT_NODE_PROTO}://${server_host}/chilemon"
    echo
    echo -e " ${C_GREEN}Usuario web    :${C_RESET} ${web_user}"
    echo -e " ${C_GREEN}Módulos ASL3   :${C_RESET} ${ASL3_MODULES_ADDED:-0} load => agregados"
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

    echo
    echo "Resumen técnico"
    echo "---------------"
    echo "Modo     : ${INSTALL_MODE}"
    echo "Proyecto : $REPO_DIR"
    echo "Wrapper  : $WRAPPER_PATH"
    echo "Config   : $LOCAL_CONFIG"

    print_final_banner "$local_node" "$server_host" "$ami_user" "$web_user" "$web_proto"
}


main() {
    require_root

    clear || true
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

    step "1 de ${TOTAL_STEPS}" "Validando estructura del repositorio"
    check_repo_structure
    ok "Estructura del repositorio validada"

    step "2 de ${TOTAL_STEPS}" "Instalando dependencias base"
    apt-get update
    ensure_package git
    ensure_package apache2
    ensure_package php
    ensure_package php-sqlite3
    ensure_package php-curl
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

    if [[ "$INSTALL_MODE" == "new" ]]; then
        # ----------------------------------------------------------
        # NEW INSTALLATION — interactive prompts
        # ----------------------------------------------------------
        step "3 de ${TOTAL_STEPS}" "Detectando datos del nodo y solicitando confirmación"

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
        step "3 de ${TOTAL_STEPS}" "Leyendo configuración existente"

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

    step "4 de ${TOTAL_STEPS}" "Preparando carpetas y permisos"
    mkdir -p "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR" "$REPO_DIR/config"
    chown -R www-data:www-data "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR"
    chmod -R 775 "$DATA_DIR" "$LOG_DIR" "$BACKUP_DIR"
    ok "Carpetas preparadas y permisos aplicados"

    step "5 de ${TOTAL_STEPS}" "Generando configuración local"
    if [[ "$INSTALL_MODE" == "update" ]]; then
        info "Modo actualización — configuración existente preservada"
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
            "$ami_timeout"
    fi

    step "6 de ${TOTAL_STEPS}" "Configurando módulos de Asterisk para ASL3"
    configure_asl3_modules

    step "7 de ${TOTAL_STEPS}" "Configurando Asterisk para WebRTC"
    configure_webrtc_asterisk

    step "8 de ${TOTAL_STEPS}" "Instalando wrapper y sudoers"
    write_wrapper_if_missing
    write_sudoers

    step "9 de ${TOTAL_STEPS}" "Configurando Apache"
    write_apache_config

    step "10 de ${TOTAL_STEPS}" "Habilitando proxy WebSocket para Apache"
    enable_apache_websocket

    step "11 de ${TOTAL_STEPS}" "Validando PHP e inicializando ChileMon"
    validate_php_modules
    run_php_installer

    if [[ "$INSTALL_MODE" == "new" ]]; then
        step "12 de ${TOTAL_STEPS}" "Creando usuario administrador de ChileMon"
        run_php_user_creation
    else
        step "12 de ${TOTAL_STEPS}" "Verificación de la instalación"
        run_verification
    fi

    validate_installation "$local_node" "$server_host" "$ami_user" "definido durante instalación" "$web_proto"
}

main "$@"
