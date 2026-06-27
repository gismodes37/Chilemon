# Installation Map Registration Specification

## Purpose

Enable agent nodes (Raspberry Pi with ASL3) to register on the hub installation map cross-origin. Agents without a hub session MAY send hub credentials inline with registration data. Agents WITH a session use the existing CSRF-protected flow.

## Requirements

### Requirement: Credential fallback on hub registration

`public/api/map/register.php` MUST accept `hub_user` + `hub_pass` in POST when no session exists.

- GIVEN POST to `/api/map/register.php` with no session cookie
- WHEN body includes `hub_user` and `hub_pass`
- THEN call `Auth::attemptLogin()` — on success proceed with registration, return `{"ok":true}`
- AND on failure return HTTP 401 `{"ok":false,"error":"..."}` without registering

- GIVEN POST with no session cookie AND no `hub_user`/`hub_pass`
- THEN return HTTP 401 `{"ok":false,"error":"..."}`

- GIVEN 5 failed login attempts in the last 10 min from same IP or username
- WHEN `Auth::attemptLogin()` is called
- THEN return HTTP 429 `{"ok":false,"error":"Demasiados intentos..."}`

### Requirement: Session-based registration preserved

Existing CSRF-protected flow MUST work unchanged when a session exists.

- GIVEN POST to `/api/map/register.php` with valid session cookie AND valid `csrf_token`
- THEN validate CSRF via `Auth::validateCsrf()`, register the node, return `{"ok":true}`
- AND the credential fallback MUST be skipped entirely

- GIVEN POST with valid session but missing/invalid CSRF
- THEN return HTTP 403 `{"ok":false,"error":"..."}` — credential fallback NOT attempted

### Requirement: Agent modal shows login fields conditionally

`public/views/dashboard.php` MUST show/hide `hub_user` + `hub_pass` in the registration modal based on localStorage.

- GIVEN no `chilemon_reg_authenticated` flag in localStorage
- WHEN modal opens
- THEN display `hub_user` and `hub_pass` fields above registration inputs
- AND submit POSTs credentials + registration data to hub

- GIVEN `localStorage.chilemon_reg_authenticated === "1"`
- THEN login fields MUST be hidden
- AND modal behaves identically to current session-based flow

- GIVEN form submitted and hub returns `{"ok":true}`
- THEN show success, set `localStorage.chilemon_reg_authenticated = "1"`, hide banner

- GIVEN form submitted and hub returns HTTP 401
- THEN show error in modal feedback, keep form open, do NOT modify localStorage

- GIVEN agent has active session AND modal submits both credentials + CSRF token
- THEN hub MUST prefer session path, validate CSRF, ignore credential fields in body

### Requirement: Network error handling

- GIVEN form submitted and fetch fails (timeout, DNS, CORS)
- THEN show "Error de conexión: {details}" in modal, keep form open for retry

## Security Constraints

- Credential fallback MUST be rate-limited by `Auth::attemptLogin()` (5 attempts / 10 min per IP + per user)
- Credential path is equivalent to `/login.php` — no CSRF required (auth is the protection)
- Agent MUST store only a boolean flag in localStorage, NEVER the password
- Hub MUST require HTTPS in production when accepting credentials via POST
