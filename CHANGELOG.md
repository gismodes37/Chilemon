# Changelog

Todos los cambios importantes de este proyecto serán documentados en este archivo.

El formato está basado en **Keep a Changelog**  
https://keepachangelog.com/es-ES/1.1.0/

y este proyecto utiliza **Semantic Versioning**  
https://semver.org/lang/es/

---

# [0.1.0] - 2026-03-13

## 🚀 Primer release funcional

Primera versión pública de ChileMon.

Este release introduce el dashboard inicial para monitoreo y control de nodos AllStarLink.

---

## ✨ Añadido

- Dashboard web para monitoreo de nodos AllStarLink
- Visualización de nodos conectados mediante `rpt nodes`
- Lectura de estadísticas del nodo mediante `rpt stats`
- Conexión de nodos remotos desde el dashboard
- Desconexión de nodos remotos desde el dashboard
- Visualización de red de nodos enlazados mediante modal
- Sistema de autenticación de usuarios
- Gestión de nodos favoritos
- Registro de actividad reciente
- Instalador automático para sistemas ASL3
- Wrapper seguro `chilemon-rpt` para ejecución controlada de comandos Asterisk
- Base de datos SQLite para almacenamiento local
- Interfaz moderna basada en Bootstrap

---

## 🔐 Seguridad

- Uso de wrapper seguro para comandos `rpt`
- Restricción de ejecución mediante sudo para usuario `www-data`
- Protección básica de autenticación

---

## ⚙ Instalación

- Script de instalación automática `install_chilemon.sh`
- Configuración automática de Apache
- Inicialización automática de base SQLite
- Creación de usuario administrador

---

## 📌 Notas

ChileMon está inspirado en el concepto de **Supermon**, pero implementado con una arquitectura moderna y modular.

Este proyecto **no modifica ni reemplaza Supermon**, y puede coexistir con instalaciones existentes de AllStarLink.

---

## 🔮 Próximas mejoras

- Indicadores de actividad RX / TX en tiempo real
- Mejoras visuales en el dashboard
- Optimización de refresco automático
- Eventos extendidos del nodo