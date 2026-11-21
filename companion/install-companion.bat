@echo off
setlocal enabledelayedexpansion
rem One-action Windows onboarding: place config.toml, register logon autostart,
rem then start the companion. Each step keeps its own idempotency and returns a
rem real exit code, so a failure here never reports success.

cd /d "%~dp0.."

set "LOGDIR=%USERPROFILE%\.chilemon"
set "LOG=%LOGDIR%\companion-install.log"
if not exist "%LOGDIR%" mkdir "%LOGDIR%" >nul 2>&1
echo %DATE% %TIME% [INFO] install-companion invoked >>"%LOG%"

rem --config-only stops after config placement (setup without starting).
set "CONFIG_ONLY=0"
set "CFG_ARG="
if /i "%~1"=="--config-only" (
    set "CONFIG_ONLY=1"
    shift
)
if not "%~1"=="" set "CFG_ARG=--config %~1"

echo [ChileMon] Setting up the Companion...

rem 1. Configuration: find/validate the dashboard download and place it.
where python >nul 2>&1
if errorlevel 1 (
    echo [ChileMon] ERROR: Python not found in PATH. Install Python 3.11+ and re-run.
    echo %DATE% %TIME% [ERROR] python not found >>"%LOG%"
    exit /b 1
)
python -m companion.install_config %CFG_ARG%
if errorlevel 1 (
    echo [ChileMon] ERROR: configuration failed. See the message above.
    echo %DATE% %TIME% [ERROR] config step failed >>"%LOG%"
    exit /b 1
)
echo %DATE% %TIME% [INFO] config step OK >>"%LOG%"

if "%CONFIG_ONLY%"=="1" (
    echo [ChileMon] Configuration ready. Autostart and start were skipped.
    exit /b 0
)

rem 2. Autostart: idempotent task / HKCU Run registration.
call "%~dp0install-autostart.bat"
if errorlevel 1 (
    echo [ChileMon] ERROR: could not register autostart.
    echo %DATE% %TIME% [ERROR] autostart step failed >>"%LOG%"
    exit /b 1
)
echo %DATE% %TIME% [INFO] autostart step OK >>"%LOG%"

rem 3. Start: returns non-zero when the listener never becomes ready.
call "%~dp0start-companion.bat"
if errorlevel 1 (
    echo [ChileMon] ERROR: companion did not start. See "%LOGDIR%\companion-startup.log".
    echo %DATE% %TIME% [ERROR] start step failed >>"%LOG%"
    exit /b 1
)

echo %DATE% %TIME% [INFO] install-companion completed OK >>"%LOG%"
echo [ChileMon] Companion installed and running on 127.0.0.1:9093.
echo [ChileMon] In the dashboard press Connect, then connect a node for PTT.
exit /b 0
