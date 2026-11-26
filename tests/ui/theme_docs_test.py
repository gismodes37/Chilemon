"""Documentation contract for the selectable dashboard skins."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def _read(name: str) -> str:
    return (ROOT / name).read_text(encoding="utf-8", errors="replace")


def test_english_readme_documents_skin_selection() -> None:
    readme = _read("README.md")

    assert "Appearance" in readme
    for token in ("clasico", "obsidian", "chilemon_theme"):
        assert token in readme, token


def test_spanish_readme_documents_skin_selection() -> None:
    readme = _read("README_ES.md")

    assert "Apariencia" in readme
    for token in ("clasico", "obsidian", "chilemon_theme"):
        assert token in readme, token
