# ChileMon Companion — instalación, funcionamiento y seguridad

El Companion es una pequeña aplicación Python que se ejecuta en la PC del operador. Proporciona al navegador un WebSocket local para PTT y niveles de audio, además de un enlace IAX2 con Asterisk en la Raspberry Pi. Esta guía explica qué se instala y dónde, cómo se conectan las piezas, qué supuestos de seguridad se aplican y si el botón **Companion App** del panel puede servir como instalador para otros usuarios.

> Estado: actualizado para el inicio automático idempotente en la rama `feat/companion-autostart-task`. Los elementos marcados como **Verificar** presentan contradicciones en el repositorio y deben confirmarse en la Pi antes de considerarlos válidos.

## Ruta rápida (una sola acción en Windows)

1. En la Pi, ejecutar `install/install_chilemon.sh`. El script genera un secreto IAX2 aleatorio y lo escribe en `config/local.php`.
2. En el panel, presionar **Companion App**. El `chilemon-companion-config.toml` descargado ya contiene la IP de la Pi y ese secreto.
3. En la PC con Windows, hacer doble clic en `companion/install-companion.bat`. Instala el config en `%USERPROFILE%\.chilemon\config.toml`, registra el inicio automático y arranca el companion. Requiere Python 3.11+; cada paso tiene su propio log y devuelve un código de salida real.
4. En el panel, confirmar **Companion Connected** y luego presionar **Reconectar** para abrir el canal IAX2. El PTT sigue siendo explícito: conectá un nodo antes de transmitir.

## Qué es el Companion

| Pieza | Función |
| --- | --- |
| `companion/main.py` | Conecta los callbacks de audio, sesión IAX2 y WebSocket. Inicia en orden WS → audio → IAX2. |
| `companion/session.py` | Ciclo de vida de la llamada IAX2: `place_call()`, corte, DTMF y vigilancia de RX. |
| `companion/audio.py` | Captura y reproducción con PortAudio, códec µ-law, medición de niveles, resolución dinámica de dispositivos y controles de salud. |
| `companion/ws_server.py` | Servidor HTTP/WS local para el navegador. |
| `companion/iax2.py` | Transporte IAX2, incluida la respuesta AUTHREP. |

El navegador nunca habla directamente con Asterisk. Solo se comunica con `ws://127.0.0.1:9093/ws`; el Companion es el dueño de la conexión UDP/4569 hacia la Pi.

## Instalación

### PC con Windows

**No existe un instalador dentro del repositorio para Windows.** `install/companion/build.bat` verifica Python y PyInstaller, instala `sounddevice`, `aiohttp` y `numpy`, y compila un único archivo con:

```bat
pyinstaller --onefile --name chilemon-companion ...
```

Luego muestra instrucciones manuales: copiar `dist\chilemon-companion.exe` a la ubicación deseada, crear `%USERPROFILE%\.chilemon\config.toml` manualmente y ejecutar el exe con `--config` si el archivo no está en la ruta por defecto. SmartScreen puede requerir una omisión manual porque el binario no está firmado.

Si se trabaja desde un checkout del código fuente, el instalador de una sola acción y los scripts de arranque están en el repositorio:

