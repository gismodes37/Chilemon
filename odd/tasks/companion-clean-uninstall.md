# Feature: Clean companion uninstaller
Branch: `feat/companion-clean-uninstall`. Delivery: work-unit commits on this feature branch; no push, PR or merge without a new user request.
Test runner: `python -m pytest tests/ -q` and `node tests/js/ptt-reconnect.test.js`. Risk: item 1 (removes user config, logs and downloaded source under `~/.chilemon`; guarded by confirmation, `--dry-run` and `--keep-config`).

## Specs

S1 — "bien, hay que in pensando en un buen desinstalador del companion que deje al usuario que no le guste chilemon"

Authorized outcome: a cleanup path for a user who decides to stop using ChileMon. Acceptance: `python -m companion.uninstall` removes the per-user app directory (`config.toml`, logs, downloaded `src`, bytecode, staging dirs), the logon task, the `HKCU Run` value and the Startup shortcut, and the Windows firewall rule when permitted. It reports anything it intentionally leaves behind and exits 0 only when no autostart entry or 9093 listener remains.

S2 — "Si por favor, si vamos a proponer una alternativa un poco invasiba para un usuario, devemos dar una alternativa de limpieza, ademas seremos evaluados por la comunidad experta y saltaran las criticas y las malas son las primeras en aparecer"

Authorized outcome: the invasive default must come with less invasive alternatives. Acceptance: `--dry-run` prints the exact plan and changes nothing; `--keep-config` preserves `config.toml` and logs while removing code/autostart; the default asks for confirmation; `--yes` skips the prompt for automation. Neither mode removes shared pip packages or the downloaded `.bat`; both names them in the final report as deliberate survivors.

S3 — Safety invariant: a process must never be killed only because it listens on port 9093. Acceptance: a test with a fake 9093 owner whose command line lacks the companion marker leaves it alone; a test with the marker kills it. The Windows-only lookups are guarded so the module and tests run on any platform.

S4 — Firewall cleanup: the rule `ChileMon Companion IAX2` is removed when the process is administrator; otherwise the uninstaller does not fail and prints the exact admin command to remove it. Acceptance: unit tests with a mocked administrator check and a mocked PowerShell runner.

S5 — Documentation: `docs/companion.md` and `docs/companion_ES.md` explain the uninstall flags, what is removed, what is deliberately kept, and the firewall admin caveat. Acceptance: both docs mention `--dry-run`, `--keep-config`, `--yes` and the firewall rule name.

S6 — The Windows entry point is a thin wrapper: `companion/uninstall-companion.bat` delegates to `python -m companion.uninstall %*` and propagates the exit code. Acceptance: a source-level test asserts the wrapper contains the delegation and no direct `taskkill`, `reg delete` or `rmdir /s` commands.

## Tasks

T1 — S1-S4 — inline — RED tests for plan, confirmation, process safety and firewall — GREEN: RED was `ModuleNotFoundError: No module named 'companion.uninstall'` on baseline 0262619; after implementation 14 focused tests passed.
T2 — S1-S6 — inline — Python uninstall module, thin batch wrapper and docs — done: 35 uninstall-focused tests passed.
T3 — S1-S6 — inline — full suite, independent verifier and evidence log — done: 204 passed, 1 skipped; JS 8 pass / 0 fail; `git diff --check` exit 0; verifier S1-S6 met with no blockers; native review unavailable on this runtime (see L6). Commit `4d12b97`.

## Log

L1 — "bien, hay que in pensando en un buen desinstalador del companion que deje al usuario que no le guste chilemon"

L2 — "Si por favor, si vamos a proponer una alternativa un poco invasiba para un usuario, devemos dar una alternativa de limpieza, ademas seremos evaluados por la comunidad experta y saltaran las criticas y las malas son las primeras en aparecer"

L3 — Read-only review found that the existing `companion/uninstall-companion.bat` kills any PID on 9093, removes the named task/Run key/Startup shortcut and config/logs, but leaves `%USERPROFILE%\.chilemon\src`, the firewall rule `ChileMon Companion IAX2`, `%TEMP%\chilemon-install-*` staging dirs, `--user` pip packages and the downloaded `.bat`. It has no preview, confirmation or survivor report. The new unit is a testable Python module behind a thin batch wrapper so the risky logic can be verified without touching a real machine.

L4 — RED → GREEN evidence: baseline 0262619 could not import `companion.uninstall` (exit 2). After implementation, `python -m pytest tests/companion/uninstall_test.py tests/companion/uninstall_companion_bat_test.py -q` → 14 passed; with the returned probes, 35 passed. Full suite → 204 passed, 1 skipped. `node tests/js/ptt-reconnect.test.js` → 8 pass / 0 fail. `git diff --check` → exit 0 with advisory CRLF warnings.

L5 — Independent verifier (gentle-ai-verify) verdict: S1-S6 all met, no blockers, no unverified spec. It also returned byte-level probes for dry-run immutability, declined confirmation, keep-config, marker matching and firewall command forms; those were added as `tests/companion/uninstall_probe_test.py` after correcting two defects in the probe code (empty `argv` passed to `main`, and a non-idempotent seed). Its scratch directories were removed by the parent. No Pi or real PC state was touched.

L6 — Native review: RDD mode is on (global default). `gentle-ai review assess` on the work-unit commit reported `risk: high`, `review_due: true` (`process_boundary` in `companion/uninstall.py`). Canonical STATUS and consent relay were completed and the user chose "Revisar este cambio" (`grant`); START created lineage `review-36dcaa870857ebb4` with four lenses and a 200-line correction budget. Both grouped attempts to capture the provider-issued lens tasks failed with `opencode_review_transport_relay_refused (reason: provider_failed)`; the bound STATUS reoffered the slot each time. No review receipt, approval or PASS is claimed. Verification stands on the independent verifier plus the observed tests above.

L7 — Work-unit commit `4d12b97`: `feat(companion): add reversible clean uninstaller with safety guards` (7 files, 847 insertions, 80 deletions). The unit is 927 changed lines by native count, above the 400-line heuristic, deliberately kept as one commit because the risk item requires its own reviewable slice. `public/api/log-call.php` was excluded from staging and remains untracked. No push, PR or merge was performed.
