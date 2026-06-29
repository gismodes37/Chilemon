# One-Click Update Specification

## Purpose

Allow admin operators to update ChileMon from the dashboard — check for new versions on GitHub, apply via `git pull`, and restart services — without SSH access.

## Requirements

### Requirement: Check for Updates

The system MUST expose `GET /api/check-update.php` that compares the local Git HEAD against `origin/main`.

- The endpoint MUST require a valid authenticated session.
- The endpoint MUST be rate-limited to 30 requests per 60 seconds per session.
- The endpoint MUST execute `git fetch origin` via `bin/chilemon-rpt git-fetch`.
- The endpoint MUST compare `HEAD` with `origin/main` and return JSON: `{update_available: bool, local_commit: string, remote_commit: string, summary: string}`.
- The endpoint MUST return `update_available: false` when HEAD matches `origin/main`.

#### Scenario: Update available

- GIVEN the local repo is behind `origin/main`
- WHEN `GET /api/check-update.php` is called with a valid session
- THEN the response MUST contain `update_available: true` with the remote commit SHA and a summary

#### Scenario: No update available

- GIVEN the local HEAD matches `origin/main`
- WHEN `GET /api/check-update.php` is called
- THEN the response MUST contain `update_available: false`

#### Scenario: Unauthenticated request returns 401

- GIVEN no valid session cookie
- WHEN `GET /api/check-update.php` is called
- THEN the response MUST be HTTP 401

#### Scenario: Rate limit exceeded

- GIVEN 30 requests in the last 60 seconds
- WHEN the 31st request to `GET /api/check-update.php` is made
- THEN the response MUST be HTTP 429

### Requirement: Apply Update

The system MUST expose `POST /api/apply-update.php` that pulls latest code and restarts services.

- The endpoint MUST require an authenticated session with admin role.
- The endpoint MUST require a valid CSRF token via `X-CSRF-Token` header.
- The endpoint MUST be rate-limited to 5 requests per 60 seconds per session.
- On success, the endpoint MUST execute: `git pull origin main`, `systemctl restart chilemon-webrtc`, `systemctl reload apache2`, all via `bin/chilemon-rpt git-pull`.
- The endpoint MUST return JSON: `{success: bool, message: string, commit: string|null}`.

#### Scenario: Successful update

- GIVEN an admin session with valid CSRF token and an available update
- WHEN `POST /api/apply-update.php` is called
- THEN the endpoint MUST run `git pull`, restart services, and return `{success: true}`

#### Scenario: Non-admin user is rejected

- GIVEN a non-admin session with valid CSRF token
- WHEN `POST /api/apply-update.php` is called
- THEN the response MUST be HTTP 403

#### Scenario: Invalid CSRF token is rejected

- GIVEN an admin session with an invalid or missing `X-CSRF-Token`
- WHEN `POST /api/apply-update.php` is called
- THEN the response MUST be HTTP 403

### Requirement: Admin-Only Update Notification

The system MUST show a pulsing update-available badge in the dashboard header for admin users only.

- The badge MUST poll `GET /api/check-update.php` every 5 minutes.
- The badge MUST pulse visually when `update_available` is true.
- Non-admin users MUST NOT see any update UI.

#### Scenario: Admin sees badge when update is available

- GIVEN an admin session and `check-update` returns `update_available: true`
- WHEN the header renders
- THEN a pulsing badge MUST be visible with an update action

#### Scenario: Non-admin never sees badge

- GIVEN a non-admin session
- WHEN the header renders (regardless of update availability)
- THEN no update badge or element MUST be present in the DOM

### Requirement: Update Confirmation and Post-Update Flow

The system MUST prompt the admin to confirm before applying an update and reload the page after completion.

- Clicking the update badge MUST open a confirmation modal showing the pending changes summary.
- Confirming MUST call `POST /api/apply-update.php`.
- On success, the page MUST reload after a 5-second countdown to allow service restart.
- The JS client MUST retry the reload if the connection drops during Apache reload.

#### Scenario: Confirmation modal displays summary

- GIVEN an admin clicks the update badge
- WHEN `update_available` is true and changes summary is available
- THEN a modal MUST display the commit summary with Confirm and Cancel buttons

#### Scenario: Page reloads after successful apply

- GIVEN the admin confirms the update and the server returns `success: true`
- WHEN the apply endpoint responds
- THEN the page MUST display a countdown and reload after 5 seconds

### Requirement: Git Operations via Sudo Wrapper

The system MUST extend `bin/chilemon-rpt` with `git-fetch` and `git-pull` commands, executed as root via sudo.

- `bin/chilemon-rpt git-fetch` MUST run `git fetch origin` in `/opt/chilemon`.
- `bin/chilemon-rpt git-pull` MUST run `git pull origin main`, then `systemctl restart chilemon-webrtc`, then `systemctl reload apache2`.
- A sudoers entry MUST allow `www-data` to run `/usr/bin/git` as root without a password.
- The repo path MUST be configurable via `GIT_REPO_PATH` constant in `config/app.php`.

#### Scenario: Git fetch via wrapper

- GIVEN `bin/chilemon-rpt git-fetch` is executed as root
- THEN `git fetch origin` MUST run in the configured repo path
- AND stderr MUST be captured and returned on failure

#### Scenario: Git pull via wrapper

- GIVEN `bin/chilemon-rpt git-pull` is executed as root
- THEN `git pull origin main` MUST run, followed by service restarts only on success
