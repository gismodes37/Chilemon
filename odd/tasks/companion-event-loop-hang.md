# Companion event-loop hang ("Companion no detectado")

Objective: keep the dashboard WebSocket and `/health` responsive when a PortAudio device open blocks, so the tutorial recording is not interrupted. Branch: `develop`, promoted to `main` · Delivery: one work-unit commit on `develop`, push to `origin/develop` and `origin/main` · Runner: `python -m pytest tests -q`

## Specs

**S1** — Operator report: `"tengo companion no detectado"` after listening to the net for more than half an hour. Evidence: companion PID alive, 9093 in `Listen` with established TCP connections, but `/health` times out and the log freezes at `Using system default output device`. Root cause: `AudioEngine.start()` runs `_pick_input_device()` (`sd.rec()` + `sd.wait()`, no timeout) synchronously on the asyncio thread from `_place_call`, `_on_session_status`, `_hangup`, `_audio_health_loop` and `stop`. A device still held by a previous stream blocks PortAudio indefinitely, freezing the whole app; the browser then sees a failed WS handshake and shows "Companion no detectado". Acceptance: no blocking PortAudio call runs on the event loop; operations stay serialized; a stuck operation is bounded; WS and `/health` keep responding. Checks: `tests/companion/event_loop_test.py`, full Python suite.

## Tasks

- `T1` — S1, inline, **done** — Offload `audio.start/stop/reset/check_health` through `_run_audio()` using a lazy `asyncio.Lock`, `asyncio.to_thread()` and a bounded `AUDIO_OP_TIMEOUT_S = 5.0`; add `_close_audio()` so reset+stop stays one serialized operation. Route: inline; no Writer trigger (one understood behavior with its tests). Risk: item 4 (async ordering: lock + worker-thread offload); independent verifier returned no blockers. Commit: this work unit (see git log for the companion event-loop fix).

Route evidence: no Writer trigger (single understood incident fix with focused regression tests); no Explorer trigger (root cause identified from the live log and `/health` probe before editing). Delivery strategy: `ask-on-risk`. Native review: `gentle-ai review status` returned `immutable_review_transport_unsupported` (OpenCode 2.4.1 cannot return completed foreground reviewer Tasks); no transaction started, mode not toggled.

## Log

**L1** (2026-11-27) — Operator reported the net exercise disconnection and then `"tengo companion no detectado"`. Passive checks: process 23584 alive since 20:16:45; port 9093 `Listen` plus established connections; `/health` timed out at 5s and again at 10s; two connections in `CloseWait`; log frozen at 21:32:46. No restart or call/PTT probe was performed during diagnosis.

**L2** — Code trace confirmed the synchronous PortAudio path: `main.py` called `audio.start()` directly inside async callbacks, and `_pick_input_device()` probes every candidate mic with `sd.rec()` + `sd.wait()` on the same thread. The 21:32:30–46 WS reconnect storm re-placed the call twice; the final reopen blocked at the mic probe.

**L3** — RED observed before implementation: `tests/companion/event_loop_test.py` failed 3/3 (`_run_audio` missing; heartbeat test proved the loop froze). GREEN after the fix: 3 passed. Full suite: 292 passed, 1 skipped in 7.96s. `git diff --check` clean. Native review unavailable in this runtime (typed `immutable_review_transport_unsupported`); verification proceeds with an independent verifier for the named high-risk item instead.

**L4** — Implementation preceded this document during live incident triage; the document now records the complete specification, route and evidence. `public/api/log-call.php` remains untracked and excluded.

**L5** — Independent verifier returned no blockers and S1 met: baseline `b71b286` + new tests → 3 failed; change → 3 passed; `hangup_test.py` and `audio_health_test.py` → 22 passed on both; real `/health` 200 and WS handshake responsive with a stuck audio operation. Advisory accepted: after a timeout the lock is released while the worker thread may remain blocked, but the loop stays responsive. The verifier's durable probes were added as `tests/companion/event_loop_verifier_test.py`; full suite now 300 passed, 1 skipped. Native review remains unavailable (`immutable_review_transport_unsupported`); no mode toggle was performed. Local Companion deployed with the fixed `main.py`, restarted as PID 6628, `/health` 200 with 1 peer and endpoint `192.168.0.125:4569` confirmed; the restart dropped the stalled call to `54614`.
