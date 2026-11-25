"""Independent spec probes for the clean companion uninstaller (S1-S6).

These complement uninstall_test.py with byte-level immutability checks on the
non-destructive paths and marker-matching edge cases. All destructive hooks are
injected; no real home, registry, process table or firewall is touched.

Run with: python -m pytest tests/companion/uninstall_probe_test.py -v
"""

from __future__ import annotations

import hashlib
from pathlib import Path

import pytest

import companion.uninstall as u


def _seed(root: Path) -> tuple[Path, Path, Path, Path, Path]:
    home = root / "home"
    temp = root / "temp"
    app = home / ".chilemon"
    (app / "src" / "__pycache__").mkdir(parents=True, exist_ok=True)
    (app / "src" / "__pycache__" / "x.pyc").write_bytes(b"bc")
    for name in (
        "config.toml",
        "companion.log",
        "companion-startup.log",
        "companion-install.log",
    ):
        (app / name).write_text("data-" + name, encoding="utf-8")
    temp.mkdir(parents=True, exist_ok=True)
    staging = temp / "chilemon-install-999"
    staging.mkdir(exist_ok=True)
    (staging / "seed").write_text("s", encoding="utf-8")
    downloads = home / "Downloads"
    downloads.mkdir(exist_ok=True)
    bat = downloads / "chilemon-companion-install.bat"
    bat.write_text("x", encoding="utf-8")
    return home, temp, app, staging, bat


def _hash(path: Path) -> str:
    digest = hashlib.sha256()
    files = sorted(p for p in path.rglob("*") if p.is_file()) if path.is_dir() else [path]
    for item in files:
        digest.update(str(item.relative_to(path)).encode())
        digest.update(item.read_bytes())
    return digest.hexdigest()


def _no(*args: object, **kwargs: object) -> None:
    return None


def _boom(*args: object, **kwargs: object) -> None:
    raise AssertionError("destructive call in a non-destructive path")


def _main(root: Path, argv: list[str], **overrides: object) -> tuple[int, list[str], Path, Path, Path]:
    home, temp, app, staging, bat = _seed(root)
    out: list[str] = []
    hooks: dict[str, object] = {
        "output": out.append,
        "input_fn": lambda _: "",
        "query_processes": lambda: [],
        "stop_process": _no,
        "remove_autostart": lambda: [],
        "verify_autostart": lambda: [],
        "run_command": lambda cmd: 0,
        "is_admin": lambda: False,
    }
    hooks.update(overrides)
    code = u.main(argv, home=home, temp=temp, **hooks)  # type: ignore[arg-type]
    return code, out, app, staging, bat


def test_dry_run_is_byte_identical(tmp_path: Path) -> None:  # S1, S2
    home, temp, app, staging, bat = _seed(tmp_path / "d")
    before = [_hash(p) for p in (app, staging, bat)]
    out: list[str] = []
    code = u.main(
        ["--dry-run", "--yes"], home=home, temp=temp, output=out.append,
        query_processes=_boom, stop_process=_boom, remove_autostart=_boom,
        verify_autostart=_boom, run_command=_boom, delete_path=_boom,
        is_admin=lambda: True,
    )
    assert code == 0
    assert before == [_hash(p) for p in (app, staging, bat)]
    assert any("Dry run" in line for line in out)


@pytest.mark.parametrize("answer", ["", "n", "no", "maybe", "YEP"])
def test_decline_defaults_to_no_and_is_immutable(tmp_path: Path, answer: str) -> None:  # S2
    home, temp, app, staging, bat = _seed(tmp_path / answer)
    before = [_hash(p) for p in (app, staging, bat)]
    out: list[str] = []
    code = u.main(
        [], home=home, temp=temp, output=out.append, input_fn=lambda _: answer,
        query_processes=_boom, stop_process=_boom, remove_autostart=_boom,
        verify_autostart=_boom, run_command=_boom, delete_path=_boom,
        is_admin=lambda: False,
    )
    assert code == 1
    assert before == [_hash(p) for p in (app, staging, bat)]
    assert "Cancelled: nothing was removed." in out


