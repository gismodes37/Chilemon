 <p align="center">
  <img src="docs/img/chilemon-banner.svg" alt="ChileMon Banner">
</p>

<p align="center">
<img src="docs/img/dashboard-main.png" width="900">
</p>

##

<p align="center">
Modern dashboard for monitoring and controlling AllStarLink nodes
</p>

<p align="center">
<img src="https://img.shields.io/badge/version-0.1.0-blue">
<img src="https://img.shields.io/badge/php-8.2+-blue">
<img src="https://img.shields.io/badge/database-SQLite-green">
<img src="https://img.shields.io/badge/ASL3-compatible-green">
<img alt="Static Badge" src="https://img.shields.io/badge/Bootstrap-5-%20%237952B3">
<img alt="Static Badge" src="https://img.shields.io/badge/Java-Script-EFD81C">
<img alt="Static Badge" src="https://img.shields.io/badge/License-MIT-blue">

</p>

# 
<p align="center">
  <img src="public/assets/img/chile-flag-brush.png" alt="ChileMon Banner" style="width:100px; height:auto;">
</p>

Dashboard moderno para monitoreo y control de nodos **AllStarLink (ASL3)**. ChileMon nace como una alternativa moderna inspirada en **Supermon**, diseñada para ofrecer una interfaz más clara, modular y fácil de instalar para operadores de nodos AllStar.

El objetivo del proyecto es proporcionar una herramienta simple y segura para visualizar y controlar la actividad de un nodo AllStarLink desde un navegador web.


# 🌐 Idiomas

🇪🇸 Español: README_ES.md  
🇬🇧 English: README.md

---

# 📡 ¿Qué es ChileMon?

ChileMon es una aplicación web que permite:

- monitorear nodos conectados
- conectar nodos remotos
- desconectar nodos
- visualizar la red de nodos enlazados
- revisar estadísticas del nodo
- administrar nodos favoritos
- visualizar actividad reciente

Todo desde una interfaz web moderna.

---

# ✨ Características principales

ChileMon actualmente incluye:

- Dashboard web para monitoreo de nodos AllStarLink
- Conexión y desconexión de nodos remotos
- Visualización de nodos conectados en tiempo real
- Estadísticas del nodo obtenidas desde Asterisk
- Modal para visualizar la red de nodos enlazados
- Gestión de nodos favoritos
- Registro de actividad reciente
- Ejecución segura de comandos mediante wrapper
- Instalador automático para sistemas ASL3

---

# 🧠 Filosofía del proyecto

ChileMon fue diseñado con los siguientes principios:

- **No interferir con AllStarLink**
- **No modificar Asterisk**
- **No reemplazar Supermon**
- **Ser una herramienta modular**
- **Ser fácil de instalar**
- **Ser seguro**

ChileMon funciona como un **módulo adicional**, sin alterar la instalación base del nodo.

---

# 🏗 Arquitectura

ChileMon está construido utilizando tecnologías simples y robustas.

### Backend

- PHP 8+
- SQLite

### Frontend

- Bootstrap 5
- JavaScript
- AJAX

### Servidor

- Apache

---

# 🔐 Seguridad

ChileMon **no ejecuta comandos Asterisk directamente desde PHP**.

En su lugar utiliza un wrapper seguro:

```php
/usr/local/bin/chilemon-rpt
```

Este wrapper permite ejecutar únicamente comandos específicos:

- rpt nodes
- rpt stats
- rpt connect
- rpt disconnect

Los comandos se ejecutan mediante una regla sudo restringida para el usuario:

```php
www-data
```

Esto evita la ejecución arbitraria de comandos en el sistema.

---

# ⚙ Cómo funciona ChileMon

ChileMon obtiene información del nodo ejecutando comandos `rpt` de Asterisk.

Ejemplo:

```php
sudo -u www-data sudo -n chilemon-rpt nodes 494780
```


El resultado es procesado por el servicio:

```php
src/Services/AslRptService.php
```

Este servicio interpreta la salida de Asterisk y extrae información como:

- nodos conectados
- estado del nodo
- estadísticas del sistema

Los datos procesados son mostrados en el dashboard web.

---

# 🖥 Dashboard

El dashboard principal permite visualizar:

- nodo local
- nodos conectados
- estado del sistema
- red de nodos enlazados
- actividad reciente

