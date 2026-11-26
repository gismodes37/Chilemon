"""Contract tests for the dashboard theme foundation.

These pin the structural CSS rules that later skins depend on. PHP and a real
browser are unavailable locally, so the checks are static and intentionally
narrow: they verify the cascade fixes and the token layer, not pixel output.
"""

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CSS = (ROOT / "public" / "assets" / "css" / "dashboard.css").read_text(
    encoding="utf-8", errors="replace"
)
HEAD = (ROOT / "public" / "views" / "partials" / "head.php").read_text(
    encoding="utf-8", errors="replace"
)


def test_body_padding_reset_does_not_cancel_fixed_header_offset() -> None:
    assert not re.search(r"html\s*,\s*body\s*\{[^}]*padding\s*:\s*0", CSS)
    assert "body.has-relative-header" in CSS
    assert "has-relative-header" in HEAD


def test_header_compaction_is_no_longer_blocked_by_important() -> None:
    assert "padding-top: 2rem !important" not in CSS
    assert "padding-bottom: 0.5rem !important" not in CSS
    assert ".supermon-header.scrolled" in CSS


def test_skyblue_token_is_defined_and_used() -> None:
    assert re.search(r"--asl-skyblue\s*:\s*#", CSS)
    assert "var(--asl-skyblue)" in CSS


def test_table_font_size_has_a_single_owner() -> None:
    owners = re.findall(r"\.supermon-table\s*\{[^}]*font-size", CSS)
    assert len(owners) == 1, owners


def test_structural_colors_come_from_tokens() -> None:
    assert re.search(r"\.temp-low\s*\{\s*color:\s*var\(--asl-green\)", CSS)
    assert re.search(r"\.temp-medium\s*\{\s*color:\s*var\(--asl-yellow\)", CSS)
    assert re.search(r"\.temp-high\s*\{\s*color:\s*var\(--asl-red\)", CSS)
    assert "var(--cm-header-deep)" in CSS
    assert "var(--asl-lime)" in CSS
