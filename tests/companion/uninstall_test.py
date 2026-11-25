"""Clean companion uninstaller.

The uninstaller removes user data, so the risky decisions must be testable
without touching a real home directory, registry or process table. These tests
use the public module interface with injected filesystem and command runners.

Run with: python -m pytest tests/companion/uninstall_test.py -v
"""

from __future__ import annotations

import shutil
from pathlib import Path

from companion.uninstall import (
    FIREWALL_RULE,
    build_plan,
    confirm_uninstall,
    find_companion_pids,
    main,
)


def _prepare(tmp_path: Path) -> tuple[Path, Path, Path]:
    home = tmp_path / "home"
    temp = tmp_path / "temp"
    app = home / ".chilemon"
    (app / "src").mkdir(parents=True)
    temp.mkdir()
    staging = temp / "chilemon-install-123"
    staging.mkdir()
    (app / "config.toml").write_text("secret = 1\n", encoding="utf-8")
    (app / "companion.log").write_text("log\n", encoding="utf-8")
    (staging / "config.toml").write_text("staged\n", encoding="utf-8")
    return app, temp, staging


def test_build_plan_removes_source_config_and_staging_by_default(tmp_path: Path) -> None:
    app, temp, staging = _prepare(tmp_path)
    plan = build_plan(tmp_path / "home", temp=temp)
    assert plan.app_dir == app
    assert app / "src" in plan.paths
    assert app / "config.toml" in plan.paths
    assert app / "companion.log" in plan.paths
    assert staging in plan.staging_dirs
    assert plan.keep_config is False


def test_build_plan_keep_config_preserves_config_and_logs(tmp_path: Path) -> None:
    app, temp, _ = _prepare(tmp_path)
    plan = build_plan(tmp_path / "home", temp=temp, keep_config=True)
    assert app / "src" in plan.paths
    assert app / "config.toml" not in plan.paths
    assert app / "companion.log" not in plan.paths
    assert plan.keep_config is True


def test_find_companion_pids_ignores_unrelated_port_owner() -> None:
    rows = [
        (101, "python -m http.server 9093"),
        (202, "C:\\Python\\python.exe -m companion.main --log-file x"),
        (303, "python -m companion.uninstall --yes"),
    ]
    assert find_companion_pids(rows) == [202]


def test_confirmation_defaults_to_no() -> None:
    answers = iter([""])
    assert not confirm_uninstall(
        "Remove Companion?", assume_yes=False,
        input_fn=lambda _: next(answers), output=lambda _: None,
    )
    answers = iter(["si"])
    assert confirm_uninstall(
        "Remove Companion?", assume_yes=False,
        input_fn=lambda _: next(answers), output=lambda _: None,
    )
    assert confirm_uninstall(
        "Remove Companion?", assume_yes=True,
        input_fn=lambda _: "no", output=lambda _: None,
    )


def test_dry_run_changes_nothing(tmp_path: Path) -> None:
    app, temp, staging = _prepare(tmp_path)
    out: list[str] = []

    def forbidden(*args: object, **kwargs: object) -> None:
        raise AssertionError("dry-run must not mutate anything")

    code = main(
        ["--dry-run"], home=tmp_path / "home", temp=temp,
        output=out.append, is_admin=lambda: True,
        query_processes=forbidden, stop_process=forbidden,
        run_command=forbidden, delete_path=forbidden,
    )
    assert code == 0
    assert (app / "config.toml").exists()
    assert (app / "src").exists()
    assert staging.exists()
    assert any("Dry run" in line for line in out)


def test_full_clean_removes_data_and_reports_survivors(tmp_path: Path) -> None:
    app, temp, staging = _prepare(tmp_path)
    out: list[str] = []
    commands: list[str] = []
    code = main(
        ["--yes"], home=tmp_path / "home", temp=temp,
        output=out.append, is_admin=lambda: False,
        query_processes=lambda: [], stop_process=lambda pids: None,
        remove_autostart=lambda: ["scheduled task"], verify_autostart=lambda: [],
        run_command=commands.append,
    )
    assert code == 0
    assert not (app / "config.toml").exists()
    assert not (app / "src").exists()
    assert not staging.exists()
    assert any(FIREWALL_RULE in line for line in out)
    assert any("numpy" in line for line in out)
    assert commands == []


def test_keep_config_preserves_config_but_removes_source(tmp_path: Path) -> None:
    app, temp, _ = _prepare(tmp_path)
    out: list[str] = []
    code = main(
        ["--yes", "--keep-config"], home=tmp_path / "home", temp=temp,
        output=out.append, is_admin=lambda: False,
        query_processes=lambda: [], stop_process=lambda pids: None,
        remove_autostart=lambda: [], verify_autostart=lambda: [],
        run_command=lambda cmd: 0,
    )
    assert code == 0
    assert (app / "config.toml").exists()
    assert not (app / "src").exists()


