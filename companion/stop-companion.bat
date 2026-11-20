@echo off
setlocal enabledelayedexpansion

rem Stop the process listening on the companion WS port.
set "FOUND=0"
for /f "tokens=5" %%P in ('netstat -ano ^| findstr ":9093" ^| findstr "LISTENING"') do (
    echo [ChileMon] Stopping companion PID %%P...
    taskkill /PID %%P /F >nul 2>&1
    set "FOUND=1"
)
if "!FOUND!"=="0" echo [ChileMon] No companion running on 127.0.0.1:9093.
