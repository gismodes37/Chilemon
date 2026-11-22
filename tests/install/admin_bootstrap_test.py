import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
INSTALLER = ROOT / "install" / "install_chilemon.sh"
PHP_INSTALLER = ROOT / "bin" / "install.php"


def _posix(path: Path) -> str:
    value = path.as_posix()
    if re.match(r"^[A-Za-z]:/", value):
        return "/mnt/" + value[0].lower() + value[2:]
    return value


def _run_shell(tmp_path: Path, body: str) -> subprocess.CompletedProcess[str]:
    lines = INSTALLER.read_text(encoding="utf-8", errors="replace").splitlines()
    if lines and lines[-1].strip() == 'main "$@"':
        lines = lines[:-1]
    sourced = tmp_path / "installer_sourced.sh"
    sourced.write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")

    script = tmp_path / "harness.sh"
    script.write_text(
        "\n".join(
            [
                "set -Eeuo pipefail",
                f'source "{_posix(sourced)}"',
                "set +e",
                "ok() { :; }",
                "warn() { :; }",
                "info() { :; }",
                "step() { :; }",
                "php() { env | grep '^CHILEMON_ADMIN_' | sort > \"$CAPTURE\"; }",
                f'CAPTURE="{_posix(tmp_path / "captured-env.txt")}"',
                f'INSTALLER_PHP="{_posix(tmp_path / "install.php")}"',
                'touch "$INSTALLER_PHP"',
                body,
            ]
        )
        + "\n",
        encoding="utf-8",
        newline="\n",
    )
    return subprocess.run(
        ["bash", _posix(script)],
        cwd=ROOT,
        stdin=subprocess.DEVNULL,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )


def test_non_interactive_install_generates_admin_credentials(tmp_path: Path) -> None:
    """A one-command install has no TTY, so it must create a usable admin."""
    result = _run_shell(tmp_path, 'run_php_user_creation')
    assert result.returncode == 0, result.stdout + result.stderr

    captured = (tmp_path / "captured-env.txt").read_text(encoding="utf-8")
    assert "CHILEMON_ADMIN_USER=admin" in captured
    match = re.search(r"^CHILEMON_ADMIN_PASS=(.+)$", captured, re.MULTILINE)
    assert match is not None, captured
    assert len(match.group(1)) >= 20, "generated password must be strong"


def test_php_installer_supports_explicit_admin_credentials() -> None:
    """install.php must create the first admin without a TTY when told to."""
    source = PHP_INSTALLER.read_text(encoding="utf-8", errors="replace")
    assert "CHILEMON_ADMIN_USER" in source
    assert "CHILEMON_ADMIN_PASS" in source
    assert "getenv('CHILEMON_ADMIN_USER')" in source
    assert "getenv('CHILEMON_ADMIN_PASS')" in source


def test_final_banner_reports_real_web_credentials() -> None:
    """The old fixed 'definido durante instalación' text misled users."""
    source = INSTALLER.read_text(encoding="utf-8", errors="replace")
    assert "definido durante instalación" not in source
    assert "Contraseña web" in source
    assert "WEB_ADMIN_PASS" in source


def test_clear_screen_is_silent_with_unknown_term(tmp_path: Path) -> None:
    """One-command installs must not print an ncurses TERM error."""
    result = _run_shell(tmp_path, "TERM=unknown clear_screen")
    assert result.returncode == 0, result.stdout + result.stderr
    assert "I need something more specific" not in result.stdout + result.stderr


def test_final_banner_health_command_expands_webrtc_port(tmp_path: Path) -> None:
    """The printed health check must show a real port under set -u."""
    result = _run_shell(
        tmp_path,
        'print_final_banner "494780" "node494780" "admin" "admin" "http"',
    )
    assert result.returncode == 0, result.stdout + result.stderr
    assert "curl http://localhost:9091/health" in result.stdout
    assert "${WEBRTC_PORT" not in result.stdout