Además permite:

- conectar nodos
- desconectar nodos
- administrar favoritos

---

# 🔎 ChileMon vs Supermon

Supermon es la interfaz clásica utilizada por muchos nodos AllStarLink.

ChileMon está inspirado en ese concepto, pero introduce una arquitectura más moderna.

| Característica | Supermon | ChileMon |
|---|---|---|
| Interfaz | HTML clásico | Dashboard moderno |
| Autenticación | Login básico | Sistema de usuarios |
| Base de datos | No | SQLite |
| Instalación | Manual | Instalador automático |
| Arquitectura | Monolítica | Modular |
| Extensibilidad | Limitada | Preparado para expansión |

ChileMon **no reemplaza Supermon**, sino que ofrece una alternativa moderna para monitoreo y control del nodo.

---

# 📷 Capturas de pantalla

### Dashboard

![Dashboard](docs/img/screenshot01.png)

### Red de nodos

![Network](docs/img/network.png)

### Favoritos

![Favorites](docs/img/favorites.png)

---

# 🚀 Instalación

ChileMon puede instalarse directamente en un nodo ASL3.

### Clonar repositorio

```php
cd /opt
sudo git clone https://github.com/usuario/chilemon.git
```

### Ejecutar instalador

```php
cd /opt/chilemon
sudo bash install/install_chilemon.sh
```

El instalador realizará automáticamente:

- instalación de dependencias
- configuración de Apache
- creación del wrapper seguro
- inicialización de base SQLite
- creación de usuario administrador

---

# 📂 Estructura del proyecto

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
      ├── README_ES.md
      ├── README.md
      ├── CHANGELOG.md
      ├── LICENSE.md
      └── .gitignore


---

# 📈 Roadmap

  
<img src="https://img.shields.io/badge/version-0.1.0-blue">

Release inicial funcional

<img alt="Static Badge" src="https://img.shields.io/badge/Version-0.2.0-blue">

Actividad RX/TX en tiempo real

<img alt="Static Badge" src="https://img.shields.io/badge/Version-0.3.0-blue">

Mejoras de dashboard

<img alt="Static Badge" src="https://img.shields.io/badge/Version-0.4.0-blue">

Eventos y estadísticas extendidas

<img alt="Static Badge" src="https://img.shields.io/badge/Version-1.0-green">

Release estable

---

# 📦 Release

## v0.1.0

Primer release funcional de ChileMon.

Incluye:

- Dashboard operativo
- Conexión y desconexión de nodos
- Visualización de red de nodos
- Gestión de favoritos
- Actividad reciente
- Instalador automático
- Integración con AllStarLink

---

# ❤️ Apoyar ChileMon

ChileMon es un proyecto independiente desarrollado para la comunidad de radioaficionados y usuarios de **AllStarLink**. Si este proyecto te resulta útil, puedes apoyar su desarrollo con una **donación voluntaria**.

### ¿En qué ayudan las donaciones?

El desarrollo de ChileMon se realiza de forma independiente. Las donaciones ayudan a sostener el proyecto y permiten seguir mejorándolo.

- Desarrollo continuo del dashboard
- Pruebas en nodos reales AllStarLink
- Mejoras de seguridad y estabilidad
- Documentación y sitio web del proyecto
- Mantenimiento y evolución futura

### Formas de apoyar

- **PayPal:** [Apoyar Chilemon](https://www.paypal.com/ncp/payment/J56JZF5CPRBVG)
- **GitHub Sponsors:** [gismodes37](https://github.com/sponsors/gismodes37)

También puedes apoyar el proyecto colaborando con código, reportando errores o probando ChileMon en nodos reales.

> ChileMon es un proyecto comunitario para radioaficionados. Las donaciones son completamente voluntarias y ayudan a sostener el desarrollo del proyecto.

---


# 🤝 Contribuciones

Las contribuciones son bienvenidas.

Si deseas colaborar:

1. Haz un fork del repositorio
2. Crea una rama nueva
3. Envía un pull request

---

## 📄 Licencia

ChileMon se distribuye bajo licencia [MIT](LICENSE).

![License](https://img.shields.io/badge/license-MIT-blue)


---

# 🇨🇱 ChileMon

Project developed for the Spanish-speaking AllStarLink community.



