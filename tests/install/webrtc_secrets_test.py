import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
INSTALLER = ROOT / "install" / "install_chilemon.sh"


def _posix(path: Path) -> str:
    """Translate a Windows path for the WSL bash used by the test runner."""
    value = path.as_posix()
    if re.match(r"^[A-Za-z]:/", value):
        return "/mnt/" + value[0].lower() + value[2:]
    return value


def _run(tmp_path: Path, body: str) -> None:
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
                "chown() { :; }",
                "php() { printf ''; }",
                f'TMP="{_posix(tmp_path)}"',
                f'BACKUP_DIR="{_posix(tmp_path)}/backups"',
                f'INSTALL_IAX_SNIPPET="{_posix(ROOT / "install" / "asterisk" / "iax.conf")}"',
                "mkdir -p \"$BACKUP_DIR\" \"$TMP/config\"",
                body,
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
    assert result.returncode == 0, result.stdout + result.stderr


def _config_value(text: str, key: str) -> str | None:
    match = re.search(rf"'{key}'\s*=>\s*'([^']*)'", text)
    return match.group(1) if match else None


def test_fresh_install_generates_webrtc_secrets(tmp_path: Path) -> None:
    """A fresh Pi must get non-placeholder secrets so the dashboard can load."""
    _run(
        tmp_path,
        "\n".join(
            [
                'LOCAL_CONFIG="$TMP/config/local.php"',
                'write_local_config "12345" "example.local" "Tagline" '
                '"127.0.0.1" "5038" "admin" "ami-pass" "3" ""',
            ]
        ),
    )
    config = (tmp_path / "config" / "local.php").read_text(encoding="utf-8")
    phone = _config_value(config, "iax_phone_pass")
    hmac = _config_value(config, "webrtc_secret")
    assert phone and re.fullmatch(r"[0-9a-f]{48}", phone), phone
    assert hmac and re.fullmatch(r"[0-9a-f]{48}", hmac), hmac
    assert phone != hmac
    assert _config_value(config, "iax_phone_user") == "webrtc-bridge"


def test_existing_secrets_are_reused_on_rerun(tmp_path: Path) -> None:
    """Re-running the installer must not rotate secrets used by the companion."""
    config_path = tmp_path / "config" / "local.php"
    common = (
        'LOCAL_CONFIG="$TMP/config/local.php"\n'
        'write_local_config "12345" "example.local" "Tagline" '
        '"127.0.0.1" "5038" "admin" "ami-pass" "3" ""\n'
    )
    _run(tmp_path, common)
    first = config_path.read_text(encoding="utf-8")
    first_phone = _config_value(first, "iax_phone_pass")
    first_hmac = _config_value(first, "webrtc_secret")
    assert first_phone and first_hmac
    _run(tmp_path, common)
    second = config_path.read_text(encoding="utf-8")
    assert _config_value(second, "iax_phone_pass") == first_phone
    assert _config_value(second, "webrtc_secret") == first_hmac
    assert _config_value(second, "webrtc_secret") == _config_value(first, "webrtc_secret")


def test_iax_bridge_snippet_uses_generated_secret(tmp_path: Path) -> None:
    """The IAX2 peer must match config/local.php without manual editing."""
    iax_conf = tmp_path / "iax.conf"
    iax_conf.write_text("[general]\nrequirecalltoken=no\n", encoding="utf-8")
    _run(
        tmp_path,
        "\n".join(
            [
                'ASTERISK_IAX_CONF="$TMP/iax.conf"',
                'IAX_PHONE_SECRET_VAL="deadbeefcafe"',
                "install_iax_bridge_snippet",
            ]
        ),
    )
    rendered = iax_conf.read_text(encoding="utf-8")
    assert "secret=deadbeefcafe" in rendered
    assert "CHANGEME_PHONE_SECRET" not in rendered
    assert "[webrtc-bridge]" in rendered


