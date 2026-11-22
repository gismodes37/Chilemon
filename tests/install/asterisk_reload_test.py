import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
INSTALLER = ROOT / "install" / "install_chilemon.sh"


def _posix(path: Path) -> str:
    value = path.as_posix()
    if re.match(r"^[A-Za-z]:/", value):
        return "/mnt/" + value[0].lower() + value[2:]
    return value


def _run_apply(tmp_path: Path, restart_needed: str) -> subprocess.CompletedProcess[str]:
    lines = INSTALLER.read_text(encoding="utf-8", errors="replace").splitlines()
    if lines and lines[-1].strip() == 'main "$@"':
        lines = lines[:-1]
    sourced = tmp_path / "installer_sourced.sh"
    sourced.write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")

    harness = tmp_path / "harness.sh"
    harness.write_text(
        "\n".join(
            [
                "set -Eeuo pipefail",
                f'source "{_posix(sourced)}"',
                "set +e",
                "ok() { echo \"OK: $*\"; }",
                "warn() { echo \"WARN: $*\"; }",
                "info() { echo \"INFO: $*\"; }",
                "sleep() { :; }",
                "systemctl() { echo \"SYSTEMCTL $*\"; return 0; }",
                f'ASTERISK_RESTART_NEEDED="{restart_needed}"',
                "apply_asterisk_config",
            ]
        )
        + "\n",
        encoding="utf-8",
        newline="\n",
    )
    return subprocess.run(
        ["bash", _posix(harness)],
        cwd=ROOT,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )


def test_asterisk_is_restarted_when_config_changed(tmp_path: Path) -> None:
    """A changed iax.conf/extensions.conf must be loaded, not left pending."""
    result = _run_apply(tmp_path, "1")
    assert result.returncode == 0, result.stdout + result.stderr
    assert "SYSTEMCTL restart asterisk" in result.stdout
    assert "SYSTEMCTL is-active --quiet asterisk" in result.stdout


def test_asterisk_is_not_restarted_when_config_unchanged(tmp_path: Path) -> None:
    """Update mode must stay idempotent: no config change, no service restart."""
    result = _run_apply(tmp_path, "0")
    assert result.returncode == 0, result.stdout + result.stderr
    assert "SYSTEMCTL restart asterisk" not in result.stdout


def test_main_applies_asterisk_config_after_step_8() -> None:
    """The restart must happen after all Asterisk config files are written."""
    source = INSTALLER.read_text(encoding="utf-8", errors="replace")
    assert re.search(
        r'step "8"[^\n]*\n\s*configure_webrtc_asterisk[^\n]*\n\s*apply_asterisk_config',
        source,
    )
