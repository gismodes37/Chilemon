# ChileMon Companion — installation, runtime and security

The Companion is a small Python audio app that runs on the operator's PC. It gives the browser a local WebSocket for PTT and audio levels, and an IAX2 link to Asterisk on the Raspberry Pi. This guide explains what gets installed where, how the pieces connect, what security assumptions apply, and whether the dashboard's **Companion App** button can serve as an installer for other users.

> Status: updated for the idempotent logon autostart on branch `feat/companion-autostart-task`. Items marked **Verify** are contradictory in the repository and must be confirmed on the Pi before being treated as fact.

## Quick path (one action on Windows)

1. On the Pi, run `install/install_chilemon.sh`. It generates a random IAX2 secret and writes it to `config/local.php`.
2. In the dashboard, click **Companion App**. The downloaded `chilemon-companion-install.bat` already contains the Pi IP and that secret (base64-encoded).
3. On the Windows PC, double-click that file from Downloads. It downloads the companion source from GitHub, installs `numpy`/`sounddevice`/`aiohttp`, validates the config via `companion.install_config`, registers logon autostart and starts the companion. Python 3.11+ and internet access are required; the source ref is `main` unless `CHILEMON_COMPANION_REF` pins another branch or tag.
4. In the dashboard, confirm **Companion Connected**, then press **Reconectar** to open the IAX2 channel. PTT stays explicit: connect a node before keying.

## What the Companion is

| Piece | Role |
| --- | --- |
| `companion/main.py` | Wires audio, IAX2 session and WebSocket callbacks together. Starts WS → audio → IAX2. |
| `companion/session.py` | IAX2 call lifecycle: `place_call()`, hangup, DTMF, RX watchdog. |
| `companion/audio.py` | PortAudio capture/playback, µ-law codec, level metering, device re-resolution and health checks. |
| `companion/ws_server.py` | Local HTTP/WS server for the browser. |
| `companion/iax2.py` | IAX2 transport, including AUTHREP challenge-response. |

The browser never talks to Asterisk. It talks only to `ws://127.0.0.1:9093/ws`; the Companion owns the UDP/4569 connection to the Pi.

## Installation

### Windows PC

There is **no in-repo Windows installer**. `install/companion/build.bat` checks for Python and PyInstaller, installs `sounddevice`, `aiohttp` and `numpy`, and builds a single file with:

```bat
pyinstaller --onefile --name chilemon-companion ...
```

It then prints manual instructions: copy `dist\chilemon-companion.exe` somewhere, create `%USERPROFILE%\.chilemon\config.toml` yourself, and run the exe with `--config` if the file is not at the default path. SmartScreen may require a manual bypass because the binary is unsigned.

For a source checkout, the one-action installer and the launchers live in the repo:

- `companion/install-companion.bat` — the onboarding entry point. It checks Python, calls `python -m companion.install_config` to find/validate the dashboard download and install it at the default path, then reuses `install-autostart.bat` and `start-companion.bat`. It is idempotent, logs to `%USERPROFILE%\.chilemon\companion-install.log` and propagates failures. `--config PATH` uses an explicit config; `--config-only` stops before autostart/start.
- `companion/install_config.py` — config discovery, validation and placement. It refuses an empty `[peer].password` (auto-discovery learns the Pi IP but never invents the secret) and writes the config with mode 0600 on POSIX.
- `companion/start-companion.bat` — `cd` to the repo, checks whether 9093 is already listening, installs the autostart on the first manual run, resolves an absolute Python path, then runs `python -m companion.main` minimized. A failed spawn is written to `%USERPROFILE%\.chilemon\companion-startup.log` and returns a non-zero exit code.
- `companion/autostart-companion.bat` — no-argument logon target; calls `start-companion.bat --autostart`.
- `companion/install-autostart.bat` — idempotent registration: checks for the `ChileMon Companion` logon task and creates it when missing. If Windows denies task creation to a standard user, it checks/creates the equivalent `HKCU\...\Run` entry.
- `companion/stop-companion.bat` — finds the PID listening on 9093 and kills it.
- `install/companion/install-firewall.ps1` — optional inbound UDP rule, optionally scoped to the Pi IP.

The configuration search order is `--config`, then `~/.chilemon/config.toml`, then the repo's `companion/config.toml` (`main.py:40-51`). Discovery can rewrite `~/.chilemon/config.toml` with mode `0600` (`main.py:287-339`).

