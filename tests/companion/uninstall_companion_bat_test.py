"""
Contract tests for the Windows companion clean-uninstall script.

There is no batch runner in this suite, so these tests pin the observable
contract encoded in uninstall-companion.bat: it stops the listener, removes
every logon mechanism, deletes only per-user companion data, verifies the port
is free and returns a real exit code.

Run with: python -m pytest tests/companion/uninstall_companion_bat_test.py -v
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
UNINSTALL = (ROOT / "companion" / "uninstall-companion.bat").read_text(
    encoding="utf-8", errors="replace"
)


def test_uninstall_stops_the_companion_listener() -> None:
    assert "netstat -ano" in UNINSTALL
    assert 'findstr ":%PORT%"' in UNINSTALL
    assert "taskkill /PID %%P /F" in UNINSTALL


def test_uninstall_removes_all_logon_mechanisms() -> None:
    assert 'schtasks /delete /tn "%TASK_NAME%" /f' in UNINSTALL
    assert 'reg delete "%RUNKEY%" /v "%TASK_NAME%" /f' in UNINSTALL
    assert "ChileMon Companion.lnk" in UNINSTALL


def test_uninstall_removes_only_per_user_companion_data() -> None:
    assert r"%USERPROFILE%\.chilemon" in UNINSTALL
    for name in (
        "config.toml",
        "companion.log",
        "companion-startup.log",
        "companion-install.log",
    ):
        assert name in UNINSTALL


def test_uninstall_does_not_delete_repository_sources() -> None:
    assert "rmdir /s /q \"%~dp0__pycache__\"" in UNINSTALL
    assert 'del /f /q "%~dp0' not in UNINSTALL
    assert "git clean" not in UNINSTALL


def test_uninstall_verifies_port_and_propagates_failure() -> None:
    assert 'findstr "LISTENING"' in UNINSTALL
    assert "exit /b 1" in UNINSTALL
    assert UNINSTALL.rstrip().endswith("exit /b 0")


def test_uninstall_is_idempotent() -> None:
    """Every removal is guarded, so running it twice is harmless."""
    assert UNINSTALL.count("if exist") >= 5
    assert ">nul 2>&1" in UNINSTALL
