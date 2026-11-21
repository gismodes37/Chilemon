@echo off
setlocal
rem Create the ChileMon Companion logon task once, then leave it alone.
rem This routine is safe to run on any machine: the task stores an absolute
rem path to the wrapper, so it does not depend on a working directory.

set "TASK_NAME=ChileMon Companion"
set "SCRIPT_DIR=%~dp0"
set "START_BAT=%SCRIPT_DIR%autostart-companion.bat"
set "LOGDIR=%USERPROFILE%\.chilemon"
set "BOOTLOG=%LOGDIR%\companion-startup.log"

if not exist "%LOGDIR%" mkdir "%LOGDIR%" >nul 2>&1

if not exist "%START_BAT%" (
    echo [ChileMon] ERROR: launcher not found: "%START_BAT%".
    echo %DATE% %TIME% [ERROR] autostart launcher missing >>"%BOOTLOG%"
    exit /b 1
)

schtasks /query /tn "%TASK_NAME%" >nul 2>&1
if not errorlevel 1 (
    echo [ChileMon] Scheduled task "%TASK_NAME%" already exists.
    exit /b 0
)

schtasks /create /tn "%TASK_NAME%" /sc onlogon /tr "%START_BAT%" /f >nul 2>&1
if not errorlevel 1 (
    echo [ChileMon] Scheduled task "%TASK_NAME%" created.
    exit /b 0
)

rem Standard users cannot create scheduled tasks on some machines. Fall back
rem to the per-user Run key: no elevation, still runs at every logon.
set "RUNKEY=HKCU\Software\Microsoft\Windows\CurrentVersion\Run"
reg query "%RUNKEY%" /v "%TASK_NAME%" >nul 2>&1
if not errorlevel 1 (
    echo [ChileMon] Autostart entry "%TASK_NAME%" already exists.
    exit /b 0
)

reg add "%RUNKEY%" /v "%TASK_NAME%" /t REG_SZ /d "\"%START_BAT%\"" /f >nul 2>&1
if errorlevel 1 (
    echo [ChileMon] ERROR: could not register autostart for "%TASK_NAME%".
    echo %DATE% %TIME% [ERROR] autostart registration failed >>"%BOOTLOG%"
    exit /b 1
)

echo [ChileMon] Autostart entry "%TASK_NAME%" created.
exit /b 0
