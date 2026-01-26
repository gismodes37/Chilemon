from fastapi import APIRouter
from app.schemas.node import NodeInfoResponse
from app.services.node_info import get_node_info

router = APIRouter(tags=["node"])


@router.get("/node/info", response_model=NodeInfoResponse)
def node_info() -> NodeInfoResponse:
    return NodeInfoResponse(**get_node_info())
