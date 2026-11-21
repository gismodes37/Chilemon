from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
INSTALL = (ROOT / "install" / "install_chilemon.sh").read_text(encoding="utf-8")
DIALPLAN = (ROOT / "install" / "asterisk" / "extensions-companion.conf").read_text(
    encoding="utf-8"
)


def test_installer_installs_canonical_dialplan_context():
    """S2: a fresh Pi install must create the context the IAX2 peer points at."""
    assert "extensions-companion.conf" in INSTALL
    assert "/etc/asterisk/extensions.conf" in INSTALL
    assert "[radio-companion]" in INSTALL


def test_installer_uses_prompted_node_in_dialplan():
    """S3: the node typed at install time, not a hardcoded default, drives rpt()."""
    assert "s|__LOCAL_NODE__|${local_node}|g" in INSTALL
    assert "__LOCAL_NODE__" in DIALPLAN
    assert 'configure_webrtc_asterisk "$local_node"' in INSTALL


def test_dialplan_injection_is_idempotent():
    """S2: re-running the installer must not duplicate the context."""
    assert "grep -q '^\\[radio-companion\\]'" in INSTALL
    assert "backup_if_exists" in INSTALL
