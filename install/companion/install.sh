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
CONFIG_DIR="/etc/chilemon-companion"
SERVICE_NAME="chilemon-companion"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# --- Identity (never hardcoded) ---
# CHILEMON_IAX_SECRET must match config/local.php => iax_companion_secret on the Pi.
# CHILEMON_LOCAL_NODE is the ASL node the companion always enters.
IAX_SECRET="${CHILEMON_IAX_SECRET:-}"
LOCAL_NODE="${CHILEMON_LOCAL_NODE:-}"
PI_HOST="${CHILEMON_PI_HOST:-}"
IAX_LAN_ACL="${CHILEMON_LAN_ACL:-}"
IAX_LAN_CIDR="${CHILEMON_LAN_CIDR:-}"

resolve_identity() {
    if [[ -z "$IAX_SECRET" ]]; then
        IAX_SECRET="$(openssl rand -hex 24)"
        log_warn "No CHILEMON_IAX_SECRET supplied — generated a random secret."
        log_warn "Copy it to the Pi: config/local.php => iax_companion_secret"
    fi

    if [[ ! "$LOCAL_NODE" =~ ^[0-9]+$ ]]; then
        log_error "CHILEMON_LOCAL_NODE must be a numeric ASL node id."
        exit 1
    fi
}

resolve_lan_acl() {
    local iface cidr prefix network full

    if [[ -z "$IAX_LAN_CIDR" ]]; then
        iface="$(ip -4 route show default 2>/dev/null \
            | awk '{for(i=1;i<=NF;i++) if($i=="dev"){print $(i+1); exit}}')"
        if [[ -n "$iface" ]]; then
            cidr="$(ip -4 route show dev "$iface" 2>/dev/null \
                | awk '$1 ~ /\// {print $1; exit}')"
        fi
        IAX_LAN_CIDR="${cidr:-127.0.0.1/32}"
    fi

    [[ -n "$IAX_LAN_ACL" ]] && return 0

    if [[ "$IAX_LAN_CIDR" == "127.0.0.1/32" ]]; then
        IAX_LAN_ACL="127.0.0.1/255.255.255.255"
        log_warn "LAN not detected — IAX2 restricted to loopback only."
        return 0
    fi

    network="${IAX_LAN_CIDR%/*}"
    prefix="${IAX_LAN_CIDR#*/}"
    full=$(( (0xFFFFFFFF << (32 - prefix)) & 0xFFFFFFFF ))
    IAX_LAN_ACL="$(printf '%s/%d.%d.%d.%d' "$network" \
        $(((full >> 24) & 255)) $(((full >> 16) & 255)) \
        $(((full >> 8) & 255)) $((full & 255)))"
}

resolve_identity
resolve_lan_acl

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

# --- Create default config with the resolved identity, never a shipped secret ---
if [ ! -f "$CONFIG_DIR/config.toml" ]; then
    log_info "Creating config at $CONFIG_DIR/config.toml"
    sed -e "s|password = \"\"|password = \"$IAX_SECRET\"|" \
        -e "s|host = \"192.168.0.116\"|host = \"$PI_HOST\"|" \
        "$INSTALL_DIR/config.toml" > "$CONFIG_DIR/config.toml"
    chmod 600 "$CONFIG_DIR/config.toml"
    if [[ -z "$PI_HOST" ]]; then
        log_warn "Set [asterisk] host in $CONFIG_DIR/config.toml to the Pi address"
    fi
else
    log_info "Config already exists — not overwriting"
fi

# --- Create systemd service ---
log_info "Creating systemd service: $SERVICE_NAME"

# The companion only needs local audio and a UDP socket: run it as a
# dedicated unprivileged system user, never as the installing root user.
COMPANION_USER="chilemon-companion"
if ! id -u "$COMPANION_USER" &>/dev/null; then
    sudo useradd --system --no-create-home --home-dir /nonexistent \
        --shell /usr/sbin/nologin "$COMPANION_USER"
    log_info "Service user $COMPANION_USER created"
fi
chown -R "$COMPANION_USER:$COMPANION_USER" "$INSTALL_DIR"
chown "$COMPANION_USER:$COMPANION_USER" "$CONFIG_DIR" "$CONFIG_DIR/config.toml"
chmod 700 "$CONFIG_DIR"

sudo tee "$SERVICE_FILE" > /dev/null <<EOF
[Unit]
Description=ChileMon Companion Audio App
After=asterisk.service network.target
Wants=asterisk.service

[Service]
Type=simple
User=${COMPANION_USER}
Group=${COMPANION_USER}
ExecStart=/usr/bin/python3 ${INSTALL_DIR}/main.py --config ${CONFIG_DIR}/config.toml
Restart=on-failure
RestartSec=5
NoNewPrivileges=yes
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
        sudo tee -a "$IAX_CONF" > /dev/null <<PEEREOF

[companion-app]
type=friend
host=dynamic
context=radio-companion
secret=$IAX_SECRET
deny=0.0.0.0/0.0.0.0
permit=$IAX_LAN_ACL
disallow=all
allow=ulaw
qualify=yes
qualifyfreqok=60000
qualifyfreqnotok=10000
maxms=5000
requirecalltoken=no
calltokenoptional=$IAX_LAN_CIDR
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
    echo "  secret=$IAX_SECRET"
    echo "  deny=0.0.0.0/0.0.0.0"
    echo "  permit=$IAX_LAN_ACL"
    echo "  disallow=all"
    echo "  allow=ulaw"
    echo "  qualify=yes"
    echo "  qualifyfreqok=60000"
    echo "  qualifyfreqnotok=10000"
    echo "  maxms=5000"
    echo "  requirecalltoken=no"
    echo "  calltokenoptional=$IAX_LAN_CIDR"
fi

# --- Inject [radio-companion] context into /etc/asterisk/extensions.conf ---
EXT_CONF="/etc/asterisk/extensions.conf"
if [ -f "$EXT_CONF" ]; then
    if grep -q "^\[radio-companion\]" "$EXT_CONF"; then
        log_info "Context [radio-companion] already exists in $EXT_CONF — skipping"
    else
        log_info "Injecting [radio-companion] context from the canonical dialplan"
        # Single source of truth: install/asterisk/extensions-companion.conf.
        # The node is fixed to CHILEMON_LOCAL_NODE via rpt(__LOCAL_NODE__|S);
        # caller-controlled digits never reach rpt().
        sudo sed "s|__LOCAL_NODE__|${LOCAL_NODE}|g" \
            "$REPO_ROOT/install/asterisk/extensions-companion.conf" \
            | sudo tee -a "$EXT_CONF" > /dev/null
        log_info "Done — run 'asterisk -rx \"core reload\"' to apply"
    fi
else
    log_warn "$EXT_CONF not found — add [radio-companion] context manually"
    echo "  Canonical source: $REPO_ROOT/install/asterisk/extensions-companion.conf"
    echo "  Replace __LOCAL_NODE__ with $LOCAL_NODE (CHILEMON_LOCAL_NODE)."
fi
