# 🇨🇱 ChileMon

**ChileMon** es un dashboard estilo Supermon desarrollado en PHP para nodos.

**AllStarLink 3 (ASL3)**

Diseñado para ejecutarse como sub-ruta bajo el nodo ASL :

 ```ruby
 https://nodeXXXXX.local/chilemon/
 ```

Su objetivo es proporcionar monitoreo, visualización y herramientas adicionales para radioaficionados, sin interferir con la  - instalación base de ASL3.
 - Seguridad
 - Persistencia de datos
 - Arquitectura mantenible
 - Personalización
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

## 📦 Requisitos

- Debian 12 (ASL3 Pi Appliance recomendado)
- PHP 8.2+
- Apache2
- Extensión `pdo_sqlite`

  - Verificar SQLite:
    ```ruby
    php -m | grep sqlite
    ```

  - Debe mostrar:
    ```ruby
    pdo_sqlite
    ```
---

# Verificar soporte SQLite:

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

## 🧠 Filosofía del Proyecto
### ChileMon:

  ❌ No reemplaza ASL

  ❌ No modifica configuración de Asterisk

  ❌ No interfiere con el nodo

  ✅ Funciona como módulo independiente

  ✅ Respeta el concepto visual de Supermon

  ✅ Agrega estructura y persistencia mediante tablas

---

##   Autenticación
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

    - La base de datos SQLite se ubica en:
  
  ```ruby
  /opt/chilemon/data/chilemon.sqlite
  ```
---

## 🛠 Instalación en Raspberry (Producción)

### 1️⃣ Clonar repositorio

```ruby
cd /opt
sudo git clone https://github.com/gismodes37/chilemon.git
```

### 2️⃣ Crear carpetas necesarias

```ruby
sudo mkdir -p /opt/chilemon/data
sudo mkdir -p /opt/chilemon/logs
```

### 3️⃣ Configurar permisos

```ruby
sudo chown -R www-data:stg /opt/chilemon
sudo find /opt/chilemon -type d -exec chmod 2775 {} ;
sudo find /opt/chilemon -type f -exec chmod 664 {} ;
```
---

## 🌐 Configuración Apache (Sub-path)

Agregar en la configuración SSL o VirtualHost:

```ruby
Alias /chilemon "/opt/chilemon/public"
```

```ruby
<Directory "/opt/chilemon/public">
AllowOverride All
Require all granted
</Directory>
```

 - Reiniciar Apache:

```ruby
sudo systemctl restart apache2
```

 - Acceso : node+numero_de_nodo.local:

```ruby
https://nodeXXXXX.local/chilemon/
```
---

## 🗃 Base de Datos

## ChileMon utiliza SQLite por defecto.

### Archivo de configuración:

```ruby
config/database.php
```
---

## 🔄 Flujo de Desarrollo

 - Se desarrolla siempre en local:

        PC → GitHub → Raspberry

- Nunca modificar producción directamente.
- Branches recomendadas:
    
     - main → estable

     - dev → desarrollo

- Tags semánticos:

     - v0.4.0
     - v0.5.0
     - v1.0.0
---

## 🧩 Milestone 2 (En Desarrollo)

### Objetivos inmediatos:

<ul>
 <li>Primera conexión real a nodo ASL</li>
 <li> Registro de actividad real en tabla nodes</li>
 <li> Sistema de favoritos</li>
 <li> Cabecera personalizable (cabecera.php)</li>
 <li> Instalador por consola con creación de usuario</li>
</ul>

---

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

