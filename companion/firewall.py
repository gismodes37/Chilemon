"""
companion/firewall.py -- Windows Firewall rule check for IAX2 inbound traffic.

On Windows, the Companion App needs to receive inbound UDP frames from Asterisk
(NEWACK, ACCEPT, audio frames, etc.). The Public network profile blocks inbound
UDP by default, even for responses to outbound traffic.

This module checks whether the required firewall rule exists and warns if not.
The rule itself must be installed by running install-firewall.ps1 as Administrator.

Usage (within companion):
    from companion.firewall import check_windows_firewall
    check_windows_firewall()
"""

from __future__ import annotations

import logging
import os
import platform
import subprocess
import sys

logger = logging.getLogger("companion.firewall")

# The rule name must match install/companion/install-firewall.ps1
RULE_NAME = "ChileMon Companion IAX2"


def _is_running_compiled() -> bool:
    """Detect if we are running as a PyInstaller .exe bundle."""
    return getattr(sys, "frozen", False) and hasattr(sys, "_MEIPASS")


def _current_program_path() -> str:
    """Return the path to the running executable.

    For compiled .exe: the actual .exe path.
    For python dev mode: the python.exe path (can't narrow further).
    """
    if _is_running_compiled():
        return sys.executable
    return sys.executable  # python.exe in dev mode


def rule_exists() -> bool:
    """Check if the ChileMon IAX2 firewall rule exists.

    Returns True if the rule is present, False if missing.
    On non-Windows or if the check fails, returns True (no action needed).
    """
    if platform.system() != "Windows":
        return True

    try:
        result = subprocess.run(
            ["netsh", "advfirewall", "firewall", "show", "rule", f"name={RULE_NAME}"],
            capture_output=True,
            text=True,
            timeout=10,
            creationflags=subprocess.CREATE_NO_WINDOW,
        )

        if result.returncode == 0 and "No rules" not in result.stdout:
            logger.debug("Firewall rule '%s' found", RULE_NAME)
            return True

        logger.info("Firewall rule '%s' not found", RULE_NAME)
        return False

    except FileNotFoundError:
        # netsh not available (unlikely on Windows, but safe)
        logger.debug("netsh not found — skipping firewall check")
        return True
    except subprocess.TimeoutExpired:
        logger.debug("Firewall check timed out — skipping")
        return True
    except Exception:
        logger.debug("Firewall check failed — skipping", exc_info=True)
        return True


def check_and_warn() -> None:
    """Check the firewall rule and warn if missing.

    Call this once during startup (before IAX2 transport opens).
    Logs a WARNING with clear remediation steps if the rule is missing
    and we are running on Windows.
    """
    if platform.system() != "Windows":
        return

    if rule_exists():
        logger.info("Windows Firewall — rule OK")
        return

    prog = _current_program_path()
    prog_name = os.path.basename(prog)

    if _is_running_compiled():
        fix_cmd = (
            f'New-NetFirewallRule -DisplayName "{RULE_NAME}" '
            f'-Direction Inbound -Protocol UDP -Program "{prog}" -Action Allow'
        )
    else:
        fix_cmd = (
            r"install-firewall.ps1 (run as Administrator)"
        )

    logger.warning(
        "Windows Firewall rule '%s' not found. "
        "IAX2 inbound UDP traffic may be blocked by Windows Firewall "
        "(Public profile blocks inbound by default).\n"
        "  To fix, open PowerShell as Administrator and run:\n"
        "    %s\n"
        "  Or change your network profile to Private:\n"
        "    Set-NetConnectionProfile -NetworkCategory Private",
        RULE_NAME,
        fix_cmd,
    )
