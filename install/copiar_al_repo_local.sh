#!/usr/bin/env bash
# ==============================================================================
# ChileMon - Copia archivos nuevos al repositorio local
# ------------------------------------------------------------------------------
# Uso:
#   bash install/copiar_al_repo_local.sh /opt/chilemon
# ==============================================================================

set -Eeuo pipefail

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TARGET_REPO="${1:-}"
TS="$(date +%Y%m%d_%H%M%S)"

if [[ -z "$TARGET_REPO" ]]; then
    echo "Uso: bash install/copiar_al_repo_local.sh /ruta/al/repo"
    exit 1
fi

if [[ ! -d "$TARGET_REPO" ]]; then
    echo "[ERROR] La ruta destino no existe: $TARGET_REPO"
    exit 1
fi

mkdir -p "$TARGET_REPO/install" "$TARGET_REPO/config" "$TARGET_REPO/docs"

copy_with_backup() {
    local src="$1"
    local dst="$2"

    if [[ -e "$dst" ]]; then
        cp -a "$dst" "${dst}.bak.${TS}"
        echo "[OK] Respaldo creado: ${dst}.bak.${TS}"
    fi

    cp "$src" "$dst"
    echo "[OK] Copiado: $dst"
}

copy_with_backup "$SOURCE_DIR/install/install_chilemon.sh" "$TARGET_REPO/install/install_chilemon.sh"
copy_with_backup "$SOURCE_DIR/install/bootstrap.sh" "$TARGET_REPO/install/bootstrap.sh"
copy_with_backup "$SOURCE_DIR/config/local.php.example" "$TARGET_REPO/config/local.php.example"
copy_with_backup "$SOURCE_DIR/docs/GUIA_RAPIDA_INSTALACION_CHILEMON.md" "$TARGET_REPO/docs/GUIA_RAPIDA_INSTALACION_CHILEMON.md"
copy_with_backup "$SOURCE_DIR/docs/INSTALACION_TECNICA_CHILEMON.md" "$TARGET_REPO/docs/INSTALACION_TECNICA_CHILEMON.md"

echo
echo "Proceso completado."
echo "Revise el repositorio destino y luego ejecute:"
echo "  cd $TARGET_REPO"
echo "  sudo bash install/install_chilemon.sh"
