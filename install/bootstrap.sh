#!/usr/bin/env bash
# ==============================================================================
# ChileMon - Bootstrap de instalación desde repositorio
# ------------------------------------------------------------------------------
# Este script descarga o actualiza el repositorio oficial en /opt/chilemon y
# luego ejecuta el instalador principal.
# ==============================================================================

set -Eeuo pipefail

REPO_URL="https://github.com/gismodes37/chilemon.git"
TARGET_DIR="/opt/chilemon"

if [[ "$EUID" -ne 0 ]]; then
    echo "[ERROR] Ejecute este script con sudo o como root."
    exit 1
fi

echo "ChileMon - Bootstrap"
echo "---------------------"

apt-get update
apt-get install -y git

if [[ -d "$TARGET_DIR/.git" ]]; then
    echo "[INFO] Repositorio existente detectado. Actualizando..."
    git -C "$TARGET_DIR" pull --ff-only
else
    echo "[INFO] Clonando repositorio en $TARGET_DIR"
    git clone "$REPO_URL" "$TARGET_DIR"
fi

cd "$TARGET_DIR"
echo "[INFO] Ejecutando instalador principal..."
exec bash install/install_chilemon.sh
