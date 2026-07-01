#!/usr/bin/env bash
# ==============================================================================
# ChileMon — WebRTC Audio Bridge Installer
# ------------------------------------------------------------------------------
# Installs and enables the chilemon-webrtc systemd service alongside the
# main ChileMon installation. Requires Python 3.11+ (Debian 12 / ASL3).
#
# Supports both NEW installations and UPDATES on existing systems.
#
# Usage:
#   sudo bash install/install_webrtc.sh
# ==============================================================================

set -Eeuo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SERVICE_SRC="$REPO_DIR/install/chilemon-webrtc.service"
SERVICE_DST="/etc/systemd/system/chilemon-webrtc.service"
DEFAULT_ENV_FILE="/etc/default/chilemon-webrtc"
INSTALL_TS="$(date +%Y%m%d_%H%M%S)"

TOTAL_STEPS=5

# Colors
if [[ -t 1 ]]; then
    C_RESET=$'\033[0m'
    C_GREEN=$'\033[1;32m'
    C_YELLOW=$'\033[1;33m'
    C_RED=$'\033[1;31m'
    C_CYAN=$'\033[1;36m'
else
    C_RESET=''; C_GREEN=''; C_YELLOW=''; C_RED=''; C_CYAN=''
fi

step() {
    echo
    echo "============================================================"
    echo "Paso $1: $2"
    echo "============================================================"
}

info()  { echo "[INFO] $1"; }
ok()    { echo "[OK]   $1"; }
warn()  { echo "[WARN] $1"; }

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
        info "Respaldo creado: $backup"
    fi
}

# ------------------------------------------------------------------------------
# Main
# ------------------------------------------------------------------------------

