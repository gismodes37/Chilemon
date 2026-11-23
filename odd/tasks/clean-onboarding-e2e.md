# Clean onboarding E2E (new Pi + clean PC)

Objective: make `main` the installable default, fix the README step-by-step for a non-technical user, provide a Windows companion clean-uninstall `.bat`, and run a full clean E2E test on the new Raspberry Pi and the PC. Branch: `main` (work lands as reviewed work-unit commits and is pushed; `main` is the permanent download/install point). Delivery: commits `513c273`, `ac53712`, `2097dc3`, `82ddd50`, `595dcff`, `18ecbbc`, `029a659`, `0a9c769`. Runners: `python -m pytest tests/ -q`, `node tests/js/*.test.js`, `bash -n install/install_chilemon.sh`, `git diff --check`, plus live Pi and live Windows verification.

## Specs

S1 — "actualicemos la rama main como principal y los ajustes necesarios al readme si los necesita, asi probamos la rasspberry pi nueva"

S2 — "seria interesante confecionar un archivo .bat que desinstale y limpie el companion del PC, asi realizamos un prueba totalmente limpia como si fuera otra maquina y si hay fallos ver como soluciorlos para una segunda prueba real en vivo"

S3 — "dime si el instalador esta preparado como para descargar todo lo necesario del repo, hagamos una instalacion real como si fuera cualquier usuario"

S4 — "revisa el readme ingles y español y fijate si el paso a paso es correcto, recuerda que debe de ser para un no tecnico facil de seguir"

S5 — "Bien la nueva PI es la siguiente : IP: 192.168.0.110, user: stg" — remote target authorized for this feature; the credential is deliberately NOT stored in this document or in memory artifacts.

S6 — Both READMEs must describe the real installer: `git` prerequisite (or one-command bootstrap), 14 steps in the real order, generated WebRTC secrets, companion one-action onboarding, and the AMI password requirement with a clear non-technical explanation.

S7 — The Windows clean-uninstall `.bat` must stop the companion, remove logon autostart (task and HKCU Run entry), kill the process listening on 9093, remove companion config/logs/cache under `%USERPROFILE%\.chilemon`, and be idempotent with real exit codes. It must not delete repository source files.

S8 — "hay que considerar que los cambios deven servir para esta nueva version de ASL como para la anterior" — module loading and config detection must work on Debian 13 / ASL3 3.10.8 (Asterisk 22) and on older ASL3 releases.

S9 — Live PTT opened the audio channel but caused a constant hum on the remote node. With the node in `duplex=0` (VOX), any mic frame on the IAX2 channel keys the transmitter; mic audio was forwarded while PTT was released and WS disconnect left PTT keyed. PTT must be a strict transmit gate in both the companion and browser bridge paths, releasing TX with DTMF `#` on unkey and on last-WS disconnect.

S10 — "guarde un favorito y al darle conectar me dio un bad request" — saving a favorite (HTTP 200) then connecting from it returned HTTP 400 because `Auth::validateCsrf()` rotated the session token on every validation while the dashboard reuses the token read once from `<meta name="csrf-token">`.

S11 — "cerre la secion y al intentar reentrar no me reconoce las credenciales" — five failed admin logins from 192.168.0.106 within 10 minutes tripped `MAX_ATTEMPTS` and locked the account. The generated web password is in the install log; access was restored by clearing `login_attempts` and verified with a real HTTP 302 login.

S12 — "registre el favorito pero el boton de conectar no funciona" — saving the favorite returned HTTP 200 but clicking its Conectar button sent NO POST to api/connect.php. Root cause: the favorites connect handler was the only connect path gated by a native `confirm()` inside a Bootstrap modal; in the live Firefox session it was dismissed/blocked. Fix: connect directly on click (matching header/node-table paths) with visible loading state. Also fixed `connect.php`/`disconnect.php` writing `node_events` with non-existent columns `node_id`/`username` (real schema: `node_number`/`details`).

