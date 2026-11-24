# Dynamic Pi endpoint detection for the Windows Companion

Branch: `fix/dynamic-pi-detection` · Delivery: work-unit commits, no push/PR/merge unless asked · Runner: `python -m pytest tests/ -q`

## Specs

- **S1** — "no sirve harcodear una IP si se corre el riesgo de que un cliente tenga DHCP". The Companion must not depend on a pinned Pi address: when the configured host stops answering, it must discover the reachable Pi endpoint automatically and reconnect.
- **S2** — Evidence of the failure: `Pi reachable at 192.168.0.110:4569` was logged every startup even though `.110` was unreachable; the check used `sendto()` on a connected-less UDP socket, which never proves reachability. Real symptom: `Call to 54614 failed: Call setup timeout (no NEWACK)` and `JitterBuffer stats: received=0, played=0, dropped=0, plc=0`.
- **S3** — "si esta conectado, la misma pc, le di a reconectar" and "se detecta la actividad en la red pero sin audio". The dashboard's reconnect/call action must hand the Companion the dashboard's current host so a DHCP change is picked up without reinstalling.
- **S4** — A hostname may resolve to a secondary Pi interface while Asterisk replies from the primary one, so candidates must be verified at the IAX2 layer (PING/PONG), not only by DNS or HTTPS.
- **S5** — The generated config keeps its IAX2 secret handling unchanged: discovery never invents a password. No literal Pi IP may be added to shipped config or fallback code.
- **S6** — Verification: focused pytest for the new behavior, full `python -m pytest tests/ -q`, and a read-only IAX2 probe against the live Pi to confirm the probe itself works. No local live-config write and no Pi mutation in this task.

## Tasks

- T1 — S1,S2,S4,S5 — Add a real IAX2 PING/PONG endpoint probe and candidate resolver in `companion/discovery.py`; tests first. — inline — commit: `123a0ce` (RED 7 failed → GREEN 7 passed)
- T2 — S1,S3,S4 — Replace the false `sendto` reachability check; resolve candidates at startup and on reconnect; accept the dashboard host as a candidate via the WS protocol. — inline — commit: `9ed277a` (RED 3 Python + 1 JS → GREEN; full suite 168 passed, 1 skipped)
- T3 — S1,S5,S6 — Emit `node_host` in the generated config, keep the shipped template free of pinned Pi IPs, update docs, run focused + full suite. — inline — commit: `467165f` (full suite 170 passed, 1 skipped; JS 8 passed)

## Log

L1 — "no sirve harcodear una IP si se corre el riesgo de que un cliente tenga DHCP"
L2 — 2026-11-24. Verified the root cause from source and log: `_ensure_pi_connection()` uses a UDP `sendto()` that always succeeds, so auto-discovery never runs; additionally the repo has a UDP 9094 discovery client but no Pi-side responder, so the old fallback could not work either. Decision: no Pi-side daemon and no subnet-wide scan (remote scope is read-only); verify candidates with IAX2 PING/PONG and let the dashboard supply its current hostname on reconnect. Current live config `C:\Users\gismo\.chilemon\config.toml` stays untouched.
L3 — T1 GREEN: IAX2 probe, address resolver and `find_reachable_pi` added to `companion/discovery.py`; 7 RED tests in `tests/companion/pi_endpoint_test.py` went GREEN. The resolver returns the *responding* IP, so a multi-homed Pi advertised at .114 can be used at .125. Committed `123a0ce`.
L4 — Live read-only probe (no config/Pi writes) showed Asterisk ignores PING and LAGRQ from an unknown peer but answers REGREQ with REGREJ. Probe switched to REGREQ; `.125` answers, `.114` and `.110` do not.
L5 — `node494780.local` resolves only to `.114`, which does not answer IAX2, so hostname candidates alone are insufficient here. Added `probe_local_subnet()` deriving the /24 from the PC's own DHCP address (configurable `[discovery].subnet_prefix`), used only after the configured host and dashboard/name candidates fail. No Pi address is pinned; no Pi-side daemon was added.
L6 — T2/T3 GREEN. The false UDP `sendto` check is gone; the dashboard announces `window.location.hostname` over WS on open; reconnect re-resolves before rebuilding the transport. Full suite `python -m pytest tests/ -q` → 170 passed, 1 skipped; `node tests/js/ptt-reconnect.test.js` → 8 passed. Live audio still needs the running Companion to pick up the new code and a user-confirmed call to 54614 (`received > 0`); no live config write or Pi mutation was performed.
