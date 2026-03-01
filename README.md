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

## 🛠 Instalación en Raspberry (Producción)

### 0️⃣ Preparar entorno

  - <span style="color:#666699">Instalar PHP completo y dependencias necesarias :</span> 
 
      ```ruby
      sudo apt update
      sudo apt install -y git apache2 php libapache2-mod-php php-cli php-sqlite3 sqlite3
      ```

  - Reinicia Apache :
      ```ruby
      sudo systemctl restart apache2
      ```

  - Habilitar módulo rewrite (requerido para sub-path y .htaccess) :
      ```ruby
      sudo a2enmod rewrite
      sudo systemctl restart apache2
      ```

  - Verificar que SQLite esté activo en PHP :
      ```ruby
      php -m | grep sqlite
      ```

  - Debe mostrar :
      ```ruby
      pdo_sqlite
      sqlite3
      ```
> Si no aparecen, revisa que php-sqlite3 esté instalado.

---

### 1️⃣ Clonar repositorio

  - Clonar repositorio
      ```ruby
      cd /opt
      sudo git clone https://github.com/gismodes37/chilemon.git
      ```
---

### 2️⃣ Crear carpetas necesarias

 - Crear carpetas
      ```ruby
      sudo mkdir -p /opt/chilemon/data
      sudo mkdir -p /opt/chilemon/logs
      ```
---

### 3️⃣ Configurar permisos (seguro para ASL3 limpio)

 - Permisos
      ```ruby
      sudo chown -R www-data:www-data /opt/chilemon
      sudo find /opt/chilemon -type d -exec chmod 2775 {} \;
      sudo find /opt/chilemon -type f -exec chmod 664 {} \;
      ```    
---

### 4️⃣ Configuración Apache (Sub-path)

 - Editar el VirtualHost SSL de ASL3:
      ```ruby
      sudo nano /etc/apache2/sites-available/default-ssl.conf
      ```
 - Dentro del bloque:
      ```ruby
      <VirtualHost *:443>
      ```

 - Agrega esto (antes de cerrar el bloque):
      ```ruby
      Alias /chilemon "/opt/chilemon/public"

      <Directory "/opt/chilemon/public">
          AllowOverride All
          Require all granted
      </Directory>
      ``` 

## 💾 Cómo guardar en nano

 - Presionar :
      ```ruby
      CTRL + O
      ```
 - confirmar con Enter:
      ```ruby
      ENTER
      ```
 - Luego presionar :
      ```ruby
      CTRL + X 
      ```
 - Reiniciar Apache :
      ```ruby
      sudo systemctl restart apache2
      ```      
---

### 5️⃣ Inicializar sistema (OBLIGATORIO)

 - Inicializar
      ```ruby
      cd /opt/chilemon
      sudo -u www-data php bin/install.php
      ```
Este script:

 - Crea la base de datos SQLite
 - Genera tablas necesarias
 - Verifica permisos
 - Crea el usuario administrador
 - El sistema solicitará:
      ```ruby
      Username     (Usuario)
      Password     (Contraseña)
      Confirmación (Confirmar contraseña)
      ```

---

## 6️⃣ Crear usuario adicionales (Opcional)

>Opcional, porque el instalador del paso 5 ya crea el primer usuario.
>Usa este paso solo si quieres agregar más usuarios.

  - Crear usuario:   
      ```ruby
      sudo -u www-data php bin/create-user.php
      ```

---

### 7️⃣ Acceso al sistema desde el navegador

 - Accedemos :
      ```ruby
      https://nodeXXXXX.local/chilemon/
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
<<<<<<< HEAD

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

## 🧩 Milestone 2 (En Desarrollo)
=======
>>>>>>> 0c1fc43 (Close Milestone 2: favorites modal + connect/disconnect + recent activity; update docs roadmap)

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

<<<<<<< HEAD
=======
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

>>>>>>> 0c1fc43 (Close Milestone 2: favorites modal + connect/disconnect + recent activity; update docs roadmap)
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