def test_webrtc_env_file_uses_generated_secrets_and_prompted_node(tmp_path: Path) -> None:
    """Step D must write real values, and an existing placeholder must be fixed."""
    env_file = tmp_path / "chilemon-webrtc"
    env_file.write_text(
        "IAX_PHONE_PASS=CHANGE_ME\nWEBRTC_SECRET=CHANGE_ME\nASL_NODE=494780\n",
        encoding="utf-8",
    )
    _run(
        tmp_path,
        "\n".join(
            [
                'DEFAULT_ENV_FILE="$TMP/chilemon-webrtc"',
                'IAX_PHONE_SECRET_VAL="phone-secret"',
                'WEBRTC_SECRET_VAL="hmac-secret"',
                'write_webrtc_env_file "123456"',
            ]
        ),
    )
    rendered = env_file.read_text(encoding="utf-8")
    assert "IAX_PHONE_PASS=phone-secret" in rendered
    assert "WEBRTC_SECRET=hmac-secret" in rendered
    assert "ASL_NODE=123456" in rendered
    assert "CHANGE_ME" not in rendered


def test_webrtc_env_file_carries_ami_credentials(tmp_path: Path) -> None:
    """The bridge requires AMI_PASS; a fresh install must write it."""
    config = tmp_path / "config" / "local.php"
    config.parent.mkdir(parents=True, exist_ok=True)
    config.write_text(
        "<?php return ['ami_host' => '127.0.0.1', 'ami_port' => '5038', "
        "'ami_user' => 'admin', 'ami_pass' => 'ami-secret'];\n",
        encoding="utf-8",
    )
    env_file = tmp_path / "chilemon-webrtc"
    _run(
        tmp_path,
        "\n".join(
            [
                f'LOCAL_CONFIG="{_posix(config)}"',
                f'DEFAULT_ENV_FILE="{_posix(env_file)}"',
                'IAX_PHONE_SECRET_VAL="phone-secret"',
                'WEBRTC_SECRET_VAL="hmac-secret"',
                'write_webrtc_env_file "494780"',
            ]
        ),
    )
    rendered = env_file.read_text(encoding="utf-8")
    assert 'AMI_PASS="ami-secret"' in rendered
    assert "AMI_HOST=127.0.0.1" in rendered
    assert "AMI_PORT=5038" in rendered
    assert "AMI_USER=admin" in rendered


def test_webrtc_env_sync_updates_ami_password_safely(tmp_path: Path) -> None:
    """Reruns must replace AMI_PASS without breaking sed on special chars."""
    config = tmp_path / "config" / "local.php"
    config.parent.mkdir(parents=True, exist_ok=True)
    config.write_text(
        "<?php return ['ami_pass' => 'p&ss|word$'];\n",
        encoding="utf-8",
    )
    env_file = tmp_path / "chilemon-webrtc"
    env_file.write_text(
        'AMI_PASS="OLD"\nIAX_PHONE_PASS=OLD\nWEBRTC_SECRET=OLD\nASL_NODE=494780\n',
        encoding="utf-8",
    )
    _run(
        tmp_path,
        "\n".join(
            [
                f'LOCAL_CONFIG="{_posix(config)}"',
                f'DEFAULT_ENV_FILE="{_posix(env_file)}"',
                'IAX_PHONE_SECRET_VAL="phone-secret"',
                'WEBRTC_SECRET_VAL="hmac-secret"',
                'write_webrtc_env_file "494780"',
            ]
        ),
    )
    rendered = env_file.read_text(encoding="utf-8")
    assert 'AMI_PASS="p&ss|word\\$"' in rendered
    assert "OLD" not in rendered


def test_installer_handles_debian_13_python_dependencies() -> None:
    """PEP 668 and Python 3.13 need explicit pip flags and audioop-lts."""
    installer = INSTALLER.read_text(encoding="utf-8", errors="replace")
    assert "--ignore-installed" in installer
    assert "audioop-lts" in installer
    assert "import audioop_lts" in installer