S13 — User wants a full clean-install test report: what worked, what failed, and every detail that keeps the install from being called successful. Tests will be repeated as needed until all details are fixed. Yesterday's TX/RX achievement is confirmed; remaining work is detail adjustments.

## Tasks

| ID | S# | Route | Status | Commit |
| --- | --- | --- | --- | --- |
| T1 | S1, S4, S6 | inline | done | `513c273`, `ac53712`, `2097dc3` |
| T2 | S3, S6 | inline | done | `513c273`, `82ddd50` |
| T3 | S2, S7 | inline | done | `2097dc3` |
| T4 | S1, S3, S5 | inline (remote) | done | `82ddd50`, `595dcff`, `18ecbbc`, `029a659` |
| T5 | S2, S5 | inline (remote) | done | verified on 2026-11-22, no code change |
| T6 | S8 | inline | done | `18ecbbc`, `029a659` |
| T7 | S9 | inline | done | `9d9ee49` — PTT gate, `#` force-unkey, WS-disconnect unkey, EN/ES docs |
| T8 | S10, S11 | inline (remote) | done | `0fb2783` — stable CSRF token; Pi patched and verified; access restored |
| T9 | S12 | inline (remote) | done | `c14ac22` — favorites connect without confirm; node_events schema fixed; Pi patched and E2E verified |

## Log

L1 — "actualicemos la rama main como principal y los ajustes necesarios al readme si los necesita, asi probamos la rasspberry pi nueva, seria interesante confecionar un archivo .bat que desinstale y limpie el companion del PC, asi realizamos un prueba totalmente limpia como si fuera otra maquina y si hay fallos ver como soluciorlos para una segunda prueba real en vivo"

L2 — 2026-11-22. Verified before starting: `main` was merged fast-forward from `feat/companion-autostart-task` (`9cb3e7d..905dbba`) and pushed; `git ls-remote` confirms remote `main` = `905dbba`. Remote Pi baseline: host `node494780`, Debian/Python 3.13, Asterisk active with node 494780 in `rpt.conf`, `/opt` empty, no git/apache/chilemon installed, `/etc/asterisk/manager.conf` has `[general]` and `[admin]` with a secret. Credential supplied by the user for this host is intentionally omitted from this document and all memory artifacts. New branch created for the remaining work.

L3 — 2026-11-22. Pre-existing findings to address in T1/T2: README EN/ES say 13 steps while `install_chilemon.sh:43` sets `TOTAL_STEPS=14`; `sudo git clone` fails on the fresh Pi because git is absent (`install/bootstrap.sh` installs it but is undocumented); `validate_php_modules` requires `mbstring` (`:170`) without installing `php-mbstring` in step 2 (`:1177-1185`); `install_webrtc_bridge` runs global `pip3 install aiortc` (`:1036-1040`) which is PEP 668-sensitive on Debian 13; the "configure secrets afterwards" note is stale after T4's `ensure_webrtc_secrets`; Options 1/2 duplicate each other and Option 3 pins the older `v0.4.0` tag.

L4 — 2026-11-22. Previous user request in this session, verbatim: "dime si el instalador esta preparado como para descargar todo lo necesario del repo, hagamos una instalacion real como si fuera cualquier usuario / revisa el readme ingles y español y fijate si el paso a paso es correcto, recuerda que debe de ser para un no tecnico facil de seguir". Same session: user answered "Sí, subilos a main (Recomendado)" to the explicit question about publishing the four local commits to `main`; that authorization was executed as the push in L2.

L5 — 2026-11-22. First clean Pi install from remote `main` (`2097dc3`) completed with `INSTALL_EXIT=0` but exposed four live defects: Debian 13 `cryptography 43.0.0` without `RECORD` broke pip uninstall; Python 3.13 removed `audioop`; generated `/etc/default/chilemon-webrtc` lacked `AMI_PASS`; final banner printed unexpanded `${WEBRTC_PORT}` and forced `https`. Fixed in `82ddd50` (`--ignore-installed`, `audioop-lts`, `escape_env_value()` with AMI credentials, honest banner). Local checks: focused `pytest` 7 passed, `bash -n` exit 0.

