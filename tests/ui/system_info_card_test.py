"""Contract tests for the Raspberry Pi hardware card on the dashboard.

The card must display live values instead of showing shell commands as if they
were data, and the "Info Avanzada" button must reveal a real panel. PHP is not
available locally, so these tests pin the server-rendered contract statically.
"""

from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DASHBOARD = (ROOT / "public" / "views" / "dashboard.php").read_text(
    encoding="utf-8", errors="replace"
)
SYSTEM = (ROOT / "app" / "Helpers" / "System.php").read_text(
    encoding="utf-8", errors="replace"
)


def test_hardware_card_renders_live_values() -> None:
    assert "Hardware RPi" in DASHBOARD
    assert "Comandos RPi" not in DASHBOARD
    for key in ("cpu_model", "mem_used", "mem_total", "mem_used_percent"):
        assert f"$systemInfo['{key}']" in DASHBOARD


def test_advanced_panel_uses_a_real_collapse() -> None:
    assert 'onclick="loadAdvancedSystemInfo()"' not in DASHBOARD
    assert 'id="advanced-system-info"' in DASHBOARD
    assert 'data-bs-target="#advanced-system-info"' in DASHBOARD


def test_system_helper_exposes_cpu_memory_and_uptime() -> None:
    assert "/proc/cpuinfo" in SYSTEM
    assert "/proc/meminfo" in SYSTEM
    assert "/proc/uptime" in SYSTEM
    for key in (
        "cpu_model",
        "cpu_board",
        "cpu_cores",
        "mem_total",
        "mem_used",
        "mem_available",
        "mem_used_percent",
        "uptime",
        "kernel",
    ):
        assert f"'{key}'" in SYSTEM
