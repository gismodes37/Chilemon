# Proposal: feat/one-click-update — One-Click Update Button

## Intent

Operators update ChileMon via SSH (`git pull` + `systemctl restart`). One-click from the dashboard eliminates the SSH bottleneck — check, apply, restart, all from the web UI.

## Scope

### In Scope
- `GET /api/check-update.php` — compares local HEAD vs `origin/main`
- `POST /api/apply-update.php` — `git pull` + service restart
- Pulsing notification in header (admin-only)
- Confirmation modal + post-update page reload
- sudoers extension for www-data git operations

### Out of Scope
- Auto-updates, branch selection, rollback, update history, multi-node

## Capabilities

### New Capabilities
- `one-click-update`: One-click ChileMon update — checks GitHub for newer version, applies via `git pull`, restarts services

### Modified Capabilities
- None

## Approach

1. **Extend `bin/chilemon-rpt`** — add `git-fetch` and `git-pull` cases. Runs as root via sudo, repo at `/opt/chilemon`.
2. **Check endpoint** — GET, authenticates, rate-limited (30/60s). Calls `git fetch origin`, compares HEAD with `origin/main`, returns `{update_available, local_commit, remote_commit, summary}`.
3. **Update endpoint** — POST, requires admin + CSRF + rate limiting (same pattern as `system_action.php`). Runs `git pull origin main`, then `systemctl restart chilemon-webrtc`, then `systemctl reload apache2`.
4. **Widget** — pulsing button in `header.php` visible only to admin. Polls check endpoint every 5 min.
5. **Modal** — reuses existing `modalReinicio` pattern from `dashboard.php`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `bin/chilemon-rpt` | Modified | +`git-fetch`, +`git-pull` |
| `public/api/check-update.php` | New | Check for updates |
| `public/api/apply-update.php` | New | Apply update |
| `public/views/partials/header.php` | Modified | +Update available badge |
| `public/views/dashboard.php` | Modified | +Update confirmation modal |
| `public/assets/js/dashboard.js` | Modified | +checkUpdate(), +applyUpdate() |
| `config/app.php` | Modified | +`GIT_REPO_PATH` |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Git pull conflict (local changes) | Low | `git stash` before pull, report conflicts |
| Apache restart kills HTTP response | Med | `systemctl reload` (graceful), JS retries |
| www-data sudo permission | Low | New sudoers entry covers this |

## Rollback Plan

SSH as root: `cd /opt/chilemon && git reset --hard HEAD@{1} && systemctl restart chilemon-webrtc && systemctl reload apache2`.

## Dependencies

- `git` on Pi, new sudoers entry `www-data ALL=(root) NOPASSWD: /usr/bin/git`
- `chilemon-webrtc` systemd service + Apache 2.4+ with `systemctl reload`

## Success Criteria

- [ ] Check returns `update_available: false` when local matches remote
- [ ] Check returns `update_available: true` when local is behind
- [ ] Apply pulls latest code and returns `success: true`
- [ ] chilemon-webrtc restarts after update; Apache reloads gracefully
- [ ] Pulsing notification visible only to admin users
- [ ] Non-admin users see no update UI
