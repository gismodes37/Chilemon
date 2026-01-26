import subprocess
import shutil
from datetime import datetime


def _run(cmd: list[str]) -> str:
    try:
        p = subprocess.run(cmd, capture_output=True, text=True)
        out = (p.stdout or p.stderr).strip()
        return out
    except FileNotFoundError:
        return "ERROR: command not found"
    except Exception as e:
        return f"ERROR: {e}"


def get_system_status(service_name: str = "chilemon") -> dict:
    # systemd
    is_active = _run(["systemctl", "is-active", service_name])  # active/inactive/failed
    since = _run(["systemctl", "show", service_name, "-p", "ActiveEnterTimestamp"])
    since = since.replace("ActiveEnterTimestamp=", "")

    # CPU / RAM
    loadavg = _run(["cat", "/proc/loadavg"]).split()[:3]
    meminfo = _run(["bash", "-lc", "awk '/MemTotal|MemAvailable/ {print $2}' /proc/meminfo"]).split()
    mem_total_kb = int(meminfo[0]) if len(meminfo) > 0 else 0
    mem_avail_kb = int(meminfo[1]) if len(meminfo) > 1 else 0

    def kb_to_gb(kb: int) -> float:
        return round(kb / 1024 / 1024, 2)

    # Disco (raíz)
    disk = shutil.disk_usage("/")
    disk_total_gb = round(disk.total / 1024 / 1024 / 1024, 2)
    disk_free_gb = round(disk.free / 1024 / 1024 / 1024, 2)

    return {
        "service": {
            "name": service_name,
            "systemd_active": is_active,
            "active_since": since,
        },
        "node": {
            "timestamp": datetime.utcnow().isoformat() + "Z",
            "loadavg": loadavg,
            "memory_gb": {
                "total": kb_to_gb(mem_total_kb),
                "available": kb_to_gb(mem_avail_kb),
                "used": round(kb_to_gb(mem_total_kb - mem_avail_kb), 2),
            },
            "disk_gb": {  # tu JSON actual ya usa disk_gb
                "total": disk_total_gb,
                "available": disk_free_gb,
                "used": round(disk_total_gb - disk_free_gb, 2),
            },
        },
    }
