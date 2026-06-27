# Tasks: Cross-Origin Installation Registration

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~50–70 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | auto-forecast |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Hub + Agent together | PR 1 | Single PR; deploy hub first, then agent |

## Phase 1: Hub — register.php Credential Fallback

- [x] 1.1 Add `Auth::isLoggedIn()` check before `requireLogin()` in `public/api/map/register.php`
- [x] 1.2 Extract `hub_user`/`hub_pass` from POST body when no session exists
- [x] 1.3 Call `Auth::attemptLogin()` — return 401 on failure, skip `requireLogin()`
- [x] 1.4 Skip CSRF validation on credential auth path, proceed to registration

## Phase 2: Agent — dashboard.php Modal Login Fields

- [x] 2.1 Add `regAuthFields` div with `hub_user`/`hub_pass` inputs in `public/views/dashboard.php`
- [x] 2.2 Add JS toggle on modal open — show/hide based on `localStorage.chilemon_reg_authenticated`
- [x] 2.3 Set `localStorage.chilemon_reg_authenticated = "1"` on successful registration response
- [x] 2.4 Wire form submit to POST credentials + registration data together
