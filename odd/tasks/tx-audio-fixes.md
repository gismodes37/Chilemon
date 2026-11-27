# TX audio fixes — stable timestamp media clock and automatic live-mic selection

Branch: `develop` (`9452a00`); live Pi install tracks `develop`, `main` stays at `ddf84b5` until explicit promotion · Delivery: two work-unit commits on `develop`, push to `origin/develop`, then promote to `main` on user approval · Runner: `python -m pytest tests -q`

## Specs

**S1** — "No se escuchó" / TX voice not intelligible. Voice frames must advance by payload duration (160-byte ulaw frame = 20 ms) and snap forward only across real silence gaps. The old wall-clock `+1 ms` guard compressed 20 ms into 1 ms; the pure counter froze during silence and triggered Asterisk jitter-buffer resync. Acceptance: `_next_tx_media_ts(payload_ms)` steps by payload duration for consecutive frames, catches up to the wall clock across silence, resets per call, and live logs show uniform ~20 ms deltas. Checks: `tests/companion/iax2_test.py`, full Python suite.

**S2** — "el parrot ya devuelve mi voz, ahora si me escucho". The OS default input can open successfully while delivering digital zero. Companion must probe inputs on the OS default device's host API, pick the live mic with the highest RMS >= 2 LSB, keep an explicit `input_device` as an override, and track OS defaults separately so the probed mic is not mistaken for a default-device change and restart loop. Acceptance: `AudioEngine._pick_input_device()`, `_default_device_ids`, `INPUT_PROBE_MS = 100`, `MIN_LIVE_INPUT_RMS = 2.0`; silence while listening must not age into a keyed restart. Checks: `tests/companion/audio_device_test.py`, `tests/companion/audio_health_test.py`, full Python suite.

**S3** — "vamos a dejar como buena esta configuracion, actualicemos el main para activar el boton de actualizacion y que todo tome curso otra vez". Promote the accepted TX configuration from `develop` to `main`, excluding the pre-existing untracked `public/api/log-call.php`. Version target remains subject to explicit user decision.

## Tasks

- `T1` — S1, inline, **done** (`797070a`) — Hybrid TX media timestamp and regression tests. Route: inline; no Writer trigger (one understood behavior with its tests). Risk: none (tests pin the wire timing contract). Native assess: medium / `under_budget`, no review due.
- `T2` — S2, inline, **done** (`f3197da`) — Automatic live-mic probe, default-device tracking, keyed-silence timer and regression tests. Route: inline; no Writer trigger (one understood behavior with its tests). Risk: none. Native assess: medium / `under_budget`, no review due.

Route evidence: no Writer trigger (each unit is one behavior with its tests, already understood from the prior diagnosis); no Explorer trigger (the mapping and root causes came from the prior TX investigation). Delivery strategy: `ask-on-risk`; forecast 374 authored changed lines across two work-unit commits.

## Log

**L1** (2026-11-27) — Operator report after the TX repair: "el parrot ya devuelve mi voz, ahora si me escucho". Earlier symptom: "No se escuchó". Companion log at 19:45:03 confirmed automatic selection of the headset mic (probe RMS 58.5) over the dead OS default (0.5), with uniform 20 ms TX frame steps.

**L2** (2026-11-27) — User accepted the configuration and authorized promotion: "vamos a dejar como buena esta configuracion, actualicemos el main para activar el boton de actualizacion y que todo tome curso otra vez". Current working tree has the five TX source/test files modified and the pre-existing untracked `public/api/log-call.php`, which stays excluded.

**L3** — No project skill registry is installed (`.atl/skill-registry.md` absent); the installed `work-unit-commits` skill was loaded. Full suite already observed at 289 passed, 1 skipped after the mic-probe fix; re-run before delivery. `gentle-ai review mode status`: on by global default.

**L4** — Delivery verification on `develop`: `python -m pytest tests -q` → 289 passed, 1 skipped in 8.91s; `git diff --check` clean. T1 committed as `797070a` (2 files, +151/-32), T2 as `f3197da` (3 files, +223/-7). Native assess with the pre-existing untracked inventory explicitly excluded: both `medium / under_budget`, `review_due: false`; no native review ceremony started. `public/api/log-call.php` remains untracked and excluded. Next: push `origin/develop`, then resolve the version target and promote to `main`.
