# ChileMon Companion — instalación, funcionamiento y seguridad

El Companion es una pequeña aplicación Python que se ejecuta en la PC del operador. Proporciona al navegador un WebSocket local para PTT y niveles de audio, además de un enlace IAX2 con Asterisk en la Raspberry Pi. Esta guía explica qué se instala y dónde, cómo se conectan las piezas, qué supuestos de seguridad se aplican y si el botón **Companion App** del panel puede servir como instalador para otros usuarios.

> Estado: actualizado para `main` tras la detección dinámica del endpoint IAX2 y la verificación de audio en vivo. Los elementos marcados como **Verificar** presentan contradicciones en el repositorio y deben confirmarse en la Pi antes de considerarlos válidos.

## Ruta rápida (una sola acción en Windows)

1. En la Pi, ejecutar `install/install_chilemon.sh`. El script genera un secreto IAX2 aleatorio y lo escribe en `config/local.php`.
2. En el panel, presionar **Companion App**. El `chilemon-companion-install.bat` descargado ya contiene la dirección de la Pi como pista inicial y ese secreto (en base64); el Companion vuelve a verificar el endpoint IAX2 real al arrancar y después de un cambio de IP.
3. En la PC con Windows, hacer doble clic en ese archivo desde Descargas. Descarga el código del companion desde GitHub, instala `numpy`/`sounddevice`/`aiohttp`, valida la config con `companion.install_config`, registra el inicio automático y arranca el companion. Requiere Python 3.11+ e internet; el ref del código es `main` salvo que `CHILEMON_COMPANION_REF` fije otra rama o tag.
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

### Desinstalación

Cuando el usuario decide dejar de usar ChileMon, ejecuta `companion/uninstall-companion.bat` (o `python -m companion.uninstall`). Por defecto muestra el plan exacto, pide confirmación y luego quita la tarea de inicio de sesión, el valor `HKCU\...\Run`, el acceso directo de Startup, todo el directorio `%USERPROFILE%\.chilemon` (config, logs, `src` descargado y bytecode) y los directorios temporales `%TEMP%\chilemon-install-*`. Un proceso solo se detiene si su línea de comandos contiene `-m companion.main`; otra aplicación que escuche en el puerto 9093 se informa y se deja corriendo.

La regla de firewall de Windows `ChileMon Companion IAX2` se elimina cuando el desinstalador corre como administrador. Si no lo está, no falla: imprime el comando exacto `install\companion\install-firewall.ps1 -Remove` para ejecutar desde un PowerShell elevado.

Alternativas menos invasivas:

- `python -m companion.uninstall --dry-run` — muestra el plan exacto sin cambiar nada.
- `python -m companion.uninstall --keep-config` — conserva `config.toml` y los logs, y quita solo el código y el autostart. Útil para reinstalar conservando el secreto emparejado.
- `python -m companion.uninstall --yes` — omite la confirmación para automatizaciones.

Los paquetes pip instalados por usuario (como `numpy`, `sounddevice` y `aiohttp`) y el `.bat` descargado en Descargas se informan deliberadamente, pero no se borran: otra aplicación podría usarlos. El desinstalador es idempotente y termina con código distinto de cero si queda una entrada de autostart o un proceso del Companion escuchando.

### Raspberry Pi (lado servidor)

`install/install_chilemon.sh` realiza la configuración principal:

- Genera `iax_companion_secret` con `openssl rand -hex 24` y lo guarda en `config/local.php` (permisos `640`).
- El instalador `.bat` del dashboard descubre el host IAX2 desde la propia descarga: primero el `Host` que usó el navegador y después `SERVER_ADDR`. Escribe esa dirección como pista `host` y guarda el nombre mDNS del nodo como `node_host`; la plantilla `companion/config.toml` del repo deja ambos vacíos, así que un mismo instalador sirve para cualquier dirección con la que se llegue al nodo en funcionamiento.
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
| Navegador → Companion | WS `/ws` | `ptt` (`key`/`unkey`), `dtmf`, `call` (`node` o `action:hangup`), `status`, `host` (`hostname` usado para llegar al panel) |
| Companion → navegador | WS `/ws` | `status`, `audio_level` (`rms`, cuatro bandas del espectro), `error` |
| Prueba de salud | HTTP `GET /health` | `{"status":"ok","peers":N}` |

El WebSocket escucha en `127.0.0.1:9093` por defecto. IAX2 usa UDP `4569` hacia el host de la Pi detectado, no necesariamente el que quedó escrito en la configuración.

### Detección dinámica del endpoint

