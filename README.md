# 🇨🇱 ChileMon

**ChileMon** es una plataforma de monitoreo y visualización para nodos **AllStarLink 3 (ASL3)**, inspirada en el estilo clásico de Supermon, pero desarrollada con una arquitectura moderna y mantenible en PHP.

Diseñado para ejecutarse como sub-ruta bajo el nodo ASL :

 ```ruby
 https://nodeXXXXX.local/chilemon/
 ```
 - ChileMon no reemplaza ASL ni modifica Asterisk.
 - Funciona como un módulo independiente que añade:
 - Persistencia real de datos mediante SQLite
 - Sistema de autenticación propio
 - Información avanzada del sistema
 - Arquitectura modular preparada para crecer
 - Instalación limpia y reproducible desde repositorio
---

## 🎯 Objetivo del Proyecto

Supermon cumple una función visual básica, pero no incorpora:
 - Base de datos persistente
 - Gestión de usuarios
 - Modularidad extensible
 - Separación de capas
 - Flujo moderno de desarrollo

ChileMon nace para cubrir esa brecha, manteniendo compatibilidad visual y conceptual con el ecosistema ASL.

---

## 🚀 Características

- Dashboard estilo Supermon moderno (100% Bootstrap 5)
- Base de datos **SQLite** (ligera, portable y sin dependencias externas)
- Compatible con Raspberry Pi
- Soporte para sub-path `/chilemon`
- Información del sistema (IP, temperatura CPU, Memoria, Hostname, Zona Horaria, PHP, etc.)
- Arquitectura preparada para integración con Asterisk / ASL
- Sin dependencia de MySQL/MariaDB
---

## 🧱 Filosofía

ChileMon:

  ❌ No interfiere con ASL

  ❌ No modifica configuración de Asterisk

  ❌ No altera la landing original del nodo

  ✅ Opera como módulo independiente

  ✅ Respeta el estilo Supermon

  ✅ Añade estructura, persistencia y escalabilidad

---

## 📦 Requisitos

- Debian 12 (ASL3 Pi Appliance recomendado)
- PHP 8.2+
- Apache2
- Extensiones PHP: `pdo_sqlite` / `sqlite3`

---

## 🛠 Instalación en Raspberry / Nodo ASL3 (Producción)

### El usuario entra al nodo y abre la terminal o entra por SSH:

### 1. Conectarse al nodo
Abra la terminal local del nodo o conéctese por SSH desde otro equipo:

```php
ssh usuario@IP_DEL_NODO
```
---
### 2. Instalar Git

Actualice los paquetes:

```php    
 sudo apt update
 ```
Instale Git si aún no está disponible:

```php
 sudo apt install -y git
```
---
### 3. Descargar ChileMon desde el repositorio oficial

Ubíquese en /opt y clone el proyecto:

```php
 cd /opt
 sudo git clone https://github.com/gismodes37/chilemon.git
```

### 4. Entrar a la carpeta del proyecto

```php
 cd /opt/chilemon
```
---

### 5. Ejecutar el instalador automático

```php
 sudo bash install/install_chilemon.sh
```
---

### 6. Responder los datos solicitados por el instalador

Durante la instalación se solicitarán los siguientes datos:

 - Numero de nodo ASL local
 - IP o nombre del servidor

   Ejemplo:
     - Nodo local: `12345`
     - Servidor: `192.168.1.20`  ó  `node12345`

 - Texto descriptivo del nodo
 - Host AMI
 - Puerto AMI
 - Usuario AMI
 - Clave AMI
 - Timeout AMI

La clave AMI se ingresa de forma oculta por seguridad. Esta clave es la misma que puso a la hora de configurar su nodo ASL.

---

### 7. Validar acceso al sistema desde el navegador

Al finalizar, el instalador mostrará la URL sugerida para abrir ChileMon en el navegador,

 - por ejemplo:
     ```php
     http://IP_DEL_NODO/chilemon
     ```

 - Si su servidor ya tiene HTTPS activo, también puede probar:
     ```php
     https://IP_DEL_NODO/chilemon
     ```

---


