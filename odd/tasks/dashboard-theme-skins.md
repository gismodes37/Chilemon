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

- `T0` — S3, inline, pending — Foundation: repair cascade, add token layer, migrate structural colors, static contract tests. Commit 1.
- `T1` — S2, inline, pending — ThemeManager, single cookie with allowlist, header "Apariencia" dropdown, legacy cookie migration. Risk: item 3 (shared header/JS). Commit 2.
- `T2` — S2, inline, pending — `obsidian` skin as token data only. Commit 3.
- `T3` — S2-S3, inline, pending — Docs and optional Settings entry; verification in both skins. Commit 4.

Route evidence: no Writer trigger (single behavior per slice, under ~100 changed lines per slice, no parallel units); no Explorer trigger (the read-only mapping already returned a handoff). Large by resume test because it spans four slices across sessions.

## Log

**L1** (2026-11-25) — "si megusta tu propuesta, primero ordenamos y estructuramos el diseño y luego la ropa nueva, con mucho cuidado con el código sencible de funcionamiento, llegar a donde estamos a costado mucho, dale"

**L2** (2026-11-25) — "se puede preparar esa propuesta como un thema, estructurarlo como para preparar unos dos thema diferentes o skin, para darle opciones a los usuarios de tener otras opciones de color, por ejemplo el original que tenemos pero mejorado, un black, que se yo un par de variantes, que te parece"

**L3** (2026-11-25) — "puedes hacer un analisis del actual estilo del dashboard de chilemon y realizar una propuesta de mejora visual, para aprobechar la potencia de bootstrap y mejorar el aspecto, por a trabajar a tus mejores expertos"

**L4** — Analysis found verified cascade defects (`dashboard.css:18,442-445,451-454`), undefined `--asl-skyblue` (`:79`), duplicate `.supermon-table` (`:96-98,525-535`), and a theme toggle whose `data-theme-*` hooks exist only in JS. User approved the ordered plan and asked for care with working code.
