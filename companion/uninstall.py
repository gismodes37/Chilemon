#!/usr/bin/env python3
"""Clean, reversible uninstall for the ChileMon Companion App.

The default mode removes the per-user application directory (config, logs,
downloaded source and bytecode), the logon autostart entries, stale installer
staging directories and, when running as administrator, the Windows firewall
rule. Shared Python packages and the downloaded ``.bat`` are reported but never
removed automatically: another application may use them.

Two less invasive alternatives are first-class flags:

* ``--dry-run`` prints the exact plan and changes nothing;
* ``--keep-config`` keeps ``config.toml`` and the logs, removing only code and
  autostart (useful for a reinstall that preserves the paired secret).

The default asks for confirmation; ``--yes`` skips it for automation.

All Windows-specific lookups are injected, so the module and its tests run on
any platform. In particular a process is never stopped only because it listens
on port 9093: its command line must contain the ``-m companion.main`` marker.

Usage:
    python -m companion.uninstall --dry-run
    python -m companion.uninstall --keep-config
    python -m companion.uninstall --yes
"""

from __future__ import annotations

import argparse
import os
import re
import shutil
import subprocess
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable, Iterable, Sequence

FIREWALL_RULE = "ChileMon Companion IAX2"
TASK_NAME = "ChileMon Companion"
COMPANION_PORT = 9093
APP_DIR_NAME = ".chilemon"
STAGING_PREFIX = "chilemon-install-"
PROCESS_MARKER = re.compile(r"(?:^|\s)-m\s+companion\.main(?:\s|$)", re.IGNORECASE)
LOG_NAMES = (
    "companion.log",
    "companion-startup.log",
    "companion-install.log",
)

Output = Callable[[str], None]
Runner = Callable[[str], int]
Deleter = Callable[[Path], None]
ProcessQuery = Callable[[], Iterable[tuple[int, str]]]
ProcessStopper = Callable[[Sequence[int]], None]
AutostartRemover = Callable[[], list[str]]
AutostartVerifier = Callable[[], list[str]]


@dataclass
class UninstallPlan:
    """Everything the uninstaller intends to remove."""

    app_dir: Path
    paths: set[Path] = field(default_factory=set)
    staging_dirs: list[Path] = field(default_factory=list)
    keep_config: bool = False

    def describe(self) -> list[str]:
        lines = [f"Per-user app directory: {self.app_dir}"]
        for path in sorted(self.paths, key=str):
            lines.append(f"  remove  {path}")
        for path in self.staging_dirs:
            lines.append(f"  remove  {path}")
        lines.append(f"  autostart  task '{TASK_NAME}', HKCU Run value and Startup shortcut")
        lines.append(f"  firewall   rule '{FIREWALL_RULE}' (administrator only)")
        if self.keep_config:
            lines.append("  keep     config.toml and logs (--keep-config)")
        return lines


def build_plan(home: Path, *, temp: Path | None = None, keep_config: bool = False) -> UninstallPlan:
    """Collect the paths owned by the Companion under ``home`` and ``temp``."""
    app_dir = Path(home) / APP_DIR_NAME
    plan = UninstallPlan(app_dir=app_dir, keep_config=keep_config)

    if app_dir.is_dir():
        protected = {"config.toml", *LOG_NAMES} if keep_config else set()
        for child in sorted(app_dir.iterdir()):
            if child.name not in protected:
                plan.paths.add(child)
        if not keep_config:
            plan.paths.add(app_dir)

    if temp is not None:
        temp_dir = Path(temp)
        if temp_dir.is_dir():
            plan.staging_dirs = sorted(
                (path for path in temp_dir.glob(f"{STAGING_PREFIX}*") if path.is_dir()),
                key=str,
            )
    return plan


def find_companion_pids(rows: Iterable[tuple[int, str]]) -> list[int]:
    """Return PIDs whose command line actually runs the Companion module."""
    pids: list[int] = []
    for pid, command_line in rows:
        if PROCESS_MARKER.search(str(command_line or "")):
            pids.append(int(pid))
    return pids


def confirm_uninstall(
    prompt: str,
    *,
    assume_yes: bool,
    input_fn: Callable[[str], str] | None = None,
    output: Output | None = None,
) -> bool:
    """Default to 'no'; only an explicit affirmative answer authorizes removal."""
    say = output or print
    if assume_yes:
        return True
    answer = (input_fn or input)(prompt).strip().lower()
    if answer in {"y", "yes", "s", "si", "sí"}:
        return True
    say("Cancelled: nothing was removed.")
    return False


