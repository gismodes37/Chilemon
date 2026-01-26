import shutil
import subprocess
from datetime import datetime
from pathlib import Path


SYSTEMCTL = "/bin/systemctl" if Path("/bin/systemctl").exists() else "systemctl"


def _run(cmd: list[str]) -> str:
    try:
        p = subprocess.run(cmd, capture_output=True, text=True)
        out = (p.stdout or p.stderr).strip()
        return out
    except FileNotFoundError:
        return "ERROR: command not found"
    except Exception as e:
        return f"ERROR: {e}"


def _read_loadavg() -> list[str]:
    try:
        with open("/proc/loadavg", "r", encoding="utf-8") as f:
            return f.read().split()[:3]
    except Exception:
        return ["-", "-", "-"]


def _read_meminfo_kb() -> tuple[int, int]:
    """
    Returns: (MemTotal_kB, MemAvailable_kB)
    """
    mem_total = 0
    mem_avail = 0
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as f:
            for line in f:
                if line.startswith("MemTotal:"):
                    mem_total = int(line.split()[1])
                elif line.startswith("MemAvailable:"):
                    mem_avail = int(line.split()[1])
        return mem_total, mem_avail
    except Exception:
        return 0, 0


def kb_to_gb(kb: int) -> float:
    return round(kb / 1024 / 1024, 2)


def get_system_status() -> dict:
    # systemd (chilemon)
    is_active = _run([SYSTEMCTL, "is-active", "chilemon"])  # active/inactive/failed
    since_raw = _run([SYSTEMCTL, "show", "chilemon", "-p", "ActiveEnterTimestamp"])
    since = since_raw.replace("ActiveEnterTimestamp=", "").strip()

    # CPU / RAM
    loadavg = _read_loadavg()
    mem_total_kb, mem_avail_kb = _read_meminfo_kb()

    mem_total_gb = kb_to_gb(mem_total_kb)
    mem_avail_gb = kb_to_gb(mem_avail_kb)
    mem_used_gb = round(max(mem_total_gb - mem_avail_gb, 0), 2)

    # Disco (raíz)
    disk = shutil.disk_usage("/")
    disk_total_gb = round(disk.total / 1024 / 1024 / 1024, 2)
    disk_used_gb = round((disk.total - disk.free) / 1024 / 1024 / 1024, 2)
    disk_avail_gb = round(disk.free / 1024 / 1024 / 1024, 2)

    return {
        "service": {
            "name": "chilemon",
            "systemd_active": is_active,
            "active_since": since,
        },
        "node": {
            "timestamp": datetime.utcnow().isoformat() + "Z",
            "loadavg": loadavg,
            "memory_gb": {
                "total": mem_total_gb,
                "available": mem_avail_gb,
                "used": mem_used_gb,
            },
            # IMPORTANTE: claves alineadas con tu JS: data.node.disk_gb.total/used
            "disk_gb": {
                "total": disk_total_gb,
                "available": disk_avail_gb,
                "used": disk_used_gb,
            },
        },
    }
