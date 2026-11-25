"""Contract tests for the official one-line install command published in the repo."""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DOCS = (
    ROOT / "README.md",
    ROOT / "README_ES.md",
    ROOT / "docs" / "index.html",
    ROOT / "docs" / "index_en.html",
)

BOOTSTRAP_URL = "https://raw.githubusercontent.com/gismodes37/Chilemon/main/install/bootstrap.sh"
TMUX_INSTALL = "sudo apt-get install -y -qq tmux"
TMUX_SESSION = "tmux new-session -A -s chilemon 'sudo bash /tmp/bootstrap.sh'"
REATTACH = "tmux attach -t chilemon"


def test_all_official_docs_publish_the_tmux_install_command():
    for doc in DOCS:
        text = doc.read_text(encoding="utf-8", errors="replace")
        assert BOOTSTRAP_URL in text, doc
        assert TMUX_INSTALL in text, doc
        assert TMUX_SESSION in text, doc


def test_all_official_docs_explain_how_to_reattach_after_a_disconnect():
    for doc in DOCS:
        text = doc.read_text(encoding="utf-8", errors="replace")
        assert REATTACH in text, doc


def test_no_official_doc_publishes_the_unguarded_command():
    bare = f"curl -fsSL {BOOTSTRAP_URL} -o /tmp/bootstrap.sh && sudo bash /tmp/bootstrap.sh"
    for doc in DOCS:
        text = doc.read_text(encoding="utf-8", errors="replace")
        assert bare not in text, doc