def test_yes_skips_prompt(tmp_path: Path) -> None:  # S2
    code, _, _, _, _ = _main(tmp_path, ["--yes"], input_fn=_boom)
    assert code == 0


def test_keep_config_is_byte_identical(tmp_path: Path) -> None:  # S2
    root = tmp_path / "k"
    home, temp, app, _, bat = _seed(root)
    kept = [app / name for name in (
        "config.toml", "companion.log", "companion-startup.log", "companion-install.log"
    )]
    before = [_hash(p) for p in kept]
    out: list[str] = []
    code = u.main(
        ["--yes", "--keep-config"], home=home, temp=temp, output=out.append,
        query_processes=lambda: [], stop_process=_no, remove_autostart=lambda: [],
        verify_autostart=lambda: [], run_command=lambda cmd: 0, is_admin=lambda: False,
    )
    assert code == 0
    assert [p.exists() for p in kept] == [True] * 4
    assert before == [_hash(p) for p in kept]
    assert not (app / "src").exists()
    assert bat.exists()


def test_full_clean_reports_and_keeps_bat(tmp_path: Path) -> None:  # S1, S2
    code, out, app, staging, bat = _main(tmp_path, ["--yes"])
    assert code == 0
    assert not app.exists() and not staging.exists() and bat.exists()
    assert any("numpy" in line for line in out)
    assert any(".bat" in line for line in out)


@pytest.mark.parametrize("rows,expected", [
    ([(1, "python -m http.server 9093"), (2, "C:\\py.exe -m companion.main --x")], [2]),
    ([(9, "PYTHON -M COMPANION.MAIN")], [9]),
    ([(9, "python -m companion.main.exe")], []),
])
def test_marker_matching(
    rows: list[tuple[int, str]], expected: list[int]
) -> None:  # S3
    assert u.find_companion_pids(rows) == expected


def test_exit_codes_for_unrelated_vs_companion_listener(tmp_path: Path) -> None:  # S1, S3
    code, out, _, _, _ = _main(
        tmp_path / "u", ["--yes"],
        query_processes=lambda: [(501, "python -m http.server 9093")],
    )
    assert code == 0
    assert any("not the Companion, left running" in line for line in out)

    code, _, _, _, _ = _main(
        tmp_path / "c", ["--yes"],
        query_processes=lambda: [(7, "python -m companion.main")],
    )
    assert code == 1


def test_non_admin_prints_exact_command_and_runs_nothing(tmp_path: Path) -> None:  # S4
    code, out, _, _, _ = _main(tmp_path, ["--yes"], run_command=_boom, is_admin=lambda: False)
    assert code == 0
    assert any(u.firewall_command() in line and "administrator" in line.lower() for line in out)


def test_admin_invokes_remove(tmp_path: Path) -> None:  # S4
    commands: list[str] = []
    code, _, _, _, _ = _main(
        tmp_path, ["--yes"],
        run_command=lambda cmd: commands.append(cmd) or 0,
        is_admin=lambda: True,
    )
    assert code == 0
    assert any("-Remove" in cmd and "install-firewall.ps1" in cmd for cmd in commands)


def test_docs_and_wrapper_contract() -> None:  # S5, S6
    root = Path(__file__).resolve().parents[2]
    for name in ("companion.md", "companion_ES.md"):
        text = (root / "docs" / name).read_text(encoding="utf-8")
        assert all(token in text for token in ("--dry-run", "--keep-config", "--yes", u.FIREWALL_RULE))
    wrapper = (root / "companion" / "uninstall-companion.bat").read_text(
        encoding="utf-8", errors="replace"
    )
    assert "python -m companion.uninstall %*" in wrapper
    assert "exit /b %ERRORLEVEL%" in wrapper
    assert not any(
        token in wrapper.lower()
        for token in ("taskkill", "reg delete", "rmdir /s", "schtasks /delete", "del /f", "netstat")
    )
