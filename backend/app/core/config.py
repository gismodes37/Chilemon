from pydantic import BaseModel


class Settings(BaseModel):
    # Sub-path requerido por tu Apache: http://<IP>/chilemon
    root_path: str = "/chilemon"


settings = Settings()
