# Design: feat/one-click-update — One-Click Update Button

## Technical Approach

Extend the existing `bin/chilemon-rpt` wrapper (already used for Asterisk/system commands via sudo) with `git-fetch` and `git-pull` cases. Create an `UpdateService` PHP class (mirroring `AslRptService`'s `ALLOWED` + `run()` pattern) that both new API endpoints delegate to. The check endpoint (GET) fetches remote refs and compares HEAD vs `origin/main`. The apply endpoint (POST) runs `git pull`, restarts `chilemon-webrtc`, and reloads Apache. Admin-only pulsing notification in the header polls every 5 minutes. Confirmation modal reuses the existing `modalReinicio` pattern from `dashboard.php`.

## Architecture Decisions

### Decision: Extend existing wrapper vs. new sudoers entry for git

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Add `www-data ALL=(root) NOPASSWD: /usr/bin/git` | Grants passwordless git to www-data directly — wider blast radius if PHP is compromised | ✗ Rejected |
| Extend `chilemon-rpt` with git cases | Keeps ALL system-level operations behind ONE wrapper with explicit allowlist. Already has `ALLOWED` validation in `AslRptService`. Consistent security model. | **Chosen** |

**Rationale**: The existing wrapper is already bulletproof — `AslRptService::run()` validates against `ALLOWED`, uses `escapeshellarg()`, and checks exit codes. Adding git cases extends the same pattern. A separate git sudoers entry would be a new attack surface.

### Decision: Git repo path — hardcoded in wrapper vs. configurable

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `GIT_REPO_PATH` constant in `config/app.php` | Flexible for dev, but the git commands run inside the shell wrapper, not PHP — wrapper doesn't read PHP constants | ✗ Rejected |
| Hardcode `/opt/chilemon` in wrapper | Matches actual production path from the install script. Simple, no config drift. Dev env doesn't need it (Windows mock). | **Chosen** |

**Rationale**: The wrapper runs as root via sudo. It exists only on the production Pi at `/usr/local/bin/chilemon-rpt`. The repo is always at `/opt/chilemon` per the install script (`install/install_chilemon.sh`). Configuration would add complexity with zero benefit.

### Decision: Check mechanism — `git fetch` vs `git ls-remote`

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `git ls-remote origin HEAD` from PHP | No local fetch needed, but requires separate sudo call for `git` (new attack surface) or adding `git-ls-remote` to wrapper | ✗ Rejected |
| `git fetch origin` then compare locally | Fetch is read-only (never merges). Runs entirely through the wrapper. Comparison uses `git rev-parse` which is purely local. One network call every 5 min is negligible. | **Chosen** |

**Rationale**: Keeps all git operations behind the wrapper. `git fetch origin` only updates remote tracking refs, never touches working tree.

### Decision: Pulsing button — inline CSS vs. Bootstrap utility

**Choice**: Custom `@keyframes pulse` CSS in `styles.css`
**Rationale**: Bootstrap 5 has no built-in pulse animation. A 14-line keyframe animation is simpler and lighter than adding a dependency. The button uses `btn-warning` with the pulse class for visibility.

### Decision: Poll vs. push notification

**Choice**: Polling (5-minute `setInterval`)
**Rationale**: Matches the existing polling pattern in `startChilemonAutoRefresh()` (8-second interval for nodes). No WebSocket/SSE infrastructure needed. 5-minute interval is negligible load for a `git fetch` (usually < 1s on LAN).

## Data Flow

```
[Check — GET /api/check-update.php]

Browser (every 5 min)
  │ GET /api/check-update.php
  ▼
check-update.php
  │ Auth::isLoggedIn()        → 401 if not
  │ RateLimiter::check(30/60) → 429 if exceeded
  ▼
UpdateService::check()
  │ exec("sudo /usr/local/bin/chilemon-rpt git-fetch")
  ▼
bin/chilemon-rpt (as root)
  │ cd /opt/chilemon && git fetch origin 2>&1
  │ echo "OK" on success
  ▼
UpdateService::check() (cont.)
  │ exec("sudo /usr/local/bin/chilemon-rpt git-compare")
  ▼
bin/chilemon-rpt (as root)
  │ cd /opt/chilemon && echo "LOCAL:$(git rev-parse HEAD)"
  │ echo "REMOTE:$(git rev-parse origin/main)"
  │ echo "SUMMARY:$(git log --oneline HEAD..origin/main 2>/dev/null || echo 'up-to-date')"
  ▼
UpdateService::check() (cont.)
  │ Parse LOCAL, REMOTE hashes
  │ update_available = (local !== remote)
  ▼
JSON: { update_available, local_commit, remote_commit, summary }


[Apply — POST /api/apply-update.php]

Admin clicks "Update" → modal → confirm
  │ POST action=apply-update + csrf_token
  ▼
apply-update.php
  │ Auth::requireAdmin()      → 403 if not admin
  │ Validate CSRF             → 400 if invalid
  │ RateLimiter::check(3/120) → 429 if exceeded
  ▼
UpdateService::apply()
  │ exec("sudo /usr/local/bin/chilemon-rpt git-pull")
  ▼
bin/chilemon-rpt (as root)
  │ cd /opt/chilemon
  │ git stash push -m "chilemon-auto-stash-$(date +%s)" 2>/dev/null
  │ git pull origin main 2>&1
  │ echo "PULL_RESULT: $?"
  ▼
UpdateService::apply() (cont.)
  │ Parse result
  │ If success:
  │   systemctl restart chilemon-webrtc
  │   systemctl reload apache2
  ▼
JSON: { success, action: "apply-update", message, stashed, commit }
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `bin/chilemon-rpt` | Modify | Add `git-fetch`, `git-compare`, `git-pull`, `sys-restart-webrtc`, `sys-reload-apache` cases |
| `app/Services/UpdateService.php` | Create | PHP service wrapping chilemon-rpt git commands; uses `ALLOWED` + `run()` pattern |
| `public/api/check-update.php` | Create | GET endpoint: auth, rate-limit (30/60s), calls `UpdateService::check()` |
| `public/api/apply-update.php` | Create | POST endpoint: auth, requireAdmin, CSRF, rate-limit (3/120s), calls `UpdateService::apply()` |
| `public/views/partials/header.php` | Modify | Add pulsing update notification badge/button, visible only for admin |
| `public/views/dashboard.php` | Modify | Add update confirmation modal (reuses `modalReinicio` JS) and inline update-result div |
| `public/assets/js/dashboard.js` | Modify | Add `checkUpdate()`, `applyUpdate()`, polling init (5 min), update UI handler |
| `public/assets/css/styles.css` | Modify | Add `@keyframes pulse-update` and `.btn-update-available` class |

## Interfaces / Contracts

### GET /api/check-update.php — Response
```json
{
  "ok": true,
  "update_available": true,
  "local_commit": "abc123",
  "remote_commit": "def456",
  "summary": "3 commits: Fix PTT timeout, Update README, Add CSRF tests"
}
```
Error: `{ "ok": false, "error": "..." }` with HTTP 401, 429, 500.

### POST /api/apply-update.php — Request
```
POST body: csrf_token=xxx
```
Headers: `Content-Type: application/x-www-form-urlencoded;charset=UTF-8`

### POST /api/apply-update.php — Response
```json
{
  "success": true,
  "action": "apply-update",
  "message": "Update applied. chilemon-webrtc restarted. Apache reloaded.",
  "stashed": false,
  "commit": "def456"
}
```
Error: `{ "success": false, "error": "..." }` with HTTP 400, 403, 429, 500.

### UpdateService::check() return type
```php
/** @return array{ok: bool, update_available: bool, local_commit: string, remote_commit: string, summary: string} */
public function check(): array
```

### UpdateService::apply() return type
```php
/** @return array{success: bool, action: string, message: string, stashed: bool, commit: string} */
public function apply(): array
```

### UpdateService wrapper commands
```
git-fetch   → cd /opt/chilemon && git fetch origin 2>&1, exit on error
git-compare → cd /opt/chilemon && echo "LOCAL:$(git rev-parse HEAD)" && echo "REMOTE:$(git rev-parse origin/main)" && echo "SUMMARY:$(git log --oneline HEAD..origin/main 2>/dev/null || echo 'up-to-date')"
git-pull    → cd /opt/chilemon && git stash push -m "chilemon-auto-stash-$(date +%s)" 2>/dev/null; git pull origin main 2>&1; echo "STASHED:$?"
sys-restart-webrtc  → systemctl restart chilemon-webrtc
sys-reload-apache   → systemctl reload apache2
```

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Unit | `UpdateService` command validation | Test that only `ALLOWED` commands pass; invalid args throw |
| Unit | Check response parsing | Mock exec output with known hashes, verify `update_available` logic |
| Integration | Check endpoint | Start session, GET `/api/check-update.php`, verify JSON shape |
| Integration | Apply endpoint | POST with valid CSRF + admin session, verify 200 JSON (mock git output) |
| E2E | Full flow | Manual: run `git fetch` on Pi, verify badge appears, click update |

Windows mock: `UpdateService` should auto-detect Windows and return mock data (same pattern as `AslRptService` lines 262-284).

## Migration / Rollout

No migration required. The installer (`install/install_chilemon.sh`) already copies `bin/chilemon-rpt` to `/usr/local/bin/`. After `git pull`, the wrapper is updated first; Apache reload picks up the new PHP endpoints. The sudoers entry `www-data ALL=(root) NOPASSWD: /usr/local/bin/chilemon-rpt` already exists from the installation — no change needed.

## Open Questions

- [ ] **git stash — should we surface to user?** If local changes exist, `git stash` is automatic. Should the modal warn "Local changes will be stashed" or just do it silently?
- [ ] **What if git pull has conflicts?** Extremely unlikely on a Pi that only pulls (never modifies tracked files), but should the response surface the conflict message?
