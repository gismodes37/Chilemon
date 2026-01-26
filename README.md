# Chilemon

Backend FastAPI para servicios del proyecto Chilemon.

## Estructura
- `backend/` : Backend FastAPI
  - `main.py` : Entry point de la app (FastAPI)
  - `app/`
    - `api/` : Routers (endpoints)
    - `core/` : Configuración (settings)
    - `schemas/` : Modelos Pydantic (response/request)
    - `services/` : Lógica/servicios

## Requisitos (Local - Windows)
- Python 3.12+
- Git
- (Opcional) VSCode + extensión Python

## Setup Local (Windows)
Desde la carpeta `backend/`:

```powershell
cd backend
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
