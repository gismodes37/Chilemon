"""
Contract tests for the dashboard-delivered companion installer.

The dashboard must serve a double-clickable .bat, not a bare config.toml. The
installer is a versioned template filled by the PHP endpoint; these tests pin
the observable contract of both halves without running a batch runner.

Run with: python -m pytest tests/companion/download_installer_test.py -v
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATE = (ROOT / "companion" / "installer-template.bat.tpl").read_text(
    encoding="utf-8", errors="replace"
)
ENDPOINT = (ROOT / "public" / "api" / "companion-download.php").read_text(
    encoding="utf-8", errors="replace"
)
DASHBOARD = (ROOT / "public" / "views" / "dashboard.php").read_text(
    encoding="utf-8", errors="replace"
)


def test_installer_is_a_versioned_template_with_both_tokens() -> None:
    assert TEMPLATE.count("__CHILEMON_CONFIG_B64__") == 1
    assert TEMPLATE.count("__CHILEMON_REPO_REF__") == 1
    assert TEMPLATE.lstrip().startswith("@echo off")


def test_installer_fetches_source_from_the_pinned_repo_ref() -> None:
    assert "github.com/gismodes37/Chilemon" in TEMPLATE
    assert "refs/heads/__CHILEMON_REPO_REF__.zip" in TEMPLATE
    assert TEMPLATE.index("github.com/gismodes37/Chilemon") < TEMPLATE.index(
        "Expand-Archive"
    )


def test_installer_reuses_existing_helpers_instead_of_reimplementing() -> None:
    assert "companion.install_config" in TEMPLATE
    assert "install-autostart.bat" in TEMPLATE
    assert "start-companion.bat" in TEMPLATE
    assert "schtasks /create" not in TEMPLATE
    assert "reg add" not in TEMPLATE


def test_installer_copies_both_package_roots() -> None:
    """Companion imports companion.* and app.Services.WebRTCBridge.iax2."""
    assert "iax2.py" in TEMPLATE
    assert "WebRTCBridge" in TEMPLATE


def test_installer_carries_config_as_base64() -> None:
    assert "FromBase64String" in TEMPLATE
    assert "__CHILEMON_CONFIG_B64__" in TEMPLATE


def test_installer_checks_python_and_dependencies_before_network() -> None:
    assert "where python" in TEMPLATE
    assert "pip install" in TEMPLATE
    assert "numpy" in TEMPLATE
    assert "sounddevice" in TEMPLATE
    assert TEMPLATE.index("where python") < TEMPLATE.index(
        "github.com/gismodes37/Chilemon"
    )


def test_installer_installs_every_runtime_dependency() -> None:
    """ws_server.py needs aiohttp to bind 9093; start-companion.bat gates on it."""
    pip_line = next(line for line in TEMPLATE.splitlines() if "pip install" in line)
    for package in ("numpy", "sounddevice", "aiohttp"):
        assert package in pip_line


def test_installer_propagates_exit_codes_at_every_critical_step() -> None:
    lines = TEMPLATE.splitlines()
    for marker in (
        "where python",
        "pip install",
        "DownloadFile(",
        "Expand-Archive",
        "install_config",
        'call "%SRCDIR%\\companion\\install-autostart.bat"',
        'call "%SRCDIR%\\companion\\start-companion.bat"',
    ):
        line_no = next(i for i, line in enumerate(lines) if marker in line)
        block = "\n".join(lines[line_no : line_no + 6])
        assert "if errorlevel 1" in block, marker


def test_endpoint_serves_a_bat_not_a_toml() -> None:
    assert "chilemon-companion-install.bat" in ENDPOINT
    assert "application/toml" not in ENDPOINT
    assert 'filename="config.toml"' not in ENDPOINT
    assert "installer-template.bat.tpl" in ENDPOINT
    assert "InstallerPayload" in ENDPOINT


def test_generated_config_carries_the_node_hostname() -> None:
    """The Companion needs a stable candidate when the Pi's DHCP IP changes."""
    assert 'node_host = "{$node_host}"' in ENDPOINT
    assert "gethostname()" in ENDPOINT


def test_shipped_config_has_no_pinned_pi_address() -> None:
    template = (ROOT / "companion" / "config.toml").read_text(
        encoding="utf-8", errors="replace"
    )
    assert "192.168.0.116" not in template
    assert 'host = ""' in template
    assert 'node_host = ""' in template


def test_dashboard_download_name_matches_endpoint() -> None:
    assert "chilemon-companion-install.bat" in DASHBOARD
    assert "chilemon-companion-config.toml" not in DASHBOARD
