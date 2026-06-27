# Design: Cross-Origin Installation Registration

## Technical Approach

Hub `register.php` gains a credential fallback path: if no session cookie exists, it checks POST body for `hub_user` + `hub_pass` and calls `Auth::attemptLogin()`. On success, registration proceeds without CSRF (auth IS the protection, same as `/login.php`). Agent `dashboard.php` adds conditional login fields to the registration modal, hidden behind a `localStorage` flag that persists across tabs and browser restarts.

## Architecture Decisions

| Decision | Options | Chosen | Rationale |
|----------|---------|--------|-----------|
| **Auth mechanism** | (a) Direct POST with credentials inline, (b) Proxy endpoint on hub that establishes session first, (c) API token per node | (a) Direct POST | Zero new endpoints, zero new server state. Credentials flow the same way as `/login.php` — HTTPS protects them in transit. Rate limiting already exists in `Auth::attemptLogin()`. |
| **Persistence for auth flag** | `localStorage` vs `sessionStorage` | `localStorage` | A node registers once — the flag should survive tab close and browser restart. `sessionStorage` (used for `reg_banner_dismissed`) would force re-authentication every session, which is unnecessary friction. |
| **CSRF skip for credential path** | Require CSRF always vs skip when authenticating | Skip when authenticating | The credential path is equivalent to `/login.php` — login forms don't need CSRF tokens because the act of providing credentials is itself the protection. Rate limiting (5/10min) blocks brute force. Threat model is unchanged. |

## Data Flow

```
Agent Browser                     Hub Server
     │                                │
     │  POST /api/map/register.php    │
     │  {callsign, lat, lng,          │
     │   hub_user, hub_pass}          │
     │ ──────────────────────────────>│
     │                                │── Auth::isLoggedIn()? No
     │                                │── Auth::attemptLogin()
     │                                │── Register via MapController
     │  {"ok": true}                  │
     │ <──────────────────────────────│
     │                                │
     │  (stores localStorage flag)    │
     │                                │
     │  POST /api/map/register.php    │ (next time, no credentials)
     │  {callsign, lat, lng}          │
     │ ──────────────────────────────>│
     │                                │── Auth::isLoggedIn()? Yes (session)
     │                                │── Validate CSRF
     │                                │── Register via MapController
     │  {"ok": true}                  │
     │ <──────────────────────────────│
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `public/api/map/register.php` (hub) | Modify | Add credential fallback before `requireLogin()` — attempt `Auth::attemptLogin()` when `hub_user`+`hub_pass` present, skip CSRF in credential path |
| `public/views/dashboard.php` (agent) | Modify | Add conditional `hub_user`/`hub_pass` fields to registration modal, toggle via `localStorage.chilemon_reg_authenticated` |

### register.php — Key Logic Change (hub)

```php
Auth::startSession();

// Credential fallback for cross-origin requests
if (!Auth::isLoggedIn()) {
    $hubUser = trim((string)($_POST['hub_user'] ?? ''));
    $hubPass = (string)($_POST['hub_pass'] ?? '');
    if ($hubUser !== '' && $hubPass !== '') {
        if (!Auth::attemptLogin($hubUser, $hubPass)) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => Auth::getLastError()]);
            exit;
        }
        // Authenticated via credentials — skip CSRF, proceed to register
    } else {
        Auth::requireLogin(); // redirects to login page
    }
}
```

Existing rate limiting (api_attempts table) and field validation remain unchanged. CSRF validation is guarded: only executed when the request came through the session path (already logged in).

### dashboard.php — Conditional Auth Fields (agent)

Added before the registration fields in the modal, wrapped in a `d-none` div toggled by JS:

```html
<div id="regAuthFields" class="mb-3">
    <div class="mb-2"><label class="form-label">Usuario hub</label>
        <input type="text" class="form-control" name="hub_user" maxlength="100"></div>
    <div class="mb-2"><label class="form-label">Contraseña hub</label>
        <input type="password" class="form-control" name="hub_pass"></div>
    <hr>
</div>
```

JS toggle on modal open:

```javascript
var authFields = document.getElementById('regAuthFields');
authFields.classList.toggle('d-none', localStorage.getItem('chilemon_reg_authenticated') === '1');
```

On success, set the flag: `localStorage.setItem('chilemon_reg_authenticated', '1')` alongside dismissing the banner.

## Interfaces / Contracts

No new types or interfaces. The hub `register.php` response contract is unchanged:

- **200**: `{"ok": true, "id": int, "callsign": string, ...}`
- **401**: `{"ok": false, "error": "..."}` (auth failure)
- **429**: `{"ok": false, "error": "Demasiadas solicitudes..."}` (rate limit)
- **400**: `{"ok": false, "error": "Faltan campos requeridos..."}` (validation)

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Manual — hub | Credential fallback without session | `curl -X POST -d "hub_user=admin&hub_pass=secret&callsign=test&lat=-33&lng=-70" http://hub/api/map/register.php` → expect `{"ok":true}` |
| Manual — hub | Invalid credentials | Same with wrong pass → expect 401 |
| Manual — hub | Rate limiting | 5 rapid requests with wrong pass → 6th returns 429 |
| Manual — agent | Modal with credentials | Open modal on agent dashboard without `localStorage` flag → fields visible; submit → success → fields hidden on reopen |
| Regression | Session-based still works | Log into hub directly, submit registration → same behavior as before (CSRF validated, no credential prompt) |

## Migration / Rollout

1. **Deploy hub first**: `register.php` change is purely additive — existing session-based flow is identical. Rollback = revert file.
2. **Deploy agent second**: `dashboard.php` change adds fields behind `localStorage` gate — no change in behavior until the hub also has the change. Rollback = revert file.
3. If hub is rolled back but agent is not: agent sends credentials but hub ignores them (returns 401 via `requireLogin()`). The user sees an error and needs to log into hub directly — acceptable degraded state.

## Open Questions

None.
