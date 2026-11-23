# Companion spectrum visualizer — RX/TX levels, real spectrum, distinctive UI
Branch: `main` (permanent download/install branch). Delivery: direct work-unit commits to `main`, pushed to `origin/main`. Runners: `python -m pytest tests/companion`, `node tests/js/*.test.js`, Docker PHP suite.

## Specs

S1. "bien has un analisis de visualizador, para ver como funciona y formular mejoras con el fin de hacerlo mas sensible a los ruidos y mejorar su aspecto" — authorized scope: sensitivity plus appearance.

S2. "hacerlo mas sensible a los ruidos" — weak input must be visible; normal speech and received telemetry must not render as dead flat bars.

S3. "no olvidemos lo visual tambien tiene que ser atractivo, los monitores americanos más usado no lo tienen son monotonos, hagamos la diferencia si queremos una comunidad" — the UI must have its own recognizable identity, not a generic equalizer.

S4. Accepted proposal, verbatim: "RX real + sensibilidad dB + espectro verdadero y throttle" plus visual redesign in one change (user answered "si por favor" and explicitly added the visual requirement).

S5. "no dejes de realizar esos cambios en local y de subir los cambios, para no perder el rastro de las correcciones" — every source change must be committed and pushed to `origin/main`; the locally installed Companion must be refreshed for testing.

S6. Invariant (derived from code evidence, not an extra user requirement): the raw linear RMS returned by `AudioEngine._compute_levels()` keeps driving `_handle_tx()` wake/sleep. Display scaling must be a separate value so TX gating does not change.

S7. Contract (derived from the accepted proposal): the `audio_level` WS message is additive — `{type:"audio_level", rms:<raw linear 0..1>, spectrum:<16 log-spaced bands 0..1>, dir:"tx"|"rx"}`. `rms` keeps its current meaning; `spectrum` grows from 4 to 16 real FFT bands; `dir` is new. Consumers must tolerate missing `dir` (default `"tx"`) and shorter `spectrum` arrays (interpolate).

S8. Delivery constraint (derived): the canonical companion source is the repo's `companion/` directory; `~/.chilemon/src/companion` is only a local runtime copy. A change that exists only in the local copy will never reach other installs.

## Tasks

T1 — S4,S6,S7,S8 — delegated (bounded writer A) — companion metadata producer: real FFT bands, RX level emission, per-direction throttle, additive `dir` field, Python tests.
T2 — S2,S3,S4,S7 — delegated (bounded writer B) — dashboard visualizer consumer: dB mapping with adaptive noise floor, direction-aware palette, distinctive console redesign, dead FFT removal, JS tests.
T3 — S1,S5,S8 — inline (parent) — seam check, refresh local installed Companion, work-unit commits, push to `origin/main`, report verification.

## Log

L1 (2026-11-23): "si por favor, no olvidemos lo visual tambien tiene que ser atractivo, los monitores americanos más usado no lo tienen son monotonos, hagamos la diferencia si queremos una comunidad"

L2 (2026-11-23): Previous analysis established the baseline defects with code evidence: the 4 "spectrum" bins were time-sliced mean absolute amplitude, not frequency bins (`companion/audio.py:605-617`); levels were emitted only from the mic path (`main.py:123`, `audio.py:520`) while `audio.py:523-556` (RX playback) computed none; raw RMS divided by 32768 produced sub-pixel bars on a 58px canvas; `main.py:161` broadcast every 20ms with no throttle; `ptt-widget.js:94-96` overrode `feedPCM` with a no-op, leaving the JS FFT dead.

L3 (2026-11-23): Route decision — two independent writer units launched together in one turn with disjoint edit surfaces, because both units are several files / >100 changed lines and share only the frozen S7 contract. Parent owns git, local install refresh and final seam check. No high-risk item named: S7 is additive, S6 preserves TX gating, S8 preserves the installer flow; Risk line for both units is `none`.

L4 (2026-11-23): Delivery strategy `exception-ok`: direct commits to `main` per the standing user instruction that `main` is always the download/install point. Work-unit commits keep tests with their behavior.

L5 (2026-11-23): Seed commit before this feature: `5683907 fix(update): give feedback when badge click finds no update`. Untracked `public/api/log-call.php` is pre-existing and must remain excluded from every commit.
