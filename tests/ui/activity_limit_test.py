"""Contract tests for the dashboard's Recent Activity window.

The initial server render and the live polling must agree on the same number
of displayed sessions. PHP is not available locally, so these tests pin the
two call sites that control the visible window.
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DASHBOARD = (ROOT / "public" / "views" / "dashboard.php").read_text(
    encoding="utf-8", errors="replace"
)
LIVE_JS = (ROOT / "public" / "assets" / "js" / "dashboard-live.js").read_text(
    encoding="utf-8", errors="replace"
)


def test_initial_render_shows_ten_sessions() -> None:
    assert "NodeLogger::latest(10);" in DASHBOARD
    assert "NodeLogger::latest(15);" not in DASHBOARD


def test_live_poll_requests_ten_sessions() -> None:
    assert "api/ami/activity.php?limit=10" in LIVE_JS
    assert "api/ami/activity.php?limit=15" not in LIVE_JS
