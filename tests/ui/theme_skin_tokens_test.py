"""Contract tests for skin token data.

The browser and PHP runtime are unavailable locally, so these checks pin the
CSS contract instead of pixels: the obsidian skin is a token override only,
and the clasico defaults keep their existing computed values.
"""

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CSS = (ROOT / "public" / "assets" / "css" / "dashboard.css").read_text(
    encoding="utf-8", errors="replace"
)


def _block(selector_pattern: str) -> str:
    match = re.search(selector_pattern + r"\s*\{([^{}]*)\}", CSS)
    assert match, f"missing CSS block for {selector_pattern}"
    return match.group(1)


def _declarations(block: str) -> dict[str, str]:
    return {
        prop.strip(): value.strip()
        for prop, value in re.findall(r"(--[\w-]+|color-scheme)\s*:\s*([^;]+);", block)
    }


def test_obsidian_defines_near_black_token_layer() -> None:
    tokens = _declarations(_block(r'\[data-skin="obsidian"\]'))

    assert tokens.get("color-scheme") == "dark"
    assert tokens.get("--bs-body-bg") == "#0b0d10"
    assert tokens.get("--bs-body-color") == "#e6e9ef"
    assert tokens.get("--bs-secondary-bg") == "#161a20"
    assert tokens.get("--bs-tertiary-bg") == "#1b2028"
    assert tokens.get("--bs-border-color") == "#2b323c"
    assert tokens.get("--bs-primary") == "#5b9cff"
    assert tokens.get("--chile-blue") == "#10151f"
    assert tokens.get("--chile-red") == "#ff4d5a"
    assert tokens.get("--cm-header-deep") == "#05070a"
    assert tokens.get("--cm-modal-bg") == "rgba(8, 10, 14, 0.92)"


def test_obsidian_light_mode_defines_a_light_layer() -> None:
    tokens = _declarations(
        _block(r'\[data-skin="obsidian"\]\[data-bs-theme="light"\]')
    )
    dark_tokens = _declarations(
        _block(r'\[data-skin="obsidian"\]\[data-bs-theme="dark"\]')
    )

    assert tokens.get("color-scheme") == "light"
    assert tokens.get("--bs-body-bg") == "#f4f7fb"
    assert tokens.get("--bs-body-color") == "#17202c"
    assert tokens.get("--cm-modal-bg") == "rgba(244, 247, 251, 0.96)"
    assert tokens.get("--cm-modal-fg") == "#17202c"
    assert tokens.get("--bs-body-bg") != dark_tokens.get("--bs-body-bg")


def test_obsidian_dark_mode_refines_the_same_tokens() -> None:
    tokens = _declarations(
        _block(r'\[data-skin="obsidian"\]\[data-bs-theme="dark"\]')
    )

    assert tokens.get("--bs-body-bg") == "#050608"
    assert tokens.get("--bs-body-color") == "#f2f5fa"
    assert tokens.get("--bs-secondary-bg") == "#101318"
    assert tokens.get("--bs-tertiary-bg") == "#15191f"
    assert tokens.get("--cm-modal-bg") == "rgba(3, 4, 6, 0.94)"


def test_obsidian_blocks_contain_tokens_only() -> None:
    selectors = (
        r'\[data-skin="obsidian"\]',
        r'\[data-skin="obsidian"\]\[data-bs-theme="dark"\]',
    )

    for selector in selectors:
        for line in _block(selector).splitlines():
            declaration = line.strip()
            if not declaration or declaration.startswith("/*"):
                continue
            prop = declaration.split(":", 1)[0].strip()
            assert prop.startswith("--") or prop == "color-scheme", declaration


def test_clasico_keeps_existing_token_values() -> None:
    tokens = _declarations(_block(r":root"))

    expected = {
        "--asl-green": "#28a745",
        "--asl-blue": "#007bff",
        "--asl-red": "#dc3545",
        "--asl-yellow": "#ffc107",
        "--asl-dark": "#343a40",
        "--asl-skyblue": "#17a2b8",
        "--asl-lime": "#6FAE1E",
        "--asl-green-dark": "#218838",
        "--chile-blue": "#0039A6",
        "--chile-red": "#D52B1E",
        "--cm-header-deep": "#00155a",
        "--cm-modal-bg": "rgba(4, 18, 85, 0.88)",
        "--cm-modal-border": "rgba(255, 255, 255, 0.22)",
        "--cm-modal-header-bg": "rgba(0, 0, 0, 0.22)",
    }

    for token, value in expected.items():
        assert tokens.get(token) == value, token


def test_modal_and_header_colors_are_tokenized() -> None:
    assert "background: var(--cm-modal-bg)" in CSS
    assert "border: 1px solid var(--cm-modal-border)" in CSS
    assert "background: var(--cm-modal-header-bg)" in CSS
    assert "border-bottom: 1px solid var(--cm-modal-header-border)" in CSS
    assert re.search(
        r"background:\s*linear-gradient\(\s*90deg,\s*"
        r"var\(--cm-header-overlay-start\),\s*"
        r"var\(--cm-header-overlay-end\)\s*\)",
        CSS,
    )
