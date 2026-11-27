"""
The one-click updater must follow the branch checked out on the Pi.

After the live install switched from ``main`` to ``develop``, the wrapper kept
comparing HEAD with ``origin/main``. Every check reported a false update and an
empty summary. These tests pin the wrapper to the checkout's upstream instead.

Run with: python -m pytest tests/install/update_wrapper_branch_test.py -v
"""

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
WRAPPER = (ROOT / "bin" / "chilemon-rpt").read_text(encoding="utf-8")


def _verb(name: str) -> str:
    """Return the body of one wrapper case, up to the next case label."""
    start = WRAPPER.index(f"    {name})")
    match = re.search(r"^    [a-z][a-z-]+\)$", WRAPPER[start + 1 :], re.MULTILINE)
    end = start + 1 + match.start() if match else len(WRAPPER)
    return WRAPPER[start:end]


def test_git_compare_uses_the_checked_out_upstream() -> None:
    compare = _verb("git-compare")

    assert "@{upstream}" in compare
    assert "origin/main" not in compare
    assert "HEAD.." in compare


def test_git_pull_follows_the_checked_out_upstream() -> None:
    pull = _verb("git-pull")

    assert "git pull 2>&1" in pull
    assert "git pull origin main" not in pull
