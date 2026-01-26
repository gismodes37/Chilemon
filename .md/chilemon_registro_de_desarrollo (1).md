# Chilemon – Registro de Desarrollo

## Etapa 1: Base de Backend y Arquitectura (ASL3)

### Objetivo de la etapa
Establecer una base técnica sólida y compatible con **AllStarLink 3 (ASL3)** para el desarrollo de Chilemon, asegurando:
- Convivencia sin conflictos con servicios existentes (Apache, Allmon3, Cockpit).
- Backend moderno y extensible.
- Instalación limpia, reproducible y sostenible.

---

## 1. Contexto inicial

- Hardware: **Raspberry Pi B+**
- Sistema: **AllStarLink v3 (Debian 12)**
- Servicios existentes:
  - Apache2 (puertos 80 y 443)
  - Cockpit (puerto 9090)
  - Allmon3
- Entorno de desarrollo:
  - PC con Windows 11
  - VSCode
  - MobaXterm (SSH)

Restricción clave:
> Chilemon debe convivir con ASL3 y exponerse bajo un **sub-path** (`/chilemon`), sin tomar control del puerto 80 ni interferir con Apache.

---

## 2. Decisiones de arquitectura

### 2.1 Backend
- Framework elegido: **FastAPI**
- Motivos:
  - Ligero y rápido
  - API-first
  - Buen soporte para WebSockets (futuro)
  - Adecuado para hardware limitado

### 2.2 Servidor web frontal
- Se utiliza **Apache2 existente** en ASL3
- Chilemon se monta como **reverse proxy en sub-path**

URL final:
```
http://<IP-del-nodo>/chilemon/
```

---

## 3. Limpieza del sistema

### 3.1 Eliminación de Nginx

Se detectó que `nginx` estaba instalado, habilitado y fallando al arrancar (conflicto con Apache en puerto 80).

Acciones realizadas:
- Detención y deshabilitación del servicio
- Eliminación completa de paquetes
- Limpieza de dependencias huérfanas

Resultado:
- Sistema más limpio
- Menor consumo de recursos
- Arquitectura clara: Apache único frontend web

---

## 4. Backend inicial de Chilemon

### 4.1 Ubicación del proyecto
```
/opt/chilemon/backend/
```

### 4.2 Gestión de dependencias (PEP 668)

Debido a Debian 12 (PEP 668), se decidió:
- **NO** instalar paquetes Python a nivel sistema
- Crear un **entorno virtual (venv)** propio del proyecto

Entorno virtual:
```
/opt/chilemon/backend/.venv
```

Dependencias instaladas:
- fastapi
- uvicorn

---

## 5. Integración con Apache (sub-path)

Apache expone Chilemon mediante reverse proxy:

- Sub-path: `/chilemon/`
- Backend escuchando solo en `127.0.0.1:8088`

Esto permite:
- No exponer el backend directamente
- Mantener seguridad y control
- Escalar sin conflictos

---

## 6. Servicio systemd

Chilemon se ejecuta como servicio del sistema:

- Servicio: `chilemon.service`
- Usuario: `stg`
- Arranque automático al boot
- Reinicio automático en caso de fallo

El servicio fue **migrado** desde un backend antiguo (`app.py`) a FastAPI, reutilizando la estructura existente.

---

## 7. Endpoints iniciales

### Endpoint raíz
```
GET /chilemon/
```
Respuesta:
```json
{ "status": "Chilemon backend OK" }
```

### Health check
```
GET /chilemon/health
```
Respuesta:
```json
{ "service": "chilemon", "status": "up" }
```

---

## 8. Estado al cierre de la etapa

✔ Backend FastAPI funcionando
✔ Ejecutándose en virtualenv
✔ Gestionado por systemd
✔ Integrado a Apache bajo sub-path
✔ Compatible con ASL3
✔ Base lista para crecer

---

**Fin de la Etapa 1**

---

## Etapa 2: Fundamentos de FastAPI aplicados a Chilemon

### Objetivo de la etapa
Definir y comprender la **estructura base del backend Chilemon** usando FastAPI, estableciendo convenciones claras para:
- Organización del código
- Definición de rutas
- Separación de responsabilidades
- Crecimiento ordenado del proyecto

Esta etapa es **formativa y estructural**: no busca aún funcionalidad compleja, sino crear un marco sólido para lo que vendrá.

---

## 1. Enfoque adoptado

Chilemon adopta un enfoque **API-first**, donde:
- El backend es la fuente de verdad
- La UI (Bootstrap o consola PC) consume la API
- Los servicios de sistema (Asterisk, ASL3) se integran de forma controlada

FastAPI es ideal para este enfoque por su claridad, rendimiento y soporte nativo para asincronía.

---

## 2. Estructura recomendada del proyecto

Estructura inicial propuesta:

```
/opt/chilemon/backend/
├── main.py              # Punto de entrada de la aplicación
├── app/
│   ├── __init__.py
│   ├── api/             # Rutas (endpoints)
│   │   ├── __init__.py
│   │   ├── health.py
│   │   └── system.py    # Información del sistema / nodo
│   ├── core/            # Configuración central
│   │   ├── __init__.py
│   │   └── config.py
│   ├── services/        # Lógica de negocio (ASL, Asterisk, sistema)
│   │   ├── __init__.py
│   │   └── system_info.py
│   └── schemas/         # Modelos de datos (Pydantic)
│       ├── __init__.py
│       └── health.py
├── .venv/
└── requirements.txt
```

Esta estructura evita un backend monolítico y permite escalar sin reescribir.

---

## 3. Conceptos clave de FastAPI usados en Chilemon

### 3.1 Aplicación principal (`main.py`)

Responsabilidades:
- Crear la instancia de FastAPI
- Definir `root_path` (`/chilemon`)
- Incluir routers

`main.py` **no debe contener lógica de negocio**.

---

### 3.2 Routers (API)

Las rutas se agrupan por dominio funcional:
- health → estado del backend
- system → estado del nodo
- asterisk → integración futura

Esto permite:
- Lectura clara del código
- Permisos por grupo
- Documentación automática limpia

---

### 3.3 Schemas (Pydantic)

Se usan para:
- Validar respuestas
- Documentar contratos de la API
- Evitar respuestas ambiguas

Esto es clave cuando la API empiece a ser consumida por:
- UI web
- Consola PC
- Scripts externos

---

### 3.4 Services

Los servicios contienen la lógica real:
- Lectura de CPU, RAM, uptime
- Ejecución controlada de comandos
- Integración con ASL3/Asterisk

Las rutas **no ejecutan lógica directamente**: solo llaman a servicios.

---

## 4. Convenciones adoptadas

- Todo endpoint responde JSON
- Nada de lógica de sistema en routers
- El backend no asume UI específica
- Todo lo sensible queda encapsulado en services
- Preparado para control de permisos

---

## 5. Estado al cierre de la etapa

✔ Arquitectura FastAPI definida
✔ Estructura de proyecto clara
✔ Convenciones establecidas
✔ Base lista para agregar endpoints reales

---

**Fin de la Etapa 2**