**Login autostart.** Running `start-companion.bat` once (or `install-autostart.bat` directly) registers `ChileMon Companion` for every logon. The routine is idempotent: it queries the registration first and only creates it when missing. Windows Task Scheduler is preferred; when the user cannot create tasks, the routine falls back to an `HKCU\Software\Microsoft\Windows\CurrentVersion\Run` entry pointing to `autostart-companion.bat` with an absolute path. Both paths call `start-companion.bat --autostart`, where the flag only suppresses the "already running" prompt. A pre-existing Startup shortcut is harmless: if 9093 is already listening, the autostart target exits quietly.

### Raspberry Pi (server side)

`install/install_chilemon.sh` does the authoritative setup:

- Generates `iax_companion_secret` with `openssl rand -hex 24` and stores it in `config/local.php` (mode `640`).
- Optionally reads `companion_iax_host` from `config/local.php` (or `CHILEMON_IAX_COMPANION_HOST`). The dashboard's `.bat` installer uses it as the IAX2 host instead of the HTTP request address. Set it to this Pi's LAN IP on multi-homed nodes or when a proxy is in front of the dashboard; without it the previous `SERVER_ADDR` detection applies.
- Detects the LAN CIDR, falling back to `127.0.0.1/32` if it cannot.
- Writes the `[companion-app]` peer from `install/asterisk/iax-companion.conf`, substituting the secret and the LAN ACL.
- Restricts the peer with `deny=0.0.0.0/0.0.0.0` plus `permit=<LAN>` and scoped `calltokenoptional`.
- Installs the `[radio-companion]` dialplan context with the node number entered during installation, from `install/asterisk/extensions-companion.conf`.
- Grants `www-data` passwordless sudo only for `bin/chilemon-rpt`, which allows `nodes`, `stats`, `connect`, `disconnect` and `git-*`. Destructive system verbs are absent.

The `[radio-companion]` context is installed by the Pi installer itself and is idempotent: re-running the installer preserves an existing context.

### Linux PC alternative

`install/companion/install.sh` installs to `/opt/chilemon/companion`, writes `/etc/chilemon-companion/config.toml` with mode `600`, creates an unprivileged `chilemon-companion` systemd service, injects the peer into Asterisk and installs the `[radio-companion]` context. Configure it with `CHILEMON_IAX_SECRET`, `CHILEMON_LOCAL_NODE`, `CHILEMON_PI_HOST` and `CHILEMON_LAN_ACL`.

## Runtime

### Endpoints and messages

| Direction | Transport | Details |
| --- | --- | --- |
| Browser → Companion | WS `/ws` | `ptt` (`key`/`unkey`), `dtmf`, `call` (`node` or `action:hangup`), `status` |
| Companion → browser | WS `/ws` | `status`, `audio_level` (`rms`, four spectrum bins), `error` |
| Health probe | HTTP `GET /health` | `{"status":"ok","peers":N}` |

The WebSocket binds `127.0.0.1:9093` by default. IAX2 uses UDP `4569` to the configured Pi host.

### Call lifecycle

`place_call()` is idempotent: it hangs up any existing call first and derives the caller ID from the local node digits. Hangup sends DTMF `#` first and calls `audio.reset()` so buffered audio does not play after the call ends. `CALL_IDLE_TIMEOUT = 60.0` seconds causes an automatic hangup when no RX audio arrives; the check runs every five seconds. This is why a healthy WS with no active call is normal after a reload — press **Reconectar** to reopen the channel explicitly. Automatic WS reconnects never re-key the repeater.

### Audio and device handling

Sample rate is 8 kHz with 160-frame (20 ms) buffers. An empty or unmatched `input_device`/`output_device` follows the real OS default; a numeric ID or a name substring pins a device. Use `python -m companion.main --list-devices` to enumerate them.

`audio_level` is computed on each microphone callback and marshalled to the event loop with `run_coroutine_threadsafe`. A device can stay open and keep firing callbacks while delivering silence after Windows switches its default input. `check_health()` runs every two seconds and reopens the streams when:

- the freshly resolved device IDs differ from those captured at stream open, or
- input RMS stays below `0.0005` for 15 seconds, or
- an input/output callback stalls for more than three seconds.

A 30-second cooldown prevents a restart loop when the device stays broken. This is what removed the need to hardcode a device name.

### Mic rest mode

PTT is a hard transmit gate. Mic frames only reach IAX2 while PTT is held; releasing PTT drops every frame and sends DTMF `#` to force the transmitter off. Closing the dashboard tab or losing the WebSocket also releases PTT immediately. This matters on a half-duplex (`duplex=0`) AllStar node: its VOX keys the transmitter from any audio on the channel, so forwarding mic frames with PTT up would re-key the node and feed a speaker-to-mic loop to the remote end as a constant tone.

