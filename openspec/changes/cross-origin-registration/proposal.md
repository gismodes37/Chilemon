# Proposal: Cross-Origin Installation Registration

## Intent

Enable agent nodes (Raspberry Pi with ASL3) to register themselves on the hub installation map without needing a pre-established session or proxy. Currently the flow only works in HUB_MODE (same server) because the registration POST requires the hub's session cookie + CSRF token — impossible cross-origin.

## Scope

### In Scope

- Hub `register.php`: accept `hub_user` + `hub_pass` in POST body as a login fallback when no session exists
- Agent `dashboard.php`: add login fields to the registration modal for first-time users; POST credentials alongside registration data
- Rate limiting: existing `Auth::attemptLogin()` rate limiting protects the credential fallback path

### Out of Scope

- TURN/STUN or tunnel-based approaches (Option 1 rejected)
- Multi-user session management on the bridge
- Admin approval workflow (already exists on hub side)

## Capabilities

### New Capabilities

- `installation-map-registration`: Cross-origin node registration flow — credentials sent inline, hub authenticates and registers in one request

### Modified Capabilities

None — no existing spec covers this flow's cross-origin concerns.

## Approach

**Option 2 — Direct POST with credentials fallback:**

1. Hub `register.php` checks `Auth::isLoggedIn()` first. If no session, looks for `hub_user` + `hub_pass` in POST body.
2. If credentials present, calls `Auth::attemptLogin()`. On success, proceeds with registration and returns a session-establishing response (JSON with success + optional session cookie header).
3. Agent `dashboard.php` registration modal shows login fields (`hub_user`, `hub_pass`) when user has no stored credentials. On submit, sends both auth and registration fields via `fetch()` to hub.
4. On successful response, stores a flag in `localStorage` to skip login fields next time.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `public/api/map/register.php` (hub) | Modified | Add credential fallback before `requireLogin()` |
| `public/views/dashboard.php` (agent) | Modified | Add login fields to registration modal, conditional on auth state |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Credential exposure in transit | Medium | Require HTTPS between agent and hub (document in setup) |
| Brute-force login via register endpoint | Low | `Auth::attemptLogin()` already has rate limiting (5 attempts / 10 min) |
| CSRF bypass via credential fallback | Low | Credential path skips CSRF but requires login — same level of protection as `/login.php` itself |

## Rollback Plan

- **Hub**: Revert `register.php` to previous version. Remove POST credential check and restore `requireLogin()` at the top.
- **Agent**: Revert `dashboard.php` to remove login fields in registration modal.
- Both changes are isolated — rollback one without the other restores the prior broken state, which is acceptable.

## Dependencies

- Access to hub server (192.168.0.111) to deploy `register.php` changes
- HTTPS between agent and hub (recommended before production use)

## Success Criteria

- [ ] Agent without hub session can submit registration via modal → receives `{"ok":true}` from hub
- [ ] Hub `register.php` with existing session still works (no regression)
- [ ] Rate limiting correctly blocks excessive login attempts through register endpoint
- [ ] Invalid credentials return `{"ok":false, "error": "..."}` without registration attempt