def test_admin_removes_firewall_rule(tmp_path: Path) -> None:
    _prepare(tmp_path)
    commands: list[str] = []
    out: list[str] = []
    main(
        ["--yes"], home=tmp_path / "home", temp=tmp_path / "temp",
        output=out.append, is_admin=lambda: True,
        query_processes=lambda: [], stop_process=lambda pids: None,
        remove_autostart=lambda: [], verify_autostart=lambda: [],
        run_command=lambda cmd: commands.append(cmd) or 0,
    )
    assert any(FIREWALL_RULE in cmd and "Remove-NetFirewallRule" in cmd for cmd in commands)
    assert any(FIREWALL_RULE in line for line in out)


def test_second_run_is_idempotent(tmp_path: Path) -> None:
    """The old uninstaller was safe to run twice; the new one must stay so."""
    home = tmp_path / "home"
    for _ in range(2):
        code = main(
            ["--yes"], home=home, temp=tmp_path / "temp",
            output=lambda _: None, is_admin=lambda: False,
            query_processes=lambda: [], stop_process=lambda pids: None,
            remove_autostart=lambda: [], verify_autostart=lambda: [],
            run_command=lambda cmd: 0,
        )
        assert code == 0


def test_fails_when_an_autostart_entry_survives(tmp_path: Path) -> None:
    code = main(
        ["--yes"], home=tmp_path / "home", temp=tmp_path / "temp",
        output=lambda _: None, is_admin=lambda: False,
        query_processes=lambda: [], stop_process=lambda pids: None,
        remove_autostart=lambda: [], verify_autostart=lambda: ["scheduled task"],
        run_command=lambda cmd: 0,
    )
    assert code == 1


def test_fails_when_a_companion_listener_survives(tmp_path: Path) -> None:
    rows = [(202, "python -m companion.main")]
    code = main(
        ["--yes"], home=tmp_path / "home", temp=tmp_path / "temp",
        output=lambda _: None, is_admin=lambda: False,
        query_processes=lambda: rows, stop_process=lambda pids: None,
        remove_autostart=lambda: [], verify_autostart=lambda: [],
        run_command=lambda cmd: 0,
    )
    assert code == 1


def test_plan_never_targets_repository_sources(tmp_path: Path) -> None:
    repo = Path(__file__).resolve().parents[2]
    plan = build_plan(tmp_path / "home", temp=tmp_path / "temp")
    assert all(not str(path).startswith(str(repo)) for path in plan.paths)


def test_docs_describe_the_less_invasive_options() -> None:
    repo = Path(__file__).resolve().parents[2]
    for name in ("companion.md", "companion_ES.md"):
        text = (repo / "docs" / name).read_text(encoding="utf-8")
        for token in ("--dry-run", "--keep-config", "--yes", FIREWALL_RULE):
            assert token in text, f"{name} is missing {token}"


def test_firewall_cleanup_precedes_data_deletion(tmp_path: Path) -> None:
    """A complete uninstall must not leave the firewall rule behind."""
    _prepare(tmp_path)
    calls: list[str] = []

    def runner(cmd: str) -> int:
        calls.append("firewall")
        return 0

    def deleter(path: Path) -> None:
        calls.append("delete")
        if path.is_dir() and not path.is_symlink():
            shutil.rmtree(path)
        elif path.exists():
            path.unlink()

    code = main(
        ["--yes"], home=tmp_path / "home", temp=tmp_path / "temp",
        output=lambda _: None, is_admin=lambda: True,
        query_processes=lambda: [], stop_process=lambda pids: None,
        remove_autostart=lambda: [], verify_autostart=lambda: [],
        run_command=runner, delete_path=deleter,
    )
    assert code == 0
    assert calls[0] == "firewall"
    assert "delete" in calls


def test_deletion_failure_is_reported_and_fails(tmp_path: Path) -> None:
    """A locked file must not be reported as a complete uninstall."""
    _prepare(tmp_path)
    out: list[str] = []

    def locked(path: Path) -> None:
        raise PermissionError("locked")

    code = main(
        ["--yes"], home=tmp_path / "home", temp=tmp_path / "temp",
        output=out.append, is_admin=lambda: False,
        query_processes=lambda: [], stop_process=lambda pids: None,
        remove_autostart=lambda: [], verify_autostart=lambda: [],
        run_command=lambda cmd: 0, delete_path=locked,
    )
    assert code == 1
    assert any("could not remove" in line for line in out)
    assert not any("Companion fully removed." in line for line in out)


def test_unreadable_listener_command_line_fails_closed(tmp_path: Path) -> None:
    """An unverifiable 9093 owner must not yield a full-success exit."""
    out: list[str] = []
    code = main(
        ["--yes"], home=tmp_path / "home", temp=tmp_path / "temp",
        output=out.append, is_admin=lambda: False,
        query_processes=lambda: [(9, "")], stop_process=lambda pids: None,
        remove_autostart=lambda: [], verify_autostart=lambda: [],
        run_command=lambda cmd: 0,
    )
    assert code == 1
    assert any("could not verify owner" in line for line in out)