The input stream stays open while PTT is released so the visualizer keeps working, and the mic rests after 120 seconds without voice (`TX_REST_S`). Speaking for three consecutive 20 ms frames above `0.01` RMS refreshes the rest timer and the 200 ms pre-roll, but it never transmits by itself — only an explicit PTT press does. While resting, the 15-second silence health check is suspended — the silence is intentional — but device-change and callback-stall detection stay active.

The status line reads `Mic en reposo - listo para transmitir`; it is a normal ready state, not a failure. All thresholds are configurable in `[tx]` of `config.toml`. Use headphones: playing received audio through a speaker next to the mic can still cause acoustic feedback while PTT is held.

For a non-technical user, `start-companion.bat` now launches the app minimized and writes diagnostics to `%USERPROFILE%\.chilemon\companion.log` instead of showing a scrolling terminal. Failures before logging is configured are captured in `companion-startup.log`.

## Security assessment

No shipped secret exists: `companion/config.toml` has `password = ""` and `config/app.php` fails closed when `IAX_COMPANION_SECRET` is missing. Tests reject the legacy `chilemon2026` default. The literal still appears in legacy WebRTCBridge test scripts, but those are AMI credentials, not the companion peer.

| Area | Assessment |
| --- | --- |
| IAX2 peer | Random secret per install, LAN-scoped ACL, `deny` all by default. Loopback fallback when no LAN is detected. |
| Local WebSocket | Binds loopback, but sets `Access-Control-Allow-Origin: *` and has **no auth or origin check**. Any page open in the operator's browser can send `ptt`, `call` or `hangup` to `127.0.0.1:9093`. This is the main local exposure. |
| Secret delivery | `companion-download.php` requires a login session and returns HTTP 503 when no secret is configured, but has no rate limit, CSRF check or admin-role check. The response contains the shared IAX2 secret in cleartext TOML. |
| Sudo boundary | `www-data` may only run the allowlisted `chilemon-rpt` wrapper. `system_action.php` rejects every action. |
| Autostart | Runs with the user's own privileges at login, adds no elevation, but makes the local WS available for the whole session. Registered as a logon task when permitted, otherwise as a per-user `Run` entry. |

**Verify:** `install/asterisk/iax-companion.conf` uses `auth=md5` and expects AUTHREP; `docs/HISTORIA_AUDIO_END_TO_END.md` concluded that `auth=none` was required at an earlier point. Current `iax2.py` implements AUTHREP, so confirm the live peer setting on the Pi before changing it.

## Can the "Companion App" button install it for future users?

**Yes — as a one-action Windows installer.** The button (`dashboard.php:169-173`) calls `downloadCompanion()`, which downloads `api/companion-download.php` as `chilemon-companion-install.bat`. The endpoint reads the versioned `companion/installer-template.bat.tpl`, embeds the generated TOML as base64 and the configured repo ref, and serves CRLF batch bytes with an `.bat` attachment name.

On the PC the installer downloads the repository zip from GitHub, extracts only `companion/` and `app/Services/WebRTCBridge/iax2.py` into `%USERPROFILE%\.chilemon\src\`, installs `numpy`/`sounddevice`/`aiohttp` with pip, writes the config to a staging file and delegates validation and placement to the tested `companion.install_config` module. It then reuses the idempotent `install-autostart.bat` and the readiness-checked `start-companion.bat`; it does not create tasks or write the Run key itself.

Remaining considerations:

1. The PC needs internet access for the GitHub download and the pip install. Pin `CHILEMON_COMPANION_REF` to make the source revision reproducible.
2. The installer is a plain batch script, so there is no signed binary or SmartScreen prompt.
3. The endpoint requires a session, but rate limiting, an admin-role check and an origin check remain separate hardening work.

## Troubleshooting checklist

- [ ] `Get-Process python*` shows a companion process; `netstat -ano | findstr :9093` shows a listener.
- [ ] `GET /health` returns `{"status":"ok"}`.
- [ ] Dashboard says **Companion Connected**, not *Companion no detectado*. That message means the WebSocket handshake failed, i.e. no process is listening.
- [ ] After a reboot, `schtasks /query /tn "ChileMon Companion"` or `reg query "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "ChileMon Companion"` shows the autostart registration, and the companion starts without a `pause` window.
- [ ] WS `status` reports `connected: true` for IAX2; press **Reconectar** if `call_active: false`.
- [ ] `audio_level` RMS is clearly above `0.0005`; a near-zero value with live callbacks means a stale silent device, which `check_health()` should reopen.
- [ ] The Pi peer secret matches `config/local.php`; a rotated secret breaks authentication silently.

## Next step

See `HISTORIA_AUDIO_END_TO_END.md` for the measured history of the audio path, and `GUIA_RAPIDA_INSTALACION_CHILEMON.md` for the Pi installation walkthrough. Before relying on the `auth=md5` statement above, verify the live peer on the Pi.
