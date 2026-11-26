"""Static contract for the header action row.

The fixed header is shared by every dashboard view and its buttons must stay
in a single row below the header text. PHP/browser execution is unavailable
locally, so the structure is pinned here.
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

HEADER = (ROOT / "public" / "views" / "partials" / "header.php").read_text(
    encoding="utf-8", errors="replace"
)
CSS = (ROOT / "public" / "assets" / "css" / "dashboard.css").read_text(
    encoding="utf-8", errors="replace"
)


def _between(source: str, start: str, end: str) -> str:
    after_start = source.split(start, 1)[1]
    return after_start.split(end, 1)[0]


def test_all_header_buttons_share_one_row_below_the_text() -> None:
    assert 'class="header-actions' in HEADER, "missing single action row container"
    row = _between(HEADER, 'class="header-actions', "<!-- /header-actions -->")

    assert 'id="update-status-badge"' in row
    assert 'data-bs-target="#favoritesModal"' in row
    assert 'id="appearance-menu"' in row
    assert 'rtrim(BASE_PATH, \'/\') ?>/logout.php' in row

    texts = HEADER.split('class="header-actions', 1)[0]
    assert 'class="quick-info' in texts
    assert "$username" in texts

    assert 'id="update-status-badge"' not in texts
    assert 'data-bs-target="#favoritesModal"' not in texts
    assert 'id="appearance-menu"' not in texts
    assert "/logout.php" not in texts


def test_action_row_is_flex_wrap_and_compacts_on_scroll() -> None:
    row_css = _between(CSS, "\n        .header-actions {", "}")
    assert "display: flex" in row_css
    assert "flex-wrap: wrap" in row_css
    assert "justify-content: flex-end" in row_css
    assert ".supermon-header.scrolled .header-actions" in CSS


def test_existing_theme_and_logout_hooks_survive() -> None:
    assert 'aria-labelledby="appearance-menu"' in HEADER
    assert 'data-theme-skin="clasico"' in HEADER
    assert 'data-theme-skin="obsidian"' in HEADER
    assert 'data-theme-mode="light"' in HEADER
    assert 'data-theme-mode="dark"' in HEADER
    assert 'name="csrf_token"' in HEADER