- `companion/install-companion.bat` — punto de entrada de la instalación. Verifica Python, llama a `python -m companion.install_config` para encontrar/validar la descarga del panel e instalarla en la ruta por defecto, y luego reutiliza `install-autostart.bat` y `start-companion.bat`. Es idempotente, registra en `%USERPROFILE%\.chilemon\companion-install.log` y propaga los fallos. `--config PATH` usa un config explícito; `--config-only` se detiene antes del autostart y el arranque.
- `companion/install_config.py` — búsqueda, validación y colocación del config. Rechaza un `[peer].password` vacío (el descubrimiento automático aprende la IP de la Pi pero nunca inventa el secreto) y escribe el config con permisos 0600 en POSIX.
- `companion/start-companion.bat` — cambia al directorio del repositorio, verifica si el puerto 9093 ya está en uso, instala el inicio automático en la primera ejecución manual, resuelve una ruta absoluta a Python y ejecuta `python -m companion.main` minimizado. Un fallo de arranque se escribe en `%USERPROFILE%\.chilemon\companion-startup.log` y devuelve un código de salida distinto de cero.
- `companion/autostart-companion.bat` — objetivo de inicio de sesión sin argumentos; llama a `start-companion.bat --autostart`.
- `companion/install-autostart.bat` — registro idempotente: consulta la tarea `ChileMon Companion` y la crea si falta. Si Windows no permite crear tareas a un usuario estándar, consulta/crea la entrada equivalente en `HKCU\...\Run`.
- `companion/stop-companion.bat` — busca el PID que escucha en 9093 y lo termina.
- `install/companion/install-firewall.ps1` — regla de entrada UDP opcional, limitada a la IP de la Pi si se indica.

El orden de búsqueda de la configuración es `--config`, luego `~/.chilemon/config.toml` y finalmente `companion/config.toml` del repositorio (`main.py:40-51`). El descubrimiento automático puede reescribir `~/.chilemon/config.toml` con permisos `0600` (`main.py:287-339`).

**Inicio automático al iniciar sesión.** Ejecutar `start-companion.bat` una vez (o `install-autostart.bat` directamente) registra `ChileMon Companion` para cada inicio de sesión. La rutina es idempotente: consulta el registro existente y solo lo crea si falta. Se prefiere el Programador de tareas de Windows; cuando el usuario no puede crear tareas, la rutina usa como respaldo una entrada `HKCU\Software\Microsoft\Windows\CurrentVersion\Run` que apunta a `autostart-companion.bat` con una ruta absoluta. Ambos caminos llaman a `start-companion.bat --autostart`, donde la bandera solo evita el aviso de "ya está corriendo". Un acceso directo previo en Startup es inofensivo: si 9093 ya está escuchando, el objetivo de autostart sale silenciosamente.

### Raspberry Pi (lado servidor)

`install/install_chilemon.sh` realiza la configuración principal:

- Genera `iax_companion_secret` con `openssl rand -hex 24` y lo guarda en `config/local.php` (permisos `640`).
- Detecta la CIDR de la red local, con `127.0.0.1/32` como respaldo si no puede determinarla.
- Escribe el peer `[companion-app]` desde `install/asterisk/iax-companion.conf`, sustituyendo el secreto y la ACL de la red local.
- Restringe el peer con `deny=0.0.0.0/0.0.0.0` más `permit=<LAN>` y un `calltokenoptional` limitado a la subred.
- Instala el contexto de dialplan `[radio-companion]` con el número de nodo ingresado durante la instalación, desde `install/asterisk/extensions-companion.conf`.
- Concede a `www-data` sudo sin contraseña solo para `bin/chilemon-rpt`, que permite `nodes`, `stats`, `connect`, `disconnect` y `git-*`. Los verbos destructivos están ausentes.

El contexto `[radio-companion]` lo instala el propio instalador de la Pi y es idempotente: una segunda ejecución preserva el contexto existente.

### Alternativa con PC Linux

`install/companion/install.sh` instala en `/opt/chilemon/companion`, escribe `/etc/chilemon-companion/config.toml` con permisos `600`, crea un servicio systemd `chilemon-companion` sin privilegios, inyecta el peer en Asterisk e instala el contexto `[radio-companion]`. Se configura con `CHILEMON_IAX_SECRET`, `CHILEMON_LOCAL_NODE`, `CHILEMON_PI_HOST` y `CHILEMON_LAN_ACL`.

## Funcionamiento

### Endpoints y mensajes

| Dirección | Transporte | Detalles |
| --- | --- | --- |
| Navegador → Companion | WS `/ws` | `ptt` (`key`/`unkey`), `dtmf`, `call` (`node` o `action:hangup`), `status` |
| Companion → navegador | WS `/ws` | `status`, `audio_level` (`rms`, cuatro bandas del espectro), `error` |
| Prueba de salud | HTTP `GET /health` | `{"status":"ok","peers":N}` |