## 🔐 Autenticación
 - Sistema de login propio con:
 - Tabla users
 - Passwords con password_hash()
 - Sesión PHP
 - Logout seguro
 - Protección de rutas privadas
 - El usuario inicial se crea durante instalación por consola.
---

## 📂 Estructura del Proyecto

    chilemon/
      │
      ├── app/
      │   └── Core/
      │       └── Database.php
      │
      ├── config/
      │   ├── app.php
      │   └── database.php
      │
      ├── data/
      │   └── chilemon.sqlite   (no debería versionarse)
      │
      ├── logs/
      │
      ├── public/
      │   ├── index.php
      │   ├── login.php
      │   ├── logout.php
      │   ├── api/
      │   │   ├── log-call.php
      │   │   ├── nodes.php
      │   │   └── stats.php
      │   └── assets/
      │
      ├── install/
      ├── bin/
      ├── README.md
      └── .gitignore


---

## 🧠 Arquitectura

### ChileMon:

- NO reemplaza ASL
- NO modifica configuración de Asterisk
- NO altera la landing original del nodo
- Funciona como módulo independiente bajo Apache


---

## 🔄 Flujo de Desarrollo

 - Se desarrolla siempre en local:

   - PC → GitHub → Raspberry

- Nunca modificar producción directamente.
- Branches recomendadas:
    
     - main → estable

     - dev → desarrollo

- Tags semánticos:

     - v0.4.0
     - v0.5.0
     - v1.0.0
---

## 🚀 Estado del Proyecto

### Milestone 1 – ✅ Completado

 - Base de datos SQLite estable
 - Eliminación total de MySQL/MariaDB
 - Dashboard estilo Supermon funcional
 - Soporte sub-path /chilemon
 - Información del sistema (CPU, IP, Hostname, etc.)
 - Login de usuarios implementado
 - Logout funcional
 - Permisos productivos configurados
 - Flujo Local → GitHub → Producción definido

---

### Milestone 2 – ✅ Completado (Supermon+ UX)

 - Botón y ventana modal de Favoritos desde el header
 - CRUD de favoritos por usuario (Nodo / Alias / Descripción editable)
 - Acción “Conectar” desde favoritos con confirmación
 - Botón “Desconectar” operativo desde el dashboard
 - Registro de actividad reciente (connect / disconnect / favorite*)
 - APIs protegidas por sesión (Unauthorized si no hay login)
 - Integración UI consistente (header + dashboard + estado)

---

### Milestone 3 – 🧭 En planificación / Inicio (Integración real con Asterisk)

Objetivo: pasar de “estado lógico” (DB/UI) a “estado real” con ASL/Asterisk.

 - Primera integración real con Asterisk (consulta de estado real del link)
 - Enlace “online real” (no solo marcado en DB)
 - Primera llamada / primer puente de audio (por etapas)
 - Base para monitoreo real (usuarios, links, rx/tx) desde Asterisk

---

## 🔮 Proyección Futura – Sistema de Versionado y Actualización

ChileMon contempla, en fases posteriores de desarrollo, la incorporación de un sistema formal de versionado visible y detección de nuevas versiones disponibles.

Este sistema podría incluir:

 - Visualización de versión instalada en la interfaz.

 - Verificación controlada de versiones disponibles.

 - Notificación discreta de actualizaciones.

 - Mecanismo seguro y supervisado de actualización.

Esta funcionalidad no será implementada hasta que exista una infraestructura clara de distribución y control, priorizando siempre:

 - Estabilidad del nodo.

 - No interferencia con ASL.

 - Control total por parte del administrador.

 - Integridad del repositorio y del sistema instalado.

ChileMon no incorporará mecanismos automáticos que comprometan el entorno productivo sin consentimiento explícito del administrador.

Esta característica formará parte de un milestone específico dedicado al ciclo de vida del producto.

## 👨‍💻 Autor

<ul>
 <li> Desarrollado en La Serena, Chile</li>
 <li> CA2IIG – Guillermo Ismodes López</li>
 <li> Servicios Tecnológicos Generales SpA</li>
 <li> La Serena - Chile</li>
</ul>
---

## 📜 Licencia

 - Licencia MIT para proyecto comunitario.
---