main() {
    require_root

    echo
    echo "${C_CYAN}ChileMon — WebRTC Audio Bridge Installer${C_RESET}"
    echo "${C_CYAN}========================================${C_RESET}"
    echo

    step "1 de ${TOTAL_STEPS}" "Validando versión de Python"

    # Verify Python 3.11+ (required for audioop module)
    if ! command -v python3 &>/dev/null; then
        echo "[ERROR] python3 no encontrado. Instale python3 primero."
        exit 1
    fi

    PYTHON_VERSION=$(python3 -c 'import sys; print(f"{sys.version_info.major}.{sys.version_info.minor}")')
    info "Python detectado: ${PYTHON_VERSION}"

    # Use bc for version comparison
    if ! command -v bc &>/dev/null; then
        apt-get install -y bc >/dev/null 2>&1
    fi

    if [[ $(echo "$PYTHON_VERSION < 3.11" | bc -l) -eq 1 ]]; then
        echo "[ERROR] Python 3.11+ es requerido para audioop (encontrado: ${PYTHON_VERSION})"
        echo "[ERROR] Actualice Python o use Debian 12+ / ASL3"
        exit 1
    fi

    ok "Python ${PYTHON_VERSION} cumple requisito mínimo (3.11+)"

    step "2 de ${TOTAL_STEPS}" "Instalando dependencias Python"

    # Base packages
    apt-get update -qq
    apt-get install -y python3-aiohttp python3-aiohttp-cors python3-websockets python3-venv

    # PEP 668 (Debian 13+): pip refuses system install without --break-system-packages.
    # --ignore-installed avoids conflicts with Debian-packaged libs (e.g. cryptography).
    local pip_opts=""
    if pip3 install --help 2>/dev/null | grep -q break-system-packages; then
        pip_opts="--break-system-packages --ignore-installed"
    fi

    # aiortc may need pip if apt version is too old
    if python3 -c "import aiortc" &>/dev/null; then
        ok "python3-aiortc ya importable (vía apt o pip)"
    elif dpkg -s python3-aiortc &>/dev/null 2>&1; then
        ok "python3-aiortc ya instalado vía apt"
    else
        info "python3-aiortc no disponible en apt — intentando pip"
        if command -v pip3 &>/dev/null; then
            pip3 install $pip_opts "aiortc>=1.4.0" && ok "python3-aiortc instalado vía pip3" \
                || warn "python3-aiortc no se pudo instalar"
        elif command -v pip &>/dev/null; then
            pip install $pip_opts "aiortc>=1.4.0" && ok "python3-aiortc instalado vía pip" \
                || warn "python3-aiortc no se pudo instalar"
        else
            warn "pip no disponible — se omite aiortc (WebRTC deshabilitado)"
        fi
    fi

    # Step 2b: Install audioop-lts (backport for Python 3.13+)
    info "Instalando audioop-lts (compatibilidad Python ≥ 3.13)"
    if command -v pip3 &>/dev/null; then
        pip3 install $pip_opts audioop-lts >/dev/null 2>&1 && ok "audioop-lts instalado vía pip3" \
            || warn "audioop-lts no se pudo instalar"
    elif command -v pip &>/dev/null; then
        pip install $pip_opts audioop-lts >/dev/null 2>&1 && ok "audioop-lts instalado vía pip" \
            || warn "audioop-lts no se pudo instalar"
    else
        warn "pip no disponible — audioop-lts no instalado"
    fi

    # Verify critical packages are importable
    local import_fail=0

    if python3 -c "import aiohttp" &>/dev/null; then
        ok "python3-aiohttp verificada"
    else
        echo "[ERROR] python3-aiohttp no se importa correctamente"
        import_fail=1
    fi

    if python3 -c "import websockets" &>/dev/null; then
        ok "python3-websockets verificada"
    else
        warn "python3-websockets no importable — WebSocket podría no funcionar"
    fi

    if python3 -c "import audioop" &>/dev/null; then
        ok "audioop (stdlib) disponible"
    elif python3 -c "import audioop_lts" &>/dev/null; then
        ok "audioop_lts (backport) disponible"
    else
        warn "audioop/audioop_lts no disponible — transcodificación ulaw puede fallar"
    fi

    if [[ "$import_fail" -ne 0 ]]; then
        exit 1
    fi

    step "3 de ${TOTAL_STEPS}" "Instalando unidad systemd"

    if [[ ! -f "$SERVICE_SRC" ]]; then
        echo "[ERROR] No se encuentra $SERVICE_SRC"
        exit 1
    fi

    backup_if_exists "$SERVICE_DST"
    cp "$SERVICE_SRC" "$SERVICE_DST"
    chmod 644 "$SERVICE_DST"
    ok "Unidad instalada en $SERVICE_DST"

    step "4 de ${TOTAL_STEPS}" "Configurando variables de entorno"

    if [[ ! -f "$DEFAULT_ENV_FILE" ]]; then
        cat > "$DEFAULT_ENV_FILE" <<EOF
# ChileMon WebRTC Bridge — Configuration
# This file is sourced by the chilemon-webrtc systemd service.
# Edit and restart the service after changes:
#   sudo systemctl restart chilemon-webrtc

# Port for the bridge HTTP/WS server
WEBRTC_PORT=9091

# Asterisk IAX2 credentials (must match iax.conf phone extension)
IAX_PHONE_USER=webrtc-bridge
IAX_PHONE_PASS=CHANGE_ME

# HMAC secret for WebSocket auth (must match config/local.php webrtc_secret)
WEBRTC_SECRET=CHANGE_ME

# Local ASL node number
ASL_NODE=494780

# Asterisk IAX2 connection
IAX_HOST=127.0.0.1
IAX_PORT=4569

# Log level: DEBUG, INFO, WARNING, ERROR
LOG_LEVEL=INFO
EOF
        chmod 600 "$DEFAULT_ENV_FILE"
        ok "Archivo de configuración creado en $DEFAULT_ENV_FILE"
        warn "EDITE $DEFAULT_ENV_FILE y configure IAX_PHONE_PASS y WEBRTC_SECRET"
        echo
        echo "  sudo nano $DEFAULT_ENV_FILE"
        echo "  sudo systemctl restart chilemon-webrtc"
        echo
    else
        ok "Configuración ya existe en $DEFAULT_ENV_FILE"
    fi

    step "5 de ${TOTAL_STEPS}" "Habilitando e iniciando servicio"

    systemctl daemon-reload
    systemctl enable chilemon-webrtc.service
    systemctl restart chilemon-webrtc.service || true

    if systemctl is-active --quiet chilemon-webrtc.service; then
        ok "Servicio chilemon-webrtc activo"
    else
        warn "Servicio no está activo — revise con: systemctl status chilemon-webrtc"
    fi

    # Verify installation
    echo
    info "Verificación de la instalación WebRTC:"

    local pass=0

    if command -v python3 &>/dev/null; then
        ok "Python3: $(python3 --version 2>&1)"
        pass=$((pass + 1))
    fi

    if python3 -c "import aiohttp" &>/dev/null 2>&1; then
        ok "Paquete: aiohttp importable"
        pass=$((pass + 1))
    fi

    if python3 -c "import websockets" &>/dev/null 2>&1; then
        ok "Paquete: websockets importable"
        pass=$((pass + 1))
    fi

    if [[ -f "$SERVICE_DST" ]]; then
        ok "Servicio: chilemon-webrtc.service instalado"
        pass=$((pass + 1))
    fi

    if systemctl is-enabled --quiet chilemon-webrtc.service 2>/dev/null; then
        ok "Servicio: habilitado en boot"
        pass=$((pass + 1))
    fi

    info "Verificación completada: ${pass} componentes OK"

    echo
    echo "${C_CYAN}========================================${C_RESET}"
    echo "${C_GREEN}Instalación completada.${C_RESET}"
    echo
    echo "  Estado:  systemctl status chilemon-webrtc"
    echo "  Logs:    journalctl -u chilemon-webrtc -f"
    echo "  Health:  curl http://localhost:\${WEBRTC_PORT:-9091}/health"
    echo
    echo "No olvide:"
    echo "  1. Editar /etc/default/chilemon-webrtc con credenciales reales"
    echo "  2. Configurar IAX_PHONE_USER/IAX_PHONE_PASS en iax.conf"
    echo "  3. Configurar webrtc_secret en config/local.php"
    echo "${C_CYAN}========================================${C_RESET}"
}

main "$@"