L6 — 2026-11-22. Second clean Pi install (`595dcff`) exposed step 14: non-TTY installs skipped first-admin creation while the banner claimed credentials were "definido durante instalación". Fixed in `595dcff` with `CHILEMON_ADMIN_USER` / `CHILEMON_ADMIN_PASS` env-driven creation in `bin/install.php`, generated strong password in the shell installer, and a truthful banner. `tests/install/admin_bootstrap_test.py` added (RED → GREEN); full suite 115 passed / 1 skipped.

L7 — 2026-11-22. User requirement added verbatim: "hay que considerar que los cambios deven servir para esta nueva version de ASL como para la anterior". Evidence: Debian 13 writes `autoload = no` (spaces) and keeps modules under `/usr/lib/aarch64-linux-gnu/asterisk/modules`; older ASL writes `autoload=no`. `18ecbbc` accepted both spellings, both `load = mod.so` and `load => mod`, and skipped modules without a `.so`; `029a659` added authoritative module-dir discovery via `asterisk -rx 'core show settings'` plus multiarch glob fallback. `tests/install/asl_modules_test.py` covers both layouts and the multiarch path. Also silenced ncurses `TERM=unknown` noise (`clear_screen`) and corrected the step-12 message that told users to create an admin manually.

L8 — 2026-11-22. Final clean Pi install from remote `main` (`029a659`): `INSTALL_EXIT=0`, `UNKNOWN_MSG_COUNT=0`, `STEP14_USER=1`, `ASL_DETECTED=1`, 8 valid `load =>` lines, admin row `admin|admin`, `AMI_PASS` present in env file, `chilemon-webrtc`/`apache2`/`asterisk` all `active`, `/health` returned `{"status":"ok","ami_connected":true,"iax_server_running":true}` after retry. Asterisk 22.10.1+asl3-3.10.8 restarted successfully with the added modules (`res_http_websocket.so` Running). UPDATE-mode rerun: `UPDATE_EXIT=0`, mode `update`, banner `existente (sin cambios)` — idempotent.

L9 — 2026-11-22. Live Windows verification: `uninstall-companion.bat` → exit 0, port 9093 free, HKCU Run value absent, Startup shortcut absent, `%USERPROFILE%\.chilemon` absent. Dashboard `config.toml` downloaded from `https://192.168.0.110/chilemon/api/companion-download.php` after real login with CSRF (`host = "192.168.0.110"`). `install-companion.bat <config-path>` → exit 0, config installed, Run key created, listener `127.0.0.1:9093 LISTENING`, WebSocket handshake on `/ws` succeeded.

L10 — 2026-11-22. Final local checks: `python -m pytest tests/ -q` → 123 passed, 1 skipped; `bash -n install/install_chilemon.sh` → 0; `git diff --check` → 0. Native RDD review of the earlier candidate `82ddd50` was granted by the user but all reviewer transports failed with `opencode_review_transport_relay_refused (provider_failed)`; no PASS was invented and no authority was burned. Review remains blocked by the provider transport, not by the change.

L11 — 2026-11-22. Next step: optional real PTT audio test from the PC to node 494780; native review retry only when the reviewer transport works. Push/PR decisions remain ordinary repository policy; all authorized pushes are already on `main`.