El `host` de `config.toml` es una pista, no un contrato. Un `sendto()` sobre un socket UDP nunca demostró alcanabilidad, así que una IP vieja podía quedar configurada para siempre mientras las llamadas fallaban con `Call setup timeout (no NEWACK)`. Al arrancar, al reconectar y al recibir el mensaje `host` del panel, el Companion resuelve candidatos y envía una sonda IAX2 REGREQ: usa la dirección que responde. Asterisk ignora PING de un peer desconocido pero responde REGREQ, y la sonda nunca abre una llamada.

Orden de candidatos: el host configurado, `[asterisk].node_host` (el nombre mDNS del nodo en la config generada), el hostname del panel y luego un escaneo acotado de la subred DHCP de la PC (`[discovery].subnet_prefix`, por defecto `/24`). No se fija ninguna IP de la Pi y no hace falta ningún daemon en la Pi. Un hostname nunca se confía por sí solo: en un nodo con varias interfaces se usa la IP que responde, evitando que DNS anuncie una secundaria mientras Asterisk responde desde la primaria. La respuesta se acepta desde la IP consultada o desde otra dirección de la misma `/24`, y se rechaza desde otras subredes para que la sonda no pueda redirigirse fuera de la LAN.

Un mensaje `host` que cambia realmente el endpoint también dispara una reconexión completa. `set_endpoint()` cierra el transporte UDP viejo; sin esa reconexión un Companion en vivo quedaría sin registrar hasta que apareciera otra falla.

**Verificar:** una host vieja debe producir `No IAX2 reply ... scanning local subnet` en `companion.log`, seguido de `IAX2 endpoint switched`, y `place_call()` debe informar `received > 0`. «Companion Connected» solo significa que el WebSocket local está arriba; la actividad de red del panel no prueba audio IAX2.

### Ciclo de la llamada

`place_call()` es idempotente: primero corta cualquier llamada existente y deriva el identificador de llamada a partir de los dígitos del nodo local. El corte envía primero DTMF `#` y llama a `audio.reset()` para que no se reproduzca audio pendiente después de finalizar. `CALL_IDLE_TIMEOUT = 60.0` segundos provoca el corte automático cuando no llega audio de RX; la verificación se ejecuta cada cinco segundos. Por eso es normal que, tras recargar la página, el WS esté sano pero no haya llamada activa: presionar **Reconectar** abre el canal explícitamente. Las reconexiones automáticas del WS nunca vuelven a abrir la emisión hacia el repetidor.

### Cortar la llamada vs des-enlazar el nodo

`linkAndCallNode()` en `public/assets/js/dashboard.js` hace dos acciones separadas: enlaza el nodo mediante `api/connect.php` y después abre la llamada IAX2 por el WebSocket local. Un mensaje WS `call` con `action: "hangup"` solo cierra el canal IAX2; **no** des-enlaza el nodo en `app_rpt`. Si el nodo sigue enlazado, su portadora o telemetría puede seguir generando audio que se escucha como un zumbido constante. Usá la acción de desconectar del panel al terminar una prueba.

El PTT sigue siendo una compuerta estricta: `send_audio()` descarta cada frame del mic salvo que haya una llamada y el PTT esté activo. Un zumbido con `ptt=false` es por lo tanto audio recibido/del nodo, no transmisión del Companion. Evidencia en vivo del 2026-11-24: tras el cambio de endpoint a `192.168.0.125`, una llamada a `54614` alcanzó `received=1911`, `played=1909`, `dropped=0`; una prueba posterior con el parrot `55553` alcanzó `frames_received=4770` con `ptt=false` y `frames_dropped=0`.

### Audio y dispositivos

La frecuencia de muestreo es 8 kHz con bloques de 160 muestras (20 ms). Si `input_device`/`output_device` están vacíos o no coinciden, se sigue el dispositivo real por defecto del sistema; un ID numérico o un fragmento del nombre fija un dispositivo concreto. Use `python -m companion.main --list-devices` para enumerarlos.

`audio_level` se calcula en cada callback del micrófono y se envía al bucle de eventos con `run_coroutine_threadsafe`. Un dispositivo puede permanecer abierto y seguir disparando callbacks mientras entrega silencio después de que Windows cambie su entrada por defecto. `check_health()` se ejecuta cada dos segundos y vuelve a abrir los streams cuando:

- los IDs de dispositivo recién resueltos difieren de los capturados al abrir los streams, o
- el RMS de entrada permanece por debajo de `0.0005` durante 15 segundos, o
- un callback de entrada o salida se detiene durante más de tres segundos.

Un periodo de gracia de 30 segundos evita un bucle de reinicios cuando el dispositivo sigue fallando. Esto eliminó la necesidad de fijar un nombre de dispositivo.

### Modo reposo del micrófono

