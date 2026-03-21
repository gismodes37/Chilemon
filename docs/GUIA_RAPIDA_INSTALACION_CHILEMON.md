# Guía rápida de instalación de ChileMon

Esta guía está escrita para una prueba real como usuario final en un nodo nuevo.

## 1. Entrar al nodo por terminal
Desde su equipo, conéctese por SSH al servidor donde instalará ChileMon:

```bash
ssh usuario@IP_DEL_NODO
```

## 2. Instalar Git si no está disponible
Ya dentro del nodo, ejecute:

```bash
sudo apt update
sudo apt install -y git
```

## 3. Instalación de ChileMon (Automatizada)
Este paso clona la versión estable y ejecuta el instalador de una sola vez:

```bash
# Entrar a /opt, clonar la versión estable e instalar
sudo git clone -b v0.1.0 https://github.com/gismodes37/Chilemon.git /opt/chilemon
cd /opt/chilemon && sudo bash install/install_chilemon.sh
```

## 6. Responder las preguntas del instalador
El script pedirá dos datos:

- **Nodo ASL local**: el número del nodo instalado en ese servidor.
- **IP o nombre del servidor**: para construir la URL final del dashboard.

Ejemplo:

- Nodo local: `12345`
- Servidor: `192.168.1.20`

## 7. Esperar la instalación completa
El instalador mostrará pasos numerados. Entre ellos:

- validación del repositorio
- instalación de dependencias
- generación de `config/local.php`
- instalación del wrapper `chilemon-rpt` (v0.3.0 con soporte EchoLink y Favoritos)
- configuración de sudoers
- configuración de Apache
- inicialización de ChileMon con PHP y SQLite

## 8. Abrir ChileMon en el navegador
Al finalizar verá una URL como esta:

```text
http://192.168.1.20/chilemon
```

Abra esa dirección en el navegador de un equipo de la misma red.

## 9. Hacer pruebas rápidas desde la terminal
Para validar que el wrapper quedó operativo:

```bash
sudo -u www-data sudo -n /usr/local/bin/chilemon-rpt nodes 12345
```

Si desea repetir la inicialización PHP:

```bash
sudo -u www-data php /opt/chilemon/bin/install.php
```

## 10. Si el repositorio todavía no trae el instalador
Si aún no ha copiado estos archivos al repo local, primero agréguelo con el script de apoyo:

```bash
bash /ruta/donde-descargó/chilemon_local_updates/install/copiar_al_repo_local.sh /opt/chilemon
```

Después ejecute de nuevo:

```bash
cd /opt/chilemon
sudo bash install/install_chilemon.sh
```
