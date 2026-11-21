@echo off
setlocal
cd /d "%~dp0.."

rem Avoid starting a second instance on the same WS port.
netstat -ano | findstr ":9093" | findstr "LISTENING" >nul 2>&1
if %ERRORLEVEL%==0 (
    rem Login autostart must never block on a prompt.
    if /i "%~1"=="--autostart" exit /b 0
    echo [ChileMon] Companion is already running on 127.0.0.1:9093.
    echo [ChileMon] Close this window to continue.
    pause >nul
    exit /b 0
)

echo [ChileMon] Starting ChileMon Companion Audio App...
echo [ChileMon] Press Ctrl+C to stop it, or run stop-companion.bat.
python -m companion.main
