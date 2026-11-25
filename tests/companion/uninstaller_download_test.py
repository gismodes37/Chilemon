"""
Contract tests for the dashboard-delivered companion uninstaller.

The dashboard runs on the Pi, so its Settings modal can only hand a
self-contained .bat to the Windows PC. These tests pin that contract without
running a batch runner, matching download_installer_test.py.

Run with: python -m pytest tests/companion/uninstaller_download_test.py -v
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TEMPLATE = (ROOT / "companion" / "uninstaller-template.bat.tpl").read_text(
    encoding="utf-8", errors="replace"
)
ENDPOINT = (ROOT / "public" / "api" / "companion-uninstall.php").read_text(
    encoding="utf-8", errors="replace"
)
DASHBOARD = (ROOT / "public" / "views" / "dashboard.php").read_text(
    encoding="utf-8", errors="replace"
)


def test_uninstaller_is_a_versioned_template() -> None:
    assert TEMPLATE.count("__CHILEMON_REPO_REF__") == 1
    assert TEMPLATE.lstrip().startswith("@echo off")


def test_uninstaller_fetches_source_from_the_pinned_repo_ref() -> None:
    assert "github.com/gismodes37/Chilemon" in TEMPLATE
    assert "refs/heads/__CHILEMON_REPO_REF__.zip" in TEMPLATE
    assert TEMPLATE.index("github.com/gismodes37/Chilemon") < TEMPLATE.index(
        "Expand-Archive"
    )


def test_uninstaller_previews_before_asking_and_removing() -> None:
    """The dashboard must never authorize deletion; the PC sees the plan first."""
    preview = TEMPLATE.index("companion.uninstall --dry-run")
    confirm = TEMPLATE.index('set /p "ANSWER=')
    remove = TEMPLATE.index("companion.uninstall --yes")
    assert preview < confirm < remove


def test_uninstaller_reuses_the_tested_module_instead_of_reimplementing() -> None:
    assert "python -m companion.uninstall" in TEMPLATE
    for forbidden in (
        'rmdir /s /q "%USERPROFILE%',
        "schtasks /delete",
        "reg delete",
        "netstat",
        "taskkill",
    ):
        assert forbidden not in TEMPLATE


def test_uninstaller_checks_python_before_network() -> None:
    assert "where python" in TEMPLATE
    assert TEMPLATE.index("where python") < TEMPLATE.index(
        "github.com/gismodes37/Chilemon"
    )


def test_uninstaller_propagates_exit_codes_at_critical_steps() -> None:
    lines = TEMPLATE.splitlines()
    for marker in (
        "where python",
        "DownloadFile(",
        "Expand-Archive",
        "companion.uninstall --dry-run",
        "companion.uninstall --yes",
    ):
        line_no = next(i for i, line in enumerate(lines) if marker in line)
        block = "\n".join(lines[line_no : line_no + 6])
        assert "errorlevel 1" in block or "EXITCODE" in block, marker


def test_uninstaller_cleans_its_own_staging_and_explains_admin() -> None:
    assert 'rd /s /q "%WORK%"' in TEMPLATE
    assert "run this file as Administrator" in TEMPLATE


def test_endpoint_serves_a_bat_without_any_secret() -> None:
    assert "chilemon-companion-uninstall.bat" in ENDPOINT
    assert "uninstaller-template.bat.tpl" in ENDPOINT
    assert "application/toml" not in ENDPOINT
    for forbidden in ("IAX_COMPANION_SECRET", "iax_companion_secret", "password", "config.toml"):
        assert forbidden not in ENDPOINT


def test_endpoint_requires_login_and_sanitizes_repo_ref() -> None:
    assert "Auth::isLoggedIn()" in ENDPOINT
    assert "preg_replace" in ENDPOINT
    assert "CHILEMON_COMPANION_REF" in ENDPOINT


def test_dashboard_exposes_the_tool_from_a_settings_modal() -> None:
    assert 'data-bs-target="#toolsModal"' in DASHBOARD
    assert 'id="toolsModal"' in DASHBOARD
    assert "downloadCompanionUninstaller()" in DASHBOARD
    assert "api/companion-uninstall.php" in DASHBOARD
    assert "--dry-run" in DASHBOARD