El WebSocket escucha en `127.0.0.1:9093` por defectro. IAX2 usa UDP `4569` hacia el host de la Pi configurado.

### Ciclo de la llamada

`place_call()` es idempotente: primero corta cualquier llamada existente y deriva el identificador de llamada a partir de los dígitos del nodo local. El corte envía primero DTMF `#` y llama a `audio.reset()` para que no se reproduzca audio pendiente después de finalizar. `CALL_IDLE_TIMEOUT = 60.0` segundos provoca el corte automático cuando no llega audio de RX; la verificación se ejecuta cada cinco segundos. Por eso es normal que, tras recargar la página, el WS esté sano pero no haya llamada activa: presionar **Reconectar** abre el canal explícitamente. Las reconexiones automáticas del WS nunca vuelven a abrir la emisión hacia el repetidor.

### Audio y dispositivos

La frecuencia de muestreo es 8 kHz con bloques de 160 muestras (20 ms). Si `input_device`/`output_device` están vacíos o no coinciden, se sigue el dispositivo real por defecto del sistema; un ID numérico o un fragmento del nombre fija un dispositivo concreto. Use `python -m companion.main --list-devices` para enumerarlos.

`audio_level` se calcula en cada callback del micrófono y se envía al bucle de eventos con `run_coroutine_threadsafe`. Un dispositivo puede permanecer abierto y seguir disparando callbacks mientras entrega silencio después de que Windows cambie su entrada por defecto. `check_health()` se ejecuta cada dos segundos y vuelve a abrir los streams cuando:

- los IDs de dispositivo recién resueltos difieren de los capturados al abrir los streams, o
- el RMS de entrada permanece por debajo de `0.0005` durante 15 segundos, o
- un callback de entrada o salida se detiene durante más de tres segundos.

Un periodo de gracia de 30 segundos evita un bucle de reinicios cuando el dispositivo sigue fallando. Esto eliminó la necesidad de fijar un nombre de dispositivo.

### Modo reposo del micrófono

El stream de entrada permanece abierto, pero los frames IAX2 dejan de enviarse después de 120 segundos sin voz (`TX_REST_S`). Hablar durante tres frames consecutivos de 20 ms por encima de `0.01` de RMS, o apretar PTT, despierta el micrófono. Al despertar se envía primero un pre-roll de 200 ms para no perder la primera sílaba. Mientras está en reposo se suspende la verificación de silencio de 15 segundos —el silencio es intencional—, pero se mantienen la detección de cambio de dispositivo y de callback detenido.

El reposo **no** keya el transmisor: el PTT sigue siendo un toggle DTMF `*` explícito desde el panel. El estado se muestra como `Mic en reposo - listo para transmitir`; es un estado normal de disponibilidad, no un error. Todos los umbrales se configuran en `[tx]` de `config.toml`.

Para un usuario no técnico, `start-companion.bat` ahora inicia la aplicación minimizada y escribe los diagnósticos en `%USERPROFILE%\.chilemon\companion.log` en lugar de mostrar una terminal con texto desplazándose. Los fallos anteriores a la configuración del logging se capturan en `companion-startup.log`.

## Evaluación de seguridad

No se distribuye ningún secreto: `companion/config.toml` tiene `password = ""` y `config/app.php` falla de forma segura cuando falta `IAX_COMPANION_SECRET`. Los tests rechazan el valor obsoleto `chilemon2026`. ese literal todavía aparece en scripts de prueba heredados de WebRTCBridge, pero corresponden a credenciales AMI, no al peer del companion.

