# Companion autostart via scheduled task
Branch: feat/companion-autostart-task
Delivery: work-unit commits; test runner `python -m pytest tests/companion/ -v` plus live Windows task/port checks.

## Specs

S1 — "seria lo mas correcto pero hay que implementarla en la rutina de inicio para que busque la tarea si existe se conecta si no existe la crea asi no importa en que maquina se instale siempre creara la tarea"
Authorized scope: the companion startup routine on Windows. Acceptance: startup is idempotent — an existing task is reused, a missing task is created; the task points to an absolute launcher path so it works on any machine; on success the companion is running and the local WS answers on 127.0.0.1:9093.

S2 — "sigo con el companion no detectado, si le doy a boton reconectar dice Buscando companion y se queda ahí, luego Companion no detectado"
Authorized scope: make the companion present after shutdown/reboot without manual starts. Acceptance: after logon, no manual `start-companion.bat` is needed; `/health` returns `ok` and the WS status reports the companion connected.

S3 — "por que pasa eso cada vez que se apaga o reinicia el sistema, algo no esta funcionando bien y no se que es"
Authorized scope: remove the silent-failure mode found in diagnosis. Acceptance: startup failures leave a diagnostic in `%USERPROFILE%\.chilemon\companion-startup.log` and a non-zero exit code; no unconditional `exit /b 0` hides a failed spawn.

## Tasks

| ID | S# | Route | Status | Commit |
| --- | --- | --- | --- | --- |
| T1 | S1, S3 | inline | done (10 autostart tests green) | 07f8b9f |
| T2 | S1, S2, S3 | inline | done (78 companion tests, 9 JS tests green) | 07f8b9f |
| T3 | S1, S2 | inline | done (live cold start + idempotency + WS verified) | 5893e79 |

## Log

L1 — "seria lo mas correcto pero hay que implementarla en la rutina de inicio para que busque la tarea si existe se conecta si no existe la crea asi no importa en que maquina se instale siempre creara la tarea"

L2 — Diagnosis evidence (2026-11-21): Windows boot 10:51:02, Explorer 10:51:11; first companion log line 12:05:32 (manual start). `ChileMon Companion.lnk` exists, is enabled, and targets `start-companion.bat --autostart`, but left no execution trace. No Scheduled Task and no `Run` entry existed as fallback. The log ended abruptly at 12:15:11 without a traceback, so the process was terminated rather than crashing. Conclusion: the failure is Windows autostart, not dashboard reconnection; `Reconectar` only probes the local WS.

L3 — Existing launcher risk confirmed: `start /min python ...` followed by `exit /b 0` hides Python-not-found, import errors and log-path failures. `python` was resolved through PATH only. Repository has no Windows installer that creates the login autostart; the shortcut was a manual per-user step documented in `docs/companion.md` and `docs/companion_ES.md`.

L4 — Environment constraint found during live test: `schtasks /create` and `Register-ScheduledTask` both return "Acceso denegado" for the non-admin user `GUILLERMO-2025\gismo`, in the root task folder and in `\ChileMon`, with and without `/ru`. `HKCU\...\Run` writes succeed without elevation. `install-autostart.bat` therefore registers a logon task when permitted and falls back to an idempotent per-user `Run` entry otherwise; the value points to `autostart-companion.bat` with an absolute path. Live check: first run created the entry, second run reported "already exists", `reg query` showed `"C:\www\chilemon\companion\autostart-companion.bat"`.

L5 — First cold-start run exposed a false failure: `start` returned a stale non-zero ERRORLEVEL even though the Python child launched and `/health` answered `ok`. Removed that check; the authoritative readiness signal is now the 10-attempt port-9093 probe. Also redirected the child stdout to `nul` so the spawning shell does not wait on an inherited handle.

L6 — Checks: 78 pytest tests in tests/companion, 9 Node tests in tests/js, `python -m compileall -q companion`, `git diff --check` all green. Native RDD assess with the canonical untracked inventory: 07f8b9f medium / `under_budget` (executable_change on autostart-companion.bat), 5893e79 passive; no native review lifecycle is due, so no consent prompt is opened. The review boundary stays at the branch point 9cb3e7d.

L7 — Final live proof, launched detached via `Start-Process cmd /c autostart-companion.bat` to mimic Explorer: process `"C:\Python314\python.exe" -m companion.main --log-file ...`, listener on 127.0.0.1:9093, `/health` `{"status": "ok", "peers": 0}`, WS status `connected: true`. Second invocation while running exited 0 in 0 s with no output (idempotent, no `pause`). `reg query HKCU\...\Run /v "ChileMon Companion"` shows the absolute wrapper path. Earlier `[ERROR] schtasks /create failed` lines in companion-startup.log came from the pre-fallback revision; the committed script no longer writes them. Untracked by design: odd/tasks/installer-security-hardening.md and public/api/log-call.php.

L8 — Pushed `07f8b9f` and `5893e79` to `origin/feat/companion-autostart-task` with `-u`; remote HEAD `5893e79` matches local HEAD. The deliberately untracked `odd/` and `public/api/log-call.php` were not added. No PR was opened (user decision). The companion is currently stopped with no listener; its log ends without a traceback, consistent with the earlier shell interruptions rather than a crash. A reboot shutdown test is pending from the user.
