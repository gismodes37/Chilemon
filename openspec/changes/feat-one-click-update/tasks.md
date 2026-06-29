# Tasks: One-Click Update

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~520–580 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (Backend) → PR 2 (Frontend) |
| Delivery strategy | auto-forecast |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Backend: wrapper, UpdateService, both API endpoints, unit tests | PR 1 | Base targeting `main`; ~290–320 lines |
| 2 | Frontend: CSS, header badge, modal, JS polling/apply, integration tests | PR 2 | Targets PR 1 branch; ~230–260 lines |

## Phase 1: Foundation

- [x] 1.1 Extend `bin/chilemon-rpt` — add `git-fetch`, `git-compare`, `git-pull`, `sys-restart-webrtc`, `sys-reload-apache` cases to the case-switch
- [x] 1.2 Create `app/Services/UpdateService.php` — `check()` and `apply()` methods, `ALLOWED` validation, Windows mock, matching `AslRptService` pattern
- [x] 1.3 Create `public/api/check-update.php` — GET endpoint: `Auth::isLoggedIn()`, `RateLimiter::check('check-update', 30, 60)`, calls `UpdateService::check()`
- [x] 1.4 Create `public/api/apply-update.php` — POST endpoint: `Auth::requireAdmin()`, CSRF validation, `RateLimiter::check('apply-update', 5, 60)`, calls `UpdateService::apply()`

## Phase 2: Frontend UI

- [x] 2.1 Add `@keyframes pulse-update` and `.btn-update-available` to `public/assets/css/dashboard.css`
- [x] 2.2 Add admin-only pulsing update badge in `public/views/partials/header.php` — visible only when `Auth::isAdmin()`
- [x] 2.3 Add update confirmation modal in `public/views/dashboard.php` — reuses `modalReinicio` Bootstrap pattern, shows commit summary, confirm/cancel
- [x] 2.4 Add `checkUpdate()`, `applyUpdate()`, 5-min polling init, and update UI handler to `public/assets/js/dashboard.js`

## Phase 3: Testing

- [x] 3.1 Unit tests for `UpdateService` — command validation rejects invalid args; `check()` parses mock output correctly; `apply()` handles git-pull output
- [x] 3.2 Integration tests — GET `/api/check-update.php` returns JSON shape with valid session; POST `/api/apply-update.php` rejects non-admin (403) and invalid CSRF (403)
