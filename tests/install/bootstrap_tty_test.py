"""
Functional contract tests for the bootstrap's interactive stdin handoff.

`curl ... | sudo bash` feeds the script through a pipe, so fd 0 is exhausted
by the time install_chilemon.sh runs. The installer asks seven questions with
`read`, so bootstrap must hand it the controlling terminal instead of the pipe.

Run with: python -m pytest tests/install/bootstrap_tty_test.py -v
"""

import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BOOTSTRAP = ROOT / "install" / "bootstrap.sh"
ORIGINAL = BOOTSTRAP.read_text(encoding="utf-8", errors="replace")


def _posix(path: Path) -> str:
    value = path.as_posix()
    if re.match(r"^[A-Za-z]:/", value):
        return "/mnt/" + value[0].lower() + value[2:]
    return value


def _harness(tmp_path: Path) -> tuple[Path, Path]:
    """Build a bootstrap copy that installs into tmp_path with stubbed tools."""
    target = tmp_path / "target"
    (target / ".git").mkdir(parents=True)
    (target / "install").mkdir(parents=True)

    root_guard = (
        'if [[ "$EUID" -ne 0 ]]; then\n'
        '    echo "[ERROR] Ejecute este script con sudo o como root."\n'
        "    exit 1\n"
        "fi"
    )
    body = (
        ORIGINAL.replace(
            'TARGET_DIR="/opt/chilemon"', f'TARGET_DIR="{_posix(target)}"'
        )
        .replace(
            'REPO_URL="https://github.com/gismodes37/chilemon.git"',
            'REPO_URL="https://example.invalid/chilemon.git"',
        )
        .replace(root_guard, "# root guard stubbed out by the test harness")
        .replace("apt-get update", ":  # apt-get stubbed")
        .replace("apt-get install -y git", ":  # apt-get stubbed")
        .replace('git -C "$TARGET_DIR" pull --ff-only', ":  # git stubbed")
        .replace('git clone "$REPO_URL" "$TARGET_DIR"', ":  # git stubbed")
    )
    assert f'TARGET_DIR="{_posix(target)}"' in body, "bootstrap TARGET_DIR changed"
    assert "example.invalid" in body, "bootstrap REPO_URL changed"
    assert root_guard not in body, "bootstrap root guard changed"

    bootstrap = tmp_path / "bootstrap.sh"
    bootstrap.write_text(body, encoding="utf-8", newline="\n")

    capture = tmp_path / "captured.txt"
    captured_posix = _posix(capture)
    (target / "install" / "install_chilemon.sh").write_text(
        "\n".join(
            [
                "#!/usr/bin/env bash",
                "set -Eeuo pipefail",
                f'if [[ -t 0 ]]; then echo tty > "{captured_posix}"; else echo notty > "{captured_posix}"; fi',
                f'readlink /proc/self/fd/0 >> "{captured_posix}"',
            ]
        )
        + "\n",
        encoding="utf-8",
        newline="\n",
    )

    return bootstrap, capture


def _run_piped(bootstrap: Path) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["bash", _posix(bootstrap)],
        cwd=ROOT,
        input="",  # stdin is a pipe at EOF, exactly like `curl ... | sudo bash`
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )


def test_piped_bootstrap_hands_the_terminal_to_the_installer(tmp_path: Path) -> None:
    """The installer must not inherit the exhausted curl pipe as stdin."""
    bootstrap, capture = _harness(tmp_path)
    result = _run_piped(bootstrap)
    assert result.returncode == 0, result.stdout + result.stderr
    observed = capture.read_text(encoding="utf-8").strip().splitlines()
    assert observed[0] == "tty", f"installer saw non-TTY stdin: {observed}"
    assert "pipe:" not in observed[1], f"installer still has the pipe: {observed}"


def test_bootstrap_keeps_the_direct_stdin_path() -> None:
    """Running `sudo bash /tmp/bootstrap.sh` must keep its own stdin."""
    assert "[[ -t 0 ]]" in ORIGINAL


def test_bootstrap_has_an_actionable_no_tty_fallback() -> None:
    """No controlling terminal must fail with a usable instruction, not a trap."""
    assert "</dev/tty" in ORIGINAL
    assert "sudo bash /tmp/bootstrap.sh" in ORIGINAL
