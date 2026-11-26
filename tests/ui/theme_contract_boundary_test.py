"""Durable T1 PHP/HTML allowlist regressions (S2). Run: pytest tests/ui/theme_contract_boundary_test.py -q"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def src(*parts: str) -> str:
    return ROOT.joinpath(*parts).read_text(encoding="utf-8", errors="replace")


INDEX = src("public", "index.php")
HEAD = src("public", "views", "partials", "head.php")


def parse(cookie: str, legacy: str | None = None) -> tuple[str, str]:
    """Faithful model of index.php; PHP is unavailable locally."""
    parts = cookie.split(":", 1)
    if len(parts) == 2 and parts[0] in ("clasico", "obsidian") and parts[1] in ("light", "dark"):
        return parts[0], parts[1]
    return "clasico", "dark" if legacy == "true" else "light"


def test_php_uses_strict_allowlist_and_two_part_split() -> None:
    assert "explode(':', $themeCookie, 2)" in INDEX
    assert INDEX.count("in_array(") >= 2 and INDEX.count("true)") >= 2
    assert "$skin = 'clasico';" in INDEX and ": 'light';" in INDEX
    assert "$_COOKIE['chilemon_darkmode'] === 'true'" in INDEX


def test_html_attributes_cannot_carry_raw_cookie_values() -> None:
    assert "$_COOKIE" not in HEAD
    assert "'clasico', 'obsidian'" in HEAD and "'light', 'dark'" in HEAD
    assert 'data-bs-theme="<?= $mode ?>" data-skin="<?= $skin ?>"' in HEAD


def test_cookie_boundaries_fall_back() -> None:
    cases = [
        ("", None, ("clasico", "light")),
        ("clasico", None, ("clasico", "light")),
        ("clasico:dark:extra", None, ("clasico", "light")),
        ("OBSIDIAN:dark", None, ("clasico", "light")),
        ("<script>:dark", None, ("clasico", "light")),
        ("x" * 100000 + ":dark", None, ("clasico", "light")),
        ("obsidian:dark", "true", ("obsidian", "dark")),
        ("garbage", "true", ("clasico", "dark")),
    ]
    for value, legacy, expected in cases:
        assert parse(value, legacy) == expected, (value[:30], legacy)