L12 — 2026-11-22. Live zumbido root cause: node 494780 has `duplex=0`, so VOX keys TX from any audio on the channel. Companion `send_audio()` ignored `ptt_active`, `TX_REST_S=120s` left the mic hot, and WS disconnect left PTT keyed (bridge grace period also skipped when `_ptt_active`). Fix: `companion/session.py` drops audio unless `_in_call and ptt_active`, `ptt_unkey()` and WS-disconnect send DTMF `#` (force TX off, not `*` toggle); `app/Services/WebRTCBridge/server.py` gates `audio_tx`, unkeys with `#`, and unkeys before the last-WS grace period. Tests in `tests/companion/ptt_audio_gate_test.py` (RED→GREEN, 8 passed). Patched live on Pi (`chilemon-webrtc` active, `/health` ok, `ptt_active=false`) and restarted the PC companion (PID 20852, port 9093). No live TX retest performed to the remote node.

L13 — 2026-11-22. User reported "guarde un favorito y al darle conectar me dio un bad request": Apache access log confirmed `favorites/save.php` 200 at 20:12:50 then `connect.php` 400 at 20:12:57. Root cause: `Auth::validateCsrf()` rotated the token after the first valid POST while the dashboard kept the old `<meta>` value. Fix: token stays stable for the session (rotation still happens in `login.php` and `logout.php`). RED test added in `tests/Auth/AuthTest.php`; local PHPUnit unavailable (no PHP on Windows), so verified on the Pi: original code `FIRST=1 STABLE=0 SECOND=0`, patched code `FIRST=1 STABLE=1 SECOND=1`, `php -l` OK. Real E2E on the Pi: login 302 → save favorite (same token) 200 → connect (same token) 200. Backups `.bak-csrf` and `.bak-ptt-gate` left on the Pi.

L14 — 2026-11-22. User reported "cerre la secion y al intentar reentrar no me reconoce las credenciales": `login_attempts` held 5 failed `admin` rows from 192.168.0.106 at 23:14–23:15 UTC (10-min window). The generated web password from `/home/stg/clean-install-5.log` verified with a real login. Cleared `login_attempts` (5 rows) on the Pi; HTTP 302 to `/index.php` confirmed restored access. E2E test favorite `54614|Remoto|Prueba E2E` was left in the DB — overwrite of the user's own favorite, to be confirmed/restored with them.

L15 — 2026-11-22. Full checks after all fixes: `python -m pytest tests/ -q` → 134 passed, 1 skipped; `node tests/js/dashboard-connect.test.js` → 4 passed; `node tests/js/ptt-reconnect.test.js` → 7 passed; `node tests/js/dashboard-update.test.js` → 3 passed; `git diff --check` → 0. Native RDD review of `0a9c769` still has no PASS (reviewer transport failures); no authority burned.

L16 — 2026-11-22. User: "registre el favorito pero el boton de conectar no funciona". Apache log: favorites/save.php 200 at 20:38:29, favorites/list.php 200 at 20:38:31, but NO POST to connect.php. JS hash on Pi matched local, list endpoint returned the item, and a VM test proved the handler works when bound — the only difference from the working header/node-table connect paths was a native `confirm()` gating the favorites button. Removed it, added `setButtonLoading` feedback; new regression `tests/js/favorites-connect.test.js` passes with confirm() forced to false. Also found `connect.php`/`disconnect.php` INSERTing into `node_events(node_id, username)` while the real schema is `node_number, details` — every connect logged a SQLite error. Fixed both to the real schema. Deployed all three files to Pi with `.bak-favconnect` backups; php -l OK. Live E2E as stg: login 302 → connect 200 → `node_events` row `54614|connect|user=stg` → disconnect 200 → `54614|disconnect|user=stg`, zero SQLite errors; probe rows deleted.

L17 — 2026-11-22. User requested a full installation-test report (what worked / what failed / remaining details) and confirmed the loop: repeat the clean install test until all details are fixed. Yesterday's TX/RX success is acknowledged; remaining work is detail adjustments only.

L18 — 2026-11-23. User chose to start today with the commits and added verbatim: "vamos primero con el numero 1. y dejemos completamente actualizado la rama main, esa sera siempre el punto de descarga e instalacion de chilemon". This explicitly authorizes work-unit commits on `main` and a push to `main`; `main` stays the permanent download/install point. No live TX to the remote node until separately authorized.