def _default_delete_path(path: Path) -> None:
    if path.is_dir() and not path.is_symlink():
        shutil.rmtree(path)
    elif path.exists() or path.is_symlink():
        path.unlink()


def _default_is_admin() -> bool:
    if os.name != "nt":
        return False
    try:
        import ctypes

        return bool(ctypes.windll.shell32.IsUserAnAdmin())
    except (AttributeError, OSError):
        return False


def _powershell(command: str, *, capture: bool = True) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["powershell", "-NoProfile", "-Command", command],
        capture_output=capture,
        text=True,
        check=False,
    )


def _default_query_processes() -> Iterable[tuple[int, str]]:
    """Find 9093 listeners and their command lines; unknown owners are reported."""
    if os.name != "nt":
        return []
    result = subprocess.run(
        ["netstat", "-ano"], capture_output=True, text=True, check=False
    )
    pids: set[int] = set()
    for line in result.stdout.splitlines():
        parts = line.split()
        if len(parts) < 5 or parts[-1] == "0":
            continue
        if f":{COMPANION_PORT}" in parts[1] and parts[3].upper() == "LISTENING":
            try:
                pids.add(int(parts[-1]))
            except ValueError:
                continue

    rows: list[tuple[int, str]] = []
    for pid in sorted(pids):
        probe = _powershell(
            f'(Get-CimInstance Win32_Process -Filter "ProcessId = {pid}").CommandLine'
        )
        rows.append((pid, probe.stdout.strip()))
    return rows


def _default_stop_process(pids: Sequence[int]) -> None:
    for pid in pids:
        subprocess.run(["taskkill", "/PID", str(pid), "/F"], check=False)


def _startup_shortcut(home: Path) -> Path:
    return (
        Path(home)
        / "AppData"
        / "Roaming"
        / "Microsoft"
        / "Windows"
        / "Start Menu"
        / "Programs"
        / "Startup"
        / f"{TASK_NAME}.lnk"
    )


def _default_remove_autostart(home: Path) -> list[str]:
    removed: list[str] = []
    runkey = r"HKCU\Software\Microsoft\Windows\CurrentVersion\Run"
    for label, args in (
        ("scheduled task", ["schtasks", "/delete", "/tn", TASK_NAME, "/f"]),
        ("HKCU Run value", ["reg", "delete", runkey, "/v", TASK_NAME, "/f"]),
    ):
        result = subprocess.run(args, capture_output=True, check=False)
        if result.returncode == 0:
            removed.append(label)
    shortcut = _startup_shortcut(home)
    if shortcut.exists():
        shortcut.unlink()
        removed.append("Startup shortcut")
    return removed


def _default_verify_autostart(home: Path) -> list[str]:
    remaining: list[str] = []
    checks = (
        ("scheduled task", ["schtasks", "/query", "/tn", TASK_NAME]),
        (
            "HKCU Run value",
            [
                "reg",
                "query",
                r"HKCU\Software\Microsoft\Windows\CurrentVersion\Run",
                "/v",
                TASK_NAME,
            ],
        ),
    )
    for label, args in checks:
        result = subprocess.run(args, capture_output=True, check=False)
        if result.returncode == 0:
            remaining.append(label)
    if _startup_shortcut(home).exists():
        remaining.append("Startup shortcut")
    return remaining


# The rule is system state, not a file. Removing it directly keeps cleanup
# independent of any script under the per-user directory that is about to be
# deleted, and keeps the displayed command and the executed command identical.
FIREWALL_PS_COMMAND = (
    f"Remove-NetFirewallRule -DisplayName '{FIREWALL_RULE}' -ErrorAction SilentlyContinue"
)


def firewall_command() -> str:
    return f'powershell -NoProfile -Command "{FIREWALL_PS_COMMAND}"'


def _default_run_command(command: str) -> int:
    result = subprocess.run(
        ["powershell", "-NoProfile", "-Command", FIREWALL_PS_COMMAND],
        capture_output=True,
        text=True,
        check=False,
    )
    return result.returncode


