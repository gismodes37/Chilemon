"""
Contract tests for the one-action Windows companion installer.

There is no batch-file runner here, so these tests pin the observable contract
encoded in install-companion.bat: it delegates config placement to the tested
Python module, reuses the idempotent autostart/start scripts, propagates real
exit codes and keeps a compact install log.

Run with: python -m pytest tests/companion/install_companion_bat_test.py -v
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
INSTALL = (ROOT / "companion" / "install-companion.bat").read_text(
    encoding="utf-8", errors="replace"
)


def test_installer_checks_python_before_any_work() -> None:
    """A missing interpreter must fail before config or autostart changes."""
    assert "where python" in INSTALL
    assert INSTALL.index("where python") < INSTALL.index(
        "python -m companion.install_config"
    )


def test_installer_delegates_config_to_tested_module() -> None:
    """Config discovery/validation lives in install_config, not in batch."""
    assert "python -m companion.install_config" in INSTALL


def test_installer_passes_explicit_config_when_supplied() -> None:
    assert "--config %~1" in INSTALL


def test_installer_reuses_idempotent_autostart_script() -> None:
    """It must not reimplement task/Run-key registration."""
    assert "call \"%~dp0install-autostart.bat\"" in INSTALL
    assert "schtasks /create" not in INSTALL
    assert "reg add" not in INSTALL


def test_installer_reuses_start_launcher() -> None:
    assert "call \"%~dp0start-companion.bat\"" in INSTALL
    assert "python -m companion.main" not in INSTALL


def test_installer_propagates_real_exit_codes() -> None:
    assert INSTALL.count("if errorlevel 1") >= 3
    assert "exit /b 1" in INSTALL
    assert INSTALL.rstrip().endswith("exit /b 0")


def test_installer_supports_config_only() -> None:
    """A config-only seam keeps setup safe to run without starting a service."""
    assert "--config-only" in INSTALL
    assert 'set "CONFIG_ONLY=1"' in INSTALL
    quiet = INSTALL.index('if "%CONFIG_ONLY%"=="1"')
    assert quiet < INSTALL.index("install-autostart.bat")


def test_installer_logs_each_step() -> None:
    assert "companion-install.log" in INSTALL
    assert ">>\"%LOG%\"" in INSTALL
