#!/bin/bash
# install/companion/install.sh
# ChileMon Companion App — Linux Installer
#
# Installs Python dependencies, sets up systemd service,
# and creates default config.
#
# Usage:
#   sudo bash install/companion/install.sh
#
# Requirements:
#   - Python 3.10+
#   - pip
#   - portaudio-dev (for pyaudio)
#   - Running Asterisk with IAX2 configured

set -euo pipefail

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info()  { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# --- Configuration ---
INSTALL_DIR="/opt/chilemon/companion"
CONFIG_DIR="$HOME/.chilemon"
SERVICE_NAME="chilemon-companion"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

log_info "Installing ChileMon Companion App"

# --- Check Python ---
if ! command -v python3 &>/dev/null; then
    log_error "Python 3 is required"
    exit 1
fi

PYTHON_VERSION=$(python3 --version 2>&1 | grep -oP '\d+\.\d+')
log_info "Python version: $PYTHON_VERSION"

# --- Install system dependencies ---
log_info "Installing system dependencies..."
if command -v apt-get &>/dev/null; then
    sudo apt-get update -qq
    sudo apt-get install -y -qq python3-pip python3-sounddevice python3-numpy 2>/dev/null || true
elif command -v yum &>/dev/null; then
    sudo yum install -y python3-pip portaudio-devel 2>/dev/null || true
elif command -v pacman &>/dev/null; then
    sudo pacman -S --noconfirm portaudio python-pip 2>/dev/null || true
else
    log_warn "Unknown package manager — install portaudio-dev manually"
fi

# --- Create directories ---
mkdir -p "$INSTALL_DIR"
mkdir -p "$CONFIG_DIR"

# --- Copy companion app files ---
log_info "Copying companion app to $INSTALL_DIR"
cp -r "$REPO_ROOT/companion/"* "$INSTALL_DIR/"

# --- Install Python dependencies ---
log_info "Installing Python packages..."
pip3 install --upgrade pip --quiet
pip3 install sounddevice aiohttp numpy --quiet

# --- Create default config ---
if [ ! -f "$CONFIG_DIR/config.toml" ]; then
    log_info "Creating default config at $CONFIG_DIR/config.toml"
    cp "$INSTALL_DIR/config.toml" "$CONFIG_DIR/config.toml"
    log_warn "EDIT $CONFIG_DIR/config.toml with your IAX2 peer password"
else
    log_info "Config already exists — not overwriting"
fi

# --- Create systemd service ---
log_info "Creating systemd service: $SERVICE_NAME"

sudo tee "$SERVICE_FILE" > /dev/null <<EOF
[Unit]
Description=ChileMon Companion Audio App
After=asterisk.service network.target
Wants=asterisk.service

[Service]
Type=simple
User=$USER
ExecStart=/usr/bin/python3 ${INSTALL_DIR}/main.py --config ${CONFIG_DIR}/config.toml
Restart=on-failure
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

sudo systemctl daemon-reload
sudo systemctl enable "$SERVICE_NAME"

log_info "Installation complete!"
echo ""
echo "Next steps:"
echo "  1. Edit your config: nano $CONFIG_DIR/config.toml"
echo "  2. Start the service: sudo systemctl start $SERVICE_NAME"
echo "  3. Check status:      sudo systemctl status $SERVICE_NAME"
echo "  4. View logs:         journalctl -u $SERVICE_NAME -f"
echo ""

# --- Inject [companion-app] peer into /etc/asterisk/iax.conf ---
IAX_CONF="/etc/asterisk/iax.conf"
if [ -f "$IAX_CONF" ]; then
    if grep -q "^\[companion-app\]" "$IAX_CONF"; then
        log_info "Peer [companion-app] already exists in $IAX_CONF — skipping"
    else
        log_info "Injecting [companion-app] peer into $IAX_CONF"
        sudo tee -a "$IAX_CONF" > /dev/null <<'PEEREOF'

[companion-app]
type=friend
host=dynamic
context=radio-companion
secret=chilemon2026
disallow=all
allow=ulaw
qualify=yes
qualifyfreqok=60000
qualifyfreqnotok=10000
maxms=5000
requirecalltoken=no
calltokenoptional=127.0.0.1
PEEREOF
        log_info "Done — run 'asterisk -rx \"core reload\"' to apply"
    fi
else
    log_warn "$IAX_CONF not found — add [companion-app] peer manually"
    echo ""
    echo "  [companion-app]"
    echo "  type=friend"
    echo "  context=radio-companion"
    echo "  host=dynamic"
    echo "  secret=chilemon2026"
    echo "  disallow=all"
    echo "  allow=ulaw"
    echo "  qualify=yes"
    echo "  qualifyfreqok=60000"
    echo "  qualifyfreqnotok=10000"
    echo "  maxms=5000"
    echo "  requirecalltoken=no"
    echo "  calltokenoptional=127.0.0.1"
fi

# --- Inject [radio-companion] context into /etc/asterisk/extensions.conf ---
EXT_CONF="/etc/asterisk/extensions.conf"
if [ -f "$EXT_CONF" ]; then
    if grep -q "^\[radio-companion\]" "$EXT_CONF"; then
        log_info "Context [radio-companion] already exists in $EXT_CONF — skipping"
    else
        log_info "Injecting [radio-companion] context into $EXT_CONF"
        sudo tee -a "$EXT_CONF" > /dev/null <<'CTXEOF'

[radio-companion]
exten => _X!,1,Rpt(${EXTEN})
 same => n,Hangup()
CTXEOF
        log_info "Done"
    fi
else
    log_warn "$EXT_CONF not found — add [radio-companion] context manually"
fi