El PTT es una compuerta de transmisión estricta. Los frames del micrófono llegan a IAX2 sólo mientras el PTT está apretado; al soltarlo se descartan todos los frames y se envía DTMF `#` para apagar el transmisor con fuerza. Cerrar la pestaña del panel o perder el WebSocket también suelta el PTT de inmediato. Esto importa en un nodo AllStar semidúplex (`duplex=0`): su VOX keya el transmisor con cualquier audio del canal, así que enviar frames del mic con el PTT suelto volvería a keyar el nodo y alimentaría un lazo parlante→mic que el remoto escucha como un zumbido constante.

El stream de entrada permanece abierto con el PTT suelto para que el visualizador siga funcionando, y el micrófono entra en reposo después de 120 segundos sin voz (`TX_REST_S`). Hablar durante tres frames consecutivos de 20 ms por encima de `0.01` de RMS actualiza el temporizador y el pre-roll de 200 ms, pero nunca transmite por sí solo: sólo lo hace un PTT explícito. Mientras está en reposo se suspende la verificación de silencio de 15 segundos —el silencio es intencional—, pero se mantienen la detección de cambio de dispositivo y de callback detenido.

El estado se muestra como `Mic en reposo - listo para transmitir`; es un estado normal de disponibilidad, no un error. Todos los umbrales se configuran en `[tx]` de `config.toml`. Usá auriculares: reproducir el audio recibido por un parlante al lado del micrófono todavía puede generar realimentación acústica mientras el PTT está apretado.

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

**Sí — como instalador de Windows de una sola acción.** El botón (`dashboard.php:169-173`) llama a `downloadCompanion()`, que descarga `api/companion-download.php` como `chilemon-companion-install.bat`. El endpoint lee la plantilla versionada `companion/installer-template.bat.tpl`, incrusta el TOML generado en base64 y el ref del repositorio configurado, y entrega bytes CRLF con nombre de archivo `.bat`.

En la PC, el instalador descarga el zip del repositorio desde GitHub, extrae solamente `companion/` y `app/Services/WebRTCBridge/iax2.py` en `%USERPROFILE%\.chilemon\src\`, instala `numpy`/`sounddevice`/`aiohttp` con pip, escribe la config en un archivo temporal y delega la validación y el armado al módulo ya testeado `companion.install_config`. Después reutiliza el idempotente `install-autostart.bat` y el `start-companion.bat` que verifica el puerto; no crea tareas ni escribe la clave Run por su cuenta.

Consideraciones pendientes:

1. La PC necesita internet para la descarga de GitHub y el pip install. Fijá `CHILEMON_COMPANION_REF` para hacer reproducible la revisión del código.
2. El instalador es un script batch simple, así que no hay binario firmado ni aviso de SmartScreen.
3. El endpoint requiere sesión, pero la limitación de tasa, el rol de administrador y el control de origen siguen siendo trabajo de endurecimiento separado.

## Lista de verificación

- [ ] `Get-Process python*` muestra un proceso del companion y `netstat -ano | findstr :9093` muestra un listener.
- [ ] `GET /health` devuelve `{"status":"ok"}`.
- [ ] El panel dice **Companion Connected**, no *Companion no detectado*. Ese mensaje significa que falló el handshake del WebSocket, es decir, no hay ningún proceso escuchando.
- [ ] Tras reiniciar, `schtasks /query /tn "ChileMon Companion"` o `reg query "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "ChileMon Companion"` muestra el registro de inicio automático, y el companion arranca sin una ventana esperando `pause`.
- [ ] El `status` del WS informa `connected: true` para IAX2; presionar **Reconectar** si `call_active: false`.
- [ ] Tras un cambio de IP de la Pi, `companion.log` muestra `IAX2 endpoint switched` y una llamada nueva informa `received > 0`; no confiar solo en «Companion Connected».
- [ ] Después de cortar la llamada IAX2, des-enlazar el nodo desde el panel para liberar la portadora; el `hangup` del WS no lo des-enlaza.
- [ ] El RMS de `audio_level` está claramente por encima de `0.0005`; un valor casi nulo con callbacks activos indica un dispositivo silencioso obsoleto, que `check_health()` debería reabrir.
- [ ] El secreto del peer en la Pi coincide con `config/local.php`; un secreto rotado rompe la autenticación sin aviso.

## Paso siguiente

Consulte `HISTORIA_AUDIO_END_TO_END.md` para el historial medido de la ruta de audio y `GUIA_RAPIDA_INSTALACION_CHILEMON.md` para la guía de instalación de la Pi. Antes de apoyarse en el apartado `auth=md5`, verifique el peer real en la Pi.
