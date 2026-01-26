# ChileMon – Fase 1: Entorno base y nodo estable

## Objetivo
Dejar operativo y estable el backend de ChileMon sobre ASL3, accesible bajo un sub-path (`/chilemon`) sin interferir con los servicios existentes del nodo.

## Alcance de la fase
- Backend FastAPI ejecutándose con Uvicorn
- Servicio gestionado por systemd
- Reverse proxy con Apache bajo `/chilemon`
- Entorno virtual Python aislado (`.venv`)
- Verificación completa de conectividad
- Registro de decisiones y lecciones aprendidas

## Entorno
### Nodo
- Hardware: Raspberry Pi B+
- OS / Stack: ASL3
- IP LAN: `192.168.100.42`

### Puertos
- Backend interno: `127.0.0.1:8088`
- HTTP/HTTPS: gestionado por Apache

## Estructura en el nodo
```
/opt/chilemon/
├─ backend/
│  ├─ main.py
│  ├─ app_legacy_httpserver.py
│  ├─ requirements.txt
│  ├─ .venv/
│  └─ __pycache__/
├─ install/
├─ web/
└─ README.md
```

## Servicio systemd
Archivo: `/etc/systemd/system/chilemon.service`

```ini
[Unit]
Description=Chilemon Backend (FastAPI)
After=network.target

[Service]
User=stg
Group=stg
WorkingDirectory=/opt/chilemon/backend
Environment="PATH=/opt/chilemon/backend/.venv/bin"
ExecStart=/opt/chilemon/backend/.venv/bin/python -m uvicorn main:app --host 127.0.0.1 --port 8088
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

## Apache (Reverse Proxy)
Configurado en los VirtualHost activos (`000-default.conf` y `default-ssl.conf`).

Puntos clave:
- Sub-path: `/chilemon/`
- Backend: `http://127.0.0.1:8088/`
- Header: `X-Forwarded-Prefix /chilemon`
- WebSocket: `/chilemon/ws/`

> Se eliminó configuración duplicada en `conf-enabled/chilemon.conf` para evitar conflictos.

## Dependencias Python
- Entorno virtual: `/opt/chilemon/backend/.venv`
- Archivo reproducible: `/opt/chilemon/backend/requirements.txt`

Creación (desde el nodo):
```bash
cd /opt/chilemon/backend
source .venv/bin/activate
python -m pip freeze > requirements.txt
deactivate
```

## Validación final
Todos los endpoints responden **200 OK**:

```bash
curl -i http://127.0.0.1:8088/health
curl -i http://127.0.0.1/chilemon/health
curl -i http://192.168.100.42/chilemon/health
```

## Incidentes resueltos
- **503 Service Unavailable**: causado por `ExecStart` apuntando a un binario inexistente (`.venv/bin/uvicorn`).
- **Restart loop de systemd**: corregido usando `python -m uvicorn`.
- **Confusión de puertos**: se intentó levantar Uvicorn manualmente mientras systemd ya lo usaba.
- **requirements.txt ausente**: regenerado desde la venv funcional.

## Lecciones aprendidas
- No ejecutar Uvicorn manualmente en el mismo puerto que systemd.
- No mezclar rutas Windows y Linux.
- Mantener una única fuente de verdad para Apache y systemd.
- Documentar cada cambio antes de avanzar de fase.

## Estado al cierre de la fase
- Nodo estable
- Backend operativo
- Infraestructura validada
- Listo para desarrollo funcional

---
**Fase 1 cerrada.**

