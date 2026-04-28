# 🐳 Guía Rápida: Entorno Local (Docker)

Esta guía contiene los comandos esenciales para levantar, apagar y administrar tu entorno de desarrollo de ChileMon utilizando Docker Compose.

Todos los comandos deben ejecutarse desde la raíz del proyecto (`c:\www\chilemon` o equivalente).

---

## 1. Comandos de Ciclo de Vida (Subir y Bajar)

### Levantar el entorno
Arranca los contenedores en segundo plano (`-d`) construyendo la imagen si es necesario (`--build`).
```bash
docker-compose up -d --build
```
> **Nota:** Puedes omitir `--build` si ya construiste la imagen y solo quieres arrancar. (Ej: `docker-compose up -d`)

### Apagar el entorno
Detiene los contenedores y los elimina (no borra los datos de la base de datos).
```bash
docker-compose down
```

### Reiniciar el entorno
Útil si cambiaste configuraciones globales.
```bash
docker-compose restart
```

---

## 2. Inicialización de la Base de Datos
*Solo necesario la primera vez que levantas el contenedor o si borras la base de datos.*

**Crear las tablas SQLite:**
```bash
docker-compose exec chilemon php bin/install.php
```

**Crear el usuario administrador (admin):**
```bash
docker-compose exec chilemon php bin/create-user.php
```

---

## 3. Comandos Útiles de Monitoreo

### Ver los Logs del sistema (Apache/PHP)
Para ver qué está pasando por debajo o depurar errores de PHP en tiempo real:
```bash
docker-compose logs -f
```
*(Presiona `Ctrl + C` para salir de los logs)*

### Entrar al contenedor (Consola)
Si necesitas revisar archivos por dentro o ejecutar comandos manuales en el entorno simulado:
```bash
docker-compose exec chilemon bash
```
*(Escribe `exit` para salir de la consola del contenedor)*

---

## 4. Resetear el Entorno (Peligro)

Si algo se rompe y quieres empezar de cero (borra todo, incluyendo la base de datos creada):
```bash
docker-compose down -v
rm -f data/chilemon.sqlite
```
Luego vuelve a levantar el entorno e inicializa la base de datos.
