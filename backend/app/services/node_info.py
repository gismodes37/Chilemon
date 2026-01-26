import os
import re
import socket
import subprocess
from typing import Optional, Tuple, Dict, Any


def _run(cmd: list[str], timeout: int = 2) -> Tuple[Optional[str], Optional[str]]:
    """Ejecuta comando en modo lectura. Retorna (stdout, error)."""
    try:
        p = subprocess.run(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=timeout,
            check=False,
        )
        out = (p.stdout or "").strip()
        err = (p.stderr or "").strip()
        return (out if out else None, err if err else None)
    except Exception as e:
        return (None, str(e))


def _read_first_existing(paths: list[str], max_bytes: int = 200_000) -> Tuple[Optional[str], Optional[str]]:
    """Lee el primer archivo existente de la lista. Retorna (contenido, path)."""
    for path in paths:
        try:
            if os.path.isfile(path):
                with open(path, "r", encoding="utf-8", errors="ignore") as f:
                    return (f.read(max_bytes), path)
        except Exception:
            continue
    return (None, None)


def _parse_rpt_conf(text: str) -> Tuple[Optional[str], Optional[str], Dict[str, Any]]:
    """
    Extrae node_number y callsign con heurísticas (ASL puede variar).
    Mejor esfuerzo; si no detecta, retorna None.
    """
    sources: Dict[str, Any] = {}

    # 1) Intento: sección [<node>] en rpt.conf (común en AllStar)
    # Busca patrones tipo: [12345]
    m = re.search(r"^\s*\[(\d{3,8})\]\s*$", text, flags=re.MULTILINE)
    node_number = m.group(1) if m else None
    if node_number:
        sources["node_number"] = {"method": "rpt.conf section", "value": node_number}

    # 2) Intento callsign: a veces aparece como "call=" o "callsign=" o "idrecording="
    # Esto es variable; probamos varios patrones.
    callsign = None
    for pat in [r"^\s*callsign\s*=\s*([A-Z0-9\/\-]+)\s*$",
                r"^\s*call\s*=\s*([A-Z0-9\/\-]+)\s*$",
                r"^\s*mycall\s*=\s*([A-Z0-9\/\-]+)\s*$"]:
        mm = re.search(pat, text, flags=re.MULTILINE | re.IGNORECASE)
        if mm:
            callsign = mm.group(1).strip()
            sources["callsign"] = {"method": "rpt.conf key", "pattern": pat, "value": callsign}
            break

    return node_number, callsign, sources


def _get_asterisk_version() -> Tuple[Optional[str], Dict[str, Any]]:
    out, err = _run(["/usr/sbin/asterisk", "-V"], timeout=2)
    if out:
        return out, {"method": "asterisk -V"}
    # fallback
    out2, err2 = _run(["asterisk", "-V"], timeout=2)
    if out2:
        return out2, {"method": "asterisk -V (PATH)"}
    return None, {"method": "asterisk -V", "error": err or err2}


def _get_asl_release_best_effort() -> Tuple[Optional[str], Dict[str, Any]]:
    """
    ASL3 no siempre expone un 'version file' único.
    Hacemos mejor esfuerzo:
      - /etc/os-release (para Debian base)
      - archivos típicos si existen
    """
    # 1) /etc/os-release (no es ASL, pero ayuda)
    txt, path = _read_first_existing(["/etc/os-release"])
    if txt:
        name = re.search(r'^PRETTY_NAME="([^"]+)"', txt, flags=re.MULTILINE)
        if name:
            return name.group(1), {"method": "os-release", "path": path}

    # 2) rutas típicas (si existieran en tu build)
    txt2, path2 = _read_first_existing([
        "/etc/allstarlink-release",
        "/etc/asl-release",
        "/etc/allstarlink/version",
        "/etc/allstarlink/version.txt",
    ])
    if txt2:
        first = txt2.strip().splitlines()[0].strip()
        return first, {"method": "release file", "path": path2}

    return None, {"method": "best-effort", "note": "no release file found"}


def get_node_info() -> Dict[str, Any]:
    sources: Dict[str, Any] = {}

    hostname = socket.gethostname()
    sources["hostname"] = {"method": "socket.gethostname"}

    # rpt.conf puede estar en /etc/asterisk/rpt.conf, o en alguna variante "local"
    rpt_text, rpt_path = _read_first_existing([
        "/etc/asterisk/rpt.conf",
        "/etc/asterisk/local/rpt.conf",
        "/etc/asterisk/rpt.conf.local",
    ])

    node_number = None
    callsign = None

    if rpt_text and rpt_path:
        n, c, src = _parse_rpt_conf(rpt_text)
        node_number, callsign = n, c
        sources["rpt_conf"] = {"path": rpt_path}
        sources.update(src)
    else:
        sources["rpt_conf"] = {"path": None, "note": "not found"}

    asterisk_version, a_src = _get_asterisk_version()
    sources["asterisk_version"] = a_src

    asl_release, asl_src = _get_asl_release_best_effort()
    sources["asl_release"] = asl_src

    return {
        "service": "chilemon",
        "hostname": hostname,
        "asl_release": asl_release,
        "asterisk_version": asterisk_version,
        "node_number": node_number,
        "callsign": callsign,
        "config_sources": sources,
    }
