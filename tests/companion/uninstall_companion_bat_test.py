"""
Contract tests for the Windows companion clean-uninstall wrapper.

There is no batch runner in this suite, so these tests pin the observable
contract encoded in uninstall-companion.bat: it is a thin, flag-forwarding
wrapper with no destructive logic of its own. The destructive logic lives in
companion/uninstall.py, where it is verified with injected dependencies.

Run with: python -m pytest tests/companion/uninstall_companion_bat_test.py -v
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
UNINSTALL = (ROOT / "companion" / "uninstall-companion.bat").read_text(
    encoding="utf-8", errors="replace"
)


def test_uninstall_delegates_to_the_python_module() -> None:
    assert "python -m companion.uninstall %*" in UNINSTALL


def test_uninstall_forwards_every_flag_unchanged() -> None:
    """%* keeps --dry-run, --keep-config and --yes available to the user."""
    for flag in ("--dry-run", "--keep-config", "--yes"):
        assert flag in UNINSTALL
    assert "%*" in UNINSTALL


def test_uninstall_runs_from_the_repository_root() -> None:
    assert 'cd /d "%~dp0.."' in UNINSTALL


def test_uninstall_propagates_the_exit_code() -> None:
    assert "exit /b %ERRORLEVEL%" in UNINSTALL


def test_uninstall_has_no_destructive_logic_of_its_own() -> None:
    for forbidden in (
        "taskkill",
        "reg delete",
        "rmdir /s",
        "schtasks /delete",
        "netstat",
        "del /f",
    ):
        assert forbidden not in UNINSTALL


def test_uninstall_checks_python_before_delegating() -> None:
    assert "where python" in UNINSTALL
