@echo off
setlocal
cd /d "%~dp0.."

rem --autostart is only for the logon task: no prompts, no task creation.

set "LOGDIR=%USERPROFILE%\.chilemon"
set "LOG=%LOGDIR%\companion.log"
set "BOOTLOG=%LOGDIR%\companion-startup.log"
if not exist "%LOGDIR%" mkdir "%LOGDIR%" >nul 2>&1

netstat -ano | findstr ":9093" | findstr "LISTENING" >nul 2>&1
if not errorlevel 1 (
    if /i "%~1"=="--autostart" exit /b 0
    echo [ChileMon] Companion is already running on 127.0.0.1:9093.
    echo [ChileMon] Close this window to continue.
    pause >nul
    exit /b 0
)

rem First manual run installs the logon task; the task itself skips this step.
if /i not "%~1"=="--autostart" (
    call "%~dp0install-autostart.bat" >>"%BOOTLOG%" 2>&1
    if errorlevel 1 (
        echo [ChileMon] Could not install the logon task; see "%BOOTLOG%".
        exit /b 1
    )
)

rem Resolve an absolute Python path so the process does not depend on PATH.
set "PYTHON="
for /f "delims=" %%P in ('where python 2^>nul') do (
    if not defined PYTHON set "PYTHON=%%P"
)
if not defined PYTHON (
    echo %DATE% %TIME% [ERROR] python not found in PATH >>"%BOOTLOG%"
    echo [ChileMon] Python not found in PATH; see "%BOOTLOG%".
    exit /b 1
)

rem cmd /c keeps stderr of a pre-logging failure in the bootstrap log.
start "ChileMon Companion" /min cmd /c ""%PYTHON%" -m companion.main --log-file "%LOG%" >nul 2>>"%BOOTLOG%"

rem `start` can report a stale ERRORLEVEL even when the child launched, so
rem the port check below is the authoritative readiness signal.
set "READY=0"
for /l %%I in (1,1,10) do (
    ping -n 2 127.0.0.1 >nul 2>&1
    netstat -ano | findstr ":9093" | findstr "LISTENING" >nul 2>&1
    if not errorlevel 1 (
        set "READY=1"
        goto :ready
    )
)
:ready
if "%READY%"=="0" (
    echo %DATE% %TIME% [ERROR] companion never listened on 9093 >>"%BOOTLOG%"
    echo [ChileMon] Companion did not become ready; see "%BOOTLOG%".
    exit /b 1
)

echo [ChileMon] Companion ready on 127.0.0.1:9093.
exit /b 0
