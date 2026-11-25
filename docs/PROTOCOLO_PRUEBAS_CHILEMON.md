# Protocolo de pruebas — ChileMon (instalación limpia + audio I/O)

> Objetivo: repetir una prueba de instalación y audio de forma determinista, sin que una configuración heredada invalide el resultado.
>
> Regla de oro: **todo se instala desde `main`**. No se editan `config/local.php`, `iax.conf` ni el `config.toml` a mano. Si la prueba falla, se corrige en el repositorio, se publica en `main` y se repite desde la fase 0.

## Por qué se desinstala el Companion primero

Una instalación NUEVA genera un `iax_companion_secret` nuevo y lo escribe en `config/local.php`, `/etc/asterisk/iax.conf` y en el `config.toml` que descarga el dashboard. Si la PC conserva el `config.toml` anterior, Asterisk rechaza el `AUTHREP`: la llamada muere con `Call setup timeout (no NEWACK)` y no hay RX/TX, aunque el dashboard siga mostrando **Companion Connected**. Un Companion heredado convierte cualquier prueba en un falso negativo.

---

## Fase 0 — Limpieza de la PC Windows

1. Descargar el desinstalador desde el dashboard: abrir **Settings → Herramientas → Desinstalar Companion** y guardar `chilemon-companion-uninstall.bat`. Ese `.bat` es autocontenido: baja el código desde `main`, muestra el plan con `--dry-run`, pide confirmación en la PC y recién después borra. Ejecutarlo como Administrador para que también se elimine la regla de firewall `ChileMon Companion IAX2`.

   Si ya tenés el código en la PC, el equivalente manual es previsualizar el plan (no cambia nada):

   ```
   cd /d "%USERPROFILE%\.chilemon\src"
   python -m companion.uninstall --dry-run
   ```

2. Desinstalar **completo** (sin `--keep-config`, porque la prueba exige config nueva):

   ```
   python -m companion.uninstall
   ```

   Responder `y` a la confirmación. También sirve el wrapper `%USERPROFILE%\.chilemon\src\companion\uninstall-companion.bat`, que reenvía los mismos flags. El desinstalador quita la tarea programada, la entrada `HKCU\...\Run` y el shortcut de Startup, borra todo `%USERPROFILE%\.chilemon` (config, logs, `src` y bytecode) y los staging `%TEMP%\chilemon-install-*`. Solo detiene procesos verificados como `-m companion.main`. La regla de firewall `ChileMon Companion IAX2` se elimina solo si corre como administrador. El éxito se confirma con:

   ```
   Companion fully removed.
   ```

3. Confirmar que no queda ni config ni listener:

   ```
   dir "%USERPROFILE%\.chilemon"
   netstat -ano | findstr ":9093" | findstr "LISTENING"
   ```

   Esperado: `%USERPROFILE%\.chilemon` ausente; `netstat` sin salida.

4. Anotar la hora de inicio de la prueba.

## Fase 1 — Instalación limpia en la Pi

1. Ejecutar **una sola vez** el comando oficial publicado en `README.md` / `README_ES.md`:

   ```
   curl -fsSL https://raw.githubusercontent.com/gismodes37/Chilemon/main/install/bootstrap.sh -o /tmp/bootstrap.sh && sudo apt-get update -qq && sudo apt-get install -y -qq tmux && tmux new-session -A -s chilemon 'sudo bash /tmp/bootstrap.sh'
   ```

2. Si se corta la conexión SSH, reanudar con `tmux attach -t chilemon`. No volver a lanzar el comando a medias.

3. Al terminar, verificar sin imprimir secretos:

   ```
   ls -l /opt/chilemon/config/local.php
   grep -c "iax_companion_secret" /opt/chilemon/config/local.php
   grep -c "^\[companion-app\]" /etc/asterisk/iax.conf
   git -C /opt/chilemon rev-parse --short HEAD
   ```

   Esperado: archivo presente con permisos `root:www-data 0640`; los dos `grep` devuelven al menos `1`; el commit coincide con la punta de `main`.