def _parse_args(argv: Sequence[str] | None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog="python -m companion.uninstall",
        description="Remove the ChileMon Companion App from this PC.",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="print the exact plan and change nothing",
    )
    parser.add_argument(
        "--keep-config",
        action="store_true",
        help="keep config.toml and logs; remove only code and autostart",
    )
    parser.add_argument(
        "--yes",
        action="store_true",
        help="skip the confirmation prompt (for automation)",
    )
    return parser.parse_args(argv)


def main(
    argv: Sequence[str] | None = None,
    *,
    home: Path | None = None,
    temp: Path | None = None,
    output: Output | None = None,
    input_fn: Callable[[str], str] | None = None,
    is_admin: Callable[[], bool] | None = None,
    query_processes: ProcessQuery | None = None,
    stop_process: ProcessStopper | None = None,
    remove_autostart: AutostartRemover | None = None,
    verify_autostart: AutostartVerifier | None = None,
    run_command: Runner | None = None,
    delete_path: Deleter | None = None,
) -> int:
    """Run the uninstall. injected callables keep every destructive step testable."""
    args = _parse_args(argv)
    say = output or print
    home = Path(home) if home is not None else Path.home()
    temp = Path(temp) if temp is not None else Path(os.environ.get("TEMP", home))
    admin_check = is_admin or _default_is_admin
    query = query_processes or _default_query_processes
    stop = stop_process or _default_stop_process
    remove_startup = remove_autostart or (lambda: _default_remove_autostart(home))
    verify_startup = verify_autostart or (lambda: _default_verify_autostart(home))
    runner = run_command or _default_run_command
    delete = delete_path or _default_delete_path

    plan = build_plan(home, temp=temp, keep_config=args.keep_config)
    say("ChileMon Companion uninstall plan:")
    for line in plan.describe():
        say(line)

    if args.dry_run:
        say("Dry run: no changes were made.")
        return 0

    if not confirm_uninstall(
        "Remove ChileMon Companion from this PC? [y/N] ",
        assume_yes=args.yes,
        input_fn=input_fn,
        output=say,
    ):
        return 1

    rows = list(query())
    companion_pids = find_companion_pids(rows)
    unknown_pids = [int(pid) for pid, _ in rows if int(pid) not in companion_pids]
    unverifiable_pids = [int(pid) for pid, cmd in rows if not str(cmd).strip()]
    if companion_pids:
        say(f"Stopping Companion process(es): {', '.join(map(str, companion_pids))}")
        stop(companion_pids)

    removed = remove_startup()
    say(f"Autostart entries removed: {', '.join(removed) if removed else 'none found'}")

    # The firewall rule is system state, not a file: remove it before deleting
    # anything so a complete uninstall cannot leave the rule behind.
    command = firewall_command()
    if admin_check():
        if runner(command) == 0:
            say(f"Firewall rule removed: {FIREWALL_RULE}")
        else:
            say(f"WARNING: could not remove firewall rule {FIREWALL_RULE}.")
            say(f"Run as administrator: {command}")
    else:
        say(f"Firewall rule {FIREWALL_RULE} was not removed (administrator rights required).")
        say(f"Run as administrator if you want it gone: {command}")

    failures: list[str] = []
    for path in [*plan.staging_dirs, *sorted(plan.paths, key=str)]:
        try:
            delete(path)
        except OSError as exc:
            failures.append(f"{path}: {exc}")
    if failures:
        for failure in failures:
            say(f"ERROR: could not remove {failure}")
    else:
        say("Per-user Companion data removed.")

    remaining_startup = verify_startup()
    remaining_pids = find_companion_pids(query())
    if remaining_startup or remaining_pids or unverifiable_pids or failures:
        for label in remaining_startup:
            say(f"ERROR: autostart entry still present: {label}")
        for pid in remaining_pids:
            say(f"ERROR: Companion still listening on port {COMPANION_PORT} (PID {pid})")
        for pid in unverifiable_pids:
            say(f"ERROR: could not verify owner of port {COMPANION_PORT} (PID {pid})")
        return 1

    say("Deliberately kept (remove manually if you want):")
    say("  - user-installed Python packages such as numpy, sounddevice and aiohttp")
    say("  - the downloaded Companion .bat in your Downloads folder")
    if unknown_pids:
        say(f"  - PID(s) {', '.join(map(str, unknown_pids))} on port {COMPANION_PORT}: not the Companion, left running")
    say("Companion fully removed.")
    return 0


if __name__ == "__main__":  # pragma: no cover
    raise SystemExit(main())
