"""
Contract tests for the Windows companion autostart scripts.

There is no batch-file test runner in this repository, so these tests pin
the observable contract encoded in the .bat files: idempotent task creation,
absolute launcher resolution, real failure signalling, and preservation of
the existing "already running" behaviour.

Run with: python -m pytest tests/companion/autostart_test.py -v
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
START = (ROOT / "companion" / "start-companion.bat").read_text(
    encoding="utf-8", errors="replace"
)
INSTALL = (ROOT / "companion" / "install-autostart.bat").read_text(
    encoding="utf-8", errors="replace"
)


def test_installer_checks_existing_task_before_creating() -> None:
    """S1: the task is looked up first, so running the routine twice is safe."""
    assert "schtasks /query" in INSTALL
    assert "schtasks /create" in INSTALL
    assert "/sc onlogon" in INSTALL


def test_installer_uses_absolute_launcher_path() -> None:
    """S1: the task must work regardless of the machine's working directory."""
    assert "%~dp0" in INSTALL
    assert "%START_BAT%" in INSTALL


def test_installer_falls_back_to_user_run_key_without_admin() -> None:
    """S1: a standard user still gets autostart when schtasks is denied."""
    assert 'HKCU\\Software\\Microsoft\\Windows\\CurrentVersion\\Run' in INSTALL
    assert "reg query" in INSTALL
    assert "reg add" in INSTALL


def test_installer_signals_failure() -> None:
    """S3: a failed registration must not be reported as success."""
    assert "if errorlevel 1" in INSTALL
    assert "exit /b 1" in INSTALL


def test_launcher_installs_task_when_run_directly() -> None:
    """S1: a first manual run creates the task for future logons."""
    assert "install-autostart.bat" in START
    assert "--autostart" in START


def test_launcher_resolves_python_to_absolute_path() -> None:
    """S1: the scheduled task must not depend on an interactive PATH."""
    assert "where python" in START
    assert '"%PYTHON%" -m companion.main' in START


def test_launcher_logs_startup_failures() -> None:
    """S3: errors before logging is configured go to a bootstrap log."""
    assert "companion-startup.log" in START
    assert "2>>" in START


def test_launcher_verifies_listener_and_returns_real_exit_code() -> None:
    """S2/S3: a spawned process that never opens 9093 is a failure."""
    assert ":9093" in START
    assert "LISTENING" in START
    assert "exit /b 1" in START


def test_autostart_flag_never_reaches_python_cli() -> None:
    """Existing contract: --autostart only changes batch behaviour."""
    assert '"%PYTHON%" -m companion.main --log-file' in START
    assert "--autostart --log-file" not in START


def test_already_running_shortcut_behaviour_is_preserved() -> None:
    """Existing contract: a live listener exits quietly under --autostart."""
    quiet = 'if /i "%~1"=="--autostart" exit /b 0'
    assert quiet in START
    pause = START.find("pause >nul")
    assert pause == -1 or pause > START.index(quiet)
