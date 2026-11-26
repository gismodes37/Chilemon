# Dashboard theme skins — structure first, then visual variants

Branch: `feat/dashboard-theme-skins` · Delivery: work-unit commits; push only on explicit user request · Runner: `python -m pytest tests/` (PHP is unavailable locally)

## Specs

**S1** — "puedes hacer un analisis del actual estilo del dashboard de chilemon y realizar una propuesta de mejora visual, para aprobechar la potencia de bootstrap y mejorar el aspecto, por a trabajar a tus mejores expertos"
Analysis and ranked proposal only, no code change. Completed before this document; evidence in `discovery/dashboard-visual-layer` and `dashboard/visual-improvement-proposal` in Engram.

**S2** — "se puede preparar esa propuesta como un thema, estructurarlo como para preparar unos dos thema diferentes o skin, para darle opciones a los usuarios de tener otras opciones de color, por ejemplo el original que tenemos pero mejorado, un black, que se yo un par de variantes, que te parece"
Prepare the design to support two selectable skins: `clasico` (improved original) and `obsidian` (near-black). Model: one cookie `chilemon_theme=<skin>:<mode>` with `chilemon_darkmode` legacy fallback, PHP allowlist, `<html data-bs-theme data-skin>`, CSS token layer mapped to `--bs-*`, one `ThemeManager` in `dashboard.js`, Bootstrap dropdown "Apariencia" in the header. No arbitrary attribute values from cookies.

**S3** — "si megusta tu propuesta, primero ordenamos y estructuramos el diseño y luego la ropa nueva, con mucho cuidado con el código sencible de funcionamiento, llegar a donde estamos a costado mucho, dale"
Execute in order: first order and structure the design (T0), then add the new looks (T1-T3). Do not touch audio/PTT/node/AMI logic. Work in reviewable slices and show the T0 diff before starting T1. Preserve current behavior and values: the token layer must be value-identical where it replaces a color.

**S0 acceptance (T0)** — Body top padding works again for the fixed header; the duplicate `.supermon-header` no longer cancels `.scrolled`; `--asl-skyblue` exists; one `.supermon-table` font-size rule; structural colors come from tokens with unchanged computed values; no changes to functional JS or AMI/audio/node code; static tests pass and the full Python suite stays green.

## Tasks

- `T0` — S3, inline, **done** (`c629c6d`) — Foundation: repair cascade, add token layer, migrate structural colors, static contract tests. Commit 1.
- `T1` — S2, inline, **done** (commit pending) — ThemeManager, single cookie with allowlist, header "Apariencia" dropdown, legacy cookie migration. Risk: item 3 (shared header/JS). Commit 2.
- `T2` — S2, inline, pending — `obsidian` skin as token data only. Commit 3.
- `T3` — S2-S3, inline, pending — Docs and optional Settings entry; verification in both skins. Commit 4.

Route evidence: no Writer trigger (single behavior per slice, under ~100 changed lines per slice, no parallel units); no Explorer trigger (the read-only mapping already returned a handoff). Large by resume test because it spans four slices across sessions.

## Log

**L1** (2026-11-25) — "si megusta tu propuesta, primero ordenamos y estructuramos el diseño y luego la ropa nueva, con mucho cuidado con el código sencible de funcionamiento, llegar a donde estamos a costado mucho, dale"

**L2** (2026-11-25) — "se puede preparar esa propuesta como un thema, estructurarlo como para preparar unos dos thema diferentes o skin, para darle opciones a los usuarios de tener otras opciones de color, por ejemplo el original que tenemos pero mejorado, un black, que se yo un par de variantes, que te parece"

**L3** (2026-11-25) — "puedes hacer un analisis del actual estilo del dashboard de chilemon y realizar una propuesta de mejora visual, para aprobechar la potencia de bootstrap y mejorar el aspecto, por a trabajar a tus mejores expertos"

**L4** — Analysis found verified cascade defects (`dashboard.css:18,442-445,451-454`), undefined `--asl-skyblue` (`:79`), duplicate `.supermon-table` (`:96-98,525-535`), and a theme toggle whose `data-theme-*` hooks exist only in JS. User approved the ordered plan and asked for care with working code.

**L6** — T1 implemented: `ThemeManager` (read/apply/persist/sync/bindControls/set/toggle/migrateLegacy) replaces the old `toggleTheme`; single allowlisted cookie `chilemon_theme=<skin>:<mode>` parsed in `index.php` with `chilemon_darkmode` fallback; `<html data-skin>` emitted by `head.php`; Bootstrap "Apariencia" dropdown in `header.php` with two radiogroups and four wired options; floating theme button removed from `footer.php`; `data-theme-*` hooks added to the remaining toggles; dead duplicate theme code and cookie helpers removed from unloaded `chilemon.js`. RED observed on 1 JS test and 7 Python assertions before the implementation. Verification: `theme-manager.test.js` 6 passed, dashboard connect/update JS suites green, full Python suite 246 passed + 1 skipped, `git diff --check` clean. No audio, PTT, node or AMI logic touched.

**L5** — T0 committed as `c629c6d` on `feat/dashboard-theme-skins`. Changes: `html,body{padding:0}` reset replaced with `html` reset plus `body.has-relative-header{padding-top:0}`; `head.php` emits that class only when `assets/img/header.jpg` exists; duplicate `.supermon-header` padding lost its `!important` so `.scrolled` compaction works; one owner for `.supermon-table` font-size; tokens `--asl-skyblue`, `--asl-lime`, `--asl-green-dark`, `--cm-header-deep` added and structural colors migrated. `--asl-skyblue` was previously undefined, so its cyan left border on Accesos rápidos is now restored (intentional bug fix, not a value change). RED observed on all 5 new assertions before the fix. Verification: `tests/ui/theme_foundation_test.py` 5 passed; full suite 239 passed, 1 skipped; `git diff --check` clean. Native assess: medium / `under_budget`, no review due. No audio, PTT, node, AMI or PHP logic touched. Not pushed.
