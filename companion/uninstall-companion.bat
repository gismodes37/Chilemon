@echo off
setlocal
rem Thin wrapper around the testable Python uninstaller. Every flag is
rem forwarded unchanged: --dry-run previews, --keep-config preserves config
rem and logs, --yes skips the confirmation prompt.

cd /d "%~dp0.."

where python >nul 2>&1
if errorlevel 1 (
    echo [ChileMon] ERROR: Python 3.11+ was not found in PATH.
    exit /b 1
)

python -m companion.uninstall %*
exit /b %ERRORLEVEL%
