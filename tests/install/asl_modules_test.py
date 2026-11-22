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


def _run(tmp_path: Path, autoload_line: str, module_files: list[str]) -> subprocess.CompletedProcess[str]:
    lines = INSTALLER.read_text(encoding="utf-8", errors="replace").splitlines()
    if lines and lines[-1].strip() == 'main "$@"':
        lines = lines[:-1]
    sourced = tmp_path / "installer_sourced.sh"
    sourced.write_text("\n".join(lines) + "\n", encoding="utf-8", newline="\n")

    modules_conf = tmp_path / "modules.conf"
    modules_conf.write_text(autoload_line + "\n", encoding="utf-8", newline="\n")

    module_dir = tmp_path / "modules"
    module_dir.mkdir(exist_ok=True)
    for name in module_files:
        (module_dir / f"{name}.so").write_bytes(b"")

    script = tmp_path / "harness.sh"
    script.write_text(
        "\n".join(
            [
                "set -Eeuo pipefail",
                f'source "{_posix(sourced)}"',
                "set +e",
                "ok() { :; }",
                'warn() { echo "WARN: $*"; }',
                "info() { :; }",
                "backup_if_exists() { :; }",
                f'ASL_MODULES_CONF="{_posix(modules_conf)}"',
                f'ASTERISK_MODULE_DIR="{_posix(module_dir)}"',
                "configure_asl3_modules",
                'echo "ADDED=${ASL3_MODULES_ADDED}"',
            ]
        )
        + "\n",
        encoding="utf-8",
        newline="\n",
    )
    result = subprocess.run(
        ["bash", _posix(script)],
        cwd=ROOT,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    return result, modules_conf


def _assert_ok(result: subprocess.CompletedProcess[str]) -> None:
    assert result.returncode == 0, result.stdout + result.stderr


def test_new_asl_autoload_with_spaces_is_detected(tmp_path: Path) -> None:
    """Debian 13 ASL3 writes 'autoload = no'; it must not be skipped."""
    result, conf = _run(tmp_path, "autoload = no", [])
    _assert_ok(result)
    assert "ASL3 no detectado" not in result.stdout + result.stderr
    assert "ADDED=0" in result.stdout
    assert not re.search(r"^load\b", conf.read_text(encoding="utf-8"), re.MULTILINE)


def test_old_asl_autoload_without_spaces_still_detected(tmp_path: Path) -> None:
    """Older ASL3 writes 'autoload=no'; existing installs keep working."""
    result, conf = _run(tmp_path, "autoload=no", ["res_http_websocket", "res_srtp"])
    _assert_ok(result)
    assert "ADDED=2" in result.stdout
    rendered = conf.read_text(encoding="utf-8")
    assert "load => res_http_websocket" in rendered
    assert "load => res_srtp" in rendered


def test_existing_load_equals_form_is_not_duplicated(tmp_path: Path) -> None:
    """Both 'load = mod.so' and 'load => mod' mean the module is loaded."""
    result, conf = _run(
        tmp_path,
        "autoload=no\nload    = res_http_websocket.so",
        ["res_http_websocket"],
    )
    _assert_ok(result)
    assert "ADDED=0" in result.stdout
    assert conf.read_text(encoding="utf-8").count("res_http_websocket") == 1


def test_non_asl_asterisk_is_left_untouched(tmp_path: Path) -> None:
    """A plain Asterisk config must not receive ASL-specific load lines."""
    result, conf = _run(tmp_path, "autoload = yes", ["res_http_websocket"])
    _assert_ok(result)
    assert "ASL3 no detectado" in result.stdout + result.stderr
    assert not re.search(r"^load\b", conf.read_text(encoding="utf-8"), re.MULTILINE)
