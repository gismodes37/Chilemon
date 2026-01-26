from pydantic import BaseModel
from typing import Optional, Dict, Any


class NodeInfoResponse(BaseModel):
    service: str = "chilemon"
    hostname: Optional[str] = None
    asl_release: Optional[str] = None  # mejor esfuerzo (puede ser None)
    asterisk_version: Optional[str] = None
    node_number: Optional[str] = None
    callsign: Optional[str] = None
    config_sources: Dict[str, Any] = {}  # trazabilidad: de dónde se sacó cada dato
