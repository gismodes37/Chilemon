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

rem Keep the console out of the user's way and keep a log for support.
rem --autostart is handled above and is never passed to the Python CLI.
set "LOGDIR=%USERPROFILE%\.chilemon"
if not exist "%LOGDIR%" mkdir "%LOGDIR%" >nul 2>&1
start "ChileMon Companion" /min python -m companion.main --log-file "%LOGDIR%\companion.log"
exit /b 0
