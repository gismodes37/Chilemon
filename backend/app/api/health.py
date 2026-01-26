from fastapi import APIRouter

from app.schemas.health import HealthResponse

router = APIRouter(tags=["health"])


@router.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    return HealthResponse(service="chilemon", status="up")


#from fastapi import APIRouter

#router = APIRouter()

#@router.get("/health")
#def health():
#    return {"service": "chilemon", "status": "up"}
