"""Static contract tests for the PHP/HTML side of the theme selector.

PHP and a browser are unavailable locally, so these pin the allowlist and the
data hooks that connect the server-rendered theme to ThemeManager.
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def read(*parts: str) -> str:
    return (ROOT.joinpath(*parts)).read_text(encoding="utf-8", errors="replace")


INDEX = read("public", "index.php")
HEAD = read("public", "views", "partials", "head.php")
HEADER = read("public", "views", "partials", "header.php")
FOOTER = read("public", "views", "partials", "footer.php")
DASHBOARD = read("public", "views", "dashboard.php")
JS = read("public", "assets", "js", "dashboard.js")
DEAD_JS = read("public", "assets", "js", "chilemon.js")


def test_single_cookie_is_allowlisted_server_side() -> None:
    assert "chilemon_theme" in INDEX
    assert "'clasico'" in INDEX and "'obsidian'" in INDEX
    assert "'light'" in INDEX and "'dark'" in INDEX
    assert INDEX.count("in_array(") >= 2
    assert "chilemon_darkmode" in INDEX  # legacy fallback still honoured


def test_html_emits_both_theme_attributes() -> None:
    assert "data-bs-theme=" in HEAD
    assert "data-skin=" in HEAD
    assert "$skin" in HEAD and "$mode" in HEAD


def test_header_exposes_both_skins_and_modes_as_radiogroups() -> None:
    assert 'data-bs-toggle="dropdown"' in HEADER
    assert 'aria-labelledby="appearance-menu"' in HEADER
    for value in ("clasico", "obsidian", "light", "dark"):
        assert f'data-theme-{"skin" if value in ("clasico", "obsidian") else "mode"}="{value}"' in HEADER
    assert HEADER.count('role="radio"') == 4
    assert 'aria-checked=' in HEADER


def test_floating_theme_button_is_removed() -> None:
    assert "theme-toggle-btn" not in FOOTER
    assert "data-theme-toggle" in FOOTER
    assert "data-theme-text" in FOOTER


def test_dashboard_button_has_theme_hooks() -> None:
    assert "data-theme-toggle" in DASHBOARD
    assert "data-theme-icon" in DASHBOARD


def test_js_has_a_single_theme_manager() -> None:
    assert "const ThemeManager" in JS
    assert "chilemon_theme" in JS
    assert "migrateLegacyTheme" in JS
    assert "bindControls" in JS
    assert '"clasico"' in JS and '"obsidian"' in JS
    assert JS.count("function toggleTheme") == 1


def test_dead_duplicate_theme_code_is_gone() -> None:
    assert "chilemon_darkmode" not in DEAD_JS
    assert "function toggleTheme" not in DEAD_JS