4. Anotar el commit y la hora de fin de instalación.

## Fase 2 — Instalación limpia del Companion

1. En el dashboard, presionar **Companion App** y descargar `chilemon-companion-install.bat`.
2. En Windows, hacer doble clic en el archivo descargado. El instalador baja el código desde `main`, valida la config, registra el inicio automático y arranca el companion.
3. Confirmar que el `config.toml` es nuevo y apunta al peer correcto, **sin mostrar la contraseña**:

   ```
   dir "%USERPROFILE%\.chilemon\config.toml"
   findstr /B "host =" "%USERPROFILE%\.chilemon\config.toml"
   findstr /B "username =" "%USERPROFILE%\.chilemon\config.toml"
   ```

   Esperado: fecha/hora de la prueba; `host` con la IP real de la Pi; `username = "companion-app"`.

## Fase 3 — Verificación de audio (criterio de éxito)

Marcar cada item solo con evidencia observada:

- [ ] `GET http://127.0.0.1:9093/health` devuelve `{"status":"ok"}`.
- [ ] `companion.log` muestra `IAX2 endpoint confirmed at <ip>:4569` (o `Pi moved from ... to ... — switching IAX2 endpoint` si cambió la IP).
- [ ] El dashboard muestra **Companion Connected** y se presiona **Reconectar**.
- [ ] `companion.log` muestra `Starting call to node <n>` y luego `Call to node <n> established`.
- [ ] Aparece `JitterBuffer: first frame received` y `JitterBuffer stats: received=N` con **N creciente**.
- [ ] El visualizador RX/TX del dashboard responde a la voz.
- [ ] Prueba real de radio: hablar con PTT y confirmar audio en el otro extremo y RX de vuelta.

> **Companion Connected por sí solo NO es éxito**: solo indica que el WebSocket local está arriba. El criterio de audio es `Call ... established` + `received` creciente + prueba de radio.

## Tabla de decisión rápida

| Síntoma en `companion.log` | Causa probable | Acción |
| --- | --- | --- |
| `AUTHREQ ... sending AUTHREP` y después `Call setup timeout (no NEWACK)` | Secreto viejo en el `config.toml` de la PC, o peer equivocado | Repetir la fase 2 y reiniciar el companion. No editar `iax.conf` a mano. |
| `No ChileMon Pi answered an IAX2 probe` | Pi apagada, red distinta o Asterisk caído | Verificar red y estado de Asterisk en la Pi; reanudar desde la fase 1. |
| `Pi moved from ... to ... — switching IAX2 endpoint` | La Pi cambió de IP | Comportamiento esperado; confirmar que luego llegue `Call ... established`. |
| `Call ... established` pero `received=0` | Dispositivo de audio, firewall o ruta de medios | Verificar dispositivo PortAudio y firewall según `docs/companion.md`. |
| `Companion no detectado` en el dashboard | No hay proceso escuchando en 9093 | Revisar el proceso y el log de arranque del companion. |

## Evidencia a reportar

1. Commit de `main` desplegado en la Pi.
2. Hora de inicio y fin de cada fase.
3. Resultado `PASS` / `FAIL` de cada item de la fase 3.
4. Líneas exactas de `companion.log` que justifican el veredicto — **nunca incluir secretos**.

Si cualquier fase falla, no aplicar parches locales: registrar el fallo con esta evidencia, corregirlo en el repositorio, publicarlo en `main` y repetir desde la fase 0.

## Referencias

- `README.md` / `README_ES.md` — comando oficial de instalación y nota de refresco del Companion.
- `docs/companion.md` / `docs/companion_ES.md` — funcionamiento, red y checklist de troubleshooting.
- `docs/HISTORIA_AUDIO_END_TO_END.md` — historial medido del camino de audio.