| Área | Evaluación |
| --- | --- |
| Peer IAX2 | Secreto aleatorio por instalación, ACL limitada a la red local, `deny` general por defecto. Respaldo a loopback cuando no se detecta la red local. |
| WebSocket local | Escucha en loopback, pero establece `Access-Control-Allow-Origin: *` y **no tiene autenticación ni verificación de origen**. Cualquier página abierta en el navegador del operador puede enviar `ptt`, `call` o `hangup` a `127.0.0.1:9093`. Es la principal exposición local. |
| Entrega del secreto | `companion-download.php` exige una sesión iniciada y devuelve HTTP 503 cuando no hay secreto configurado, pero no aplica limitación de tasa, CSRF ni verificación de rol de administrador. La respuesta contiene el secreto IAX2 compartido en TOML sin cifrar. |
| Límite de sudo | `www-data` solo puede ejecutar el wrapper permitido `chilemon-rpt`. `system_action.php` rechaza todas las acciones. |
| Inicio automático | Se ejecuta con los permisos del usuario al iniciar sesión, no eleva privilegios, pero deja el WS local disponible durante toda la sesión. Se registra como tarea de inicio de sesión cuando se permite; si no, como entrada `Run` del usuario. |

**Verificar:** `install/asterisk/iax-companion.conf` usa `auth=md5` y espera AUTHREP; `docs/HISTORIA_AUDIO_END_TO_END.md` concluyó en su momento que se requería `auth=none`. El `iax2.py` actual implementa AUTHREP, así que conviene confirmar la configuración real del peer en la Pi antes de modificarla.

## ¿Puede el botón "Companion App" instalarlo para futuros usuarios?

**No, no como instalador.** El botón (`dashboard.php:169-173`) llama a `downloadCompanion()` (`:1085-1107`), que descarga `api/companion-download.php` como `config.toml` y muestra una alerta con pasos manuales: obtener el exe desde GitHub, copiar la configuración, iniciar el companion y conectarse. El endpoint genera y devuelve **solamente** TOML. Su documentación y los encabezados iniciales todavía prometen un `.exe` incluido, pero esos encabezados se sobreescriben en `:90-92`.

Lo que sí aporta hoy es útil: entrega la IP correcta de la Pi, el secreto IAX2 y la configuración de audio/WS sin exponerlos en un archivo del repositorio. Por eso funciona bien como paso de **aprovisionamiento de configuración** para usuarios nuevos.

Para una instalación real con un solo clic haría falta:

1. Un ejecutable firmado y alojado que incluya o consiga del servidor la configuración generada.
2. Un agente local o un esquema de URI personalizado, porque un navegador no puede iniciar un proceso de Windows.
3. Endurecimiento del endpoint del secreto: limitación de tasa, verificación de rol de administrador y control de origen.
4. Eliminar la documentación y los encabezados obsoletos del `.exe` para que la interfaz no prometa lo que el endpoint no entrega.

## Lista de verificación

- [ ] `Get-Process python*` muestra un proceso del companion y `netstat -ano | findstr :9093` muestra un listener.
- [ ] `GET /health` devuelve `{"status":"ok"}`.
- [ ] El panel dice **Companion Connected**, no *Companion no detectado*. Ese mensaje significa que falló el handshake del WebSocket, es decir, no hay ningún proceso escuchando.
- [ ] Tras reiniciar, `schtasks /query /tn "ChileMon Companion"` o `reg query "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "ChileMon Companion"` muestra el registro de inicio automático, y el companion arranca sin una ventana esperando `pause`.
- [ ] El `status` del WS informa `connected: true` para IAX2; presionar **Reconectar** si `call_active: false`.
- [ ] El RMS de `audio_level` está claramente por encima de `0.0005`; un valor casi nulo con callbacks activos indica un dispositivo silencioso obsoleto, que `check_health()` debería reabrir.
- [ ] El secreto del peer en la Pi coincide con `config/local.php`; un secreto rotado rompe la autenticación sin aviso.

## Paso siguiente

Consulte `HISTORIA_AUDIO_END_TO_END.md` para el historial medido de la ruta de audio y `GUIA_RAPIDA_INSTALACION_CHILEMON.md` para la guía de instalación de la Pi. Antes de apoyarse en el apartado `auth=md5`, verifique el peer real en la Pi.
