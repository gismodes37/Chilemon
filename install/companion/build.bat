@echo off
REM install/companion/build.bat
REM ChileMon Companion App — Windows PyInstaller Build Script
REM
REM Builds a standalone .exe bundle of the companion app for distribution.
REM
REM Requirements:
REM   - Python 3.11+ installed
REM   - PyInstaller installed (pip install pyinstaller)
REM   - sounddevice installed (pip install sounddevice)
REM   - aiohttp installed (pip install aiohttp)
REM   - numpy installed (pip install numpy)
REM
REM Usage:
REM   build.bat
REM
REM Output: dist\chilemon-companion\*

setlocal enabledelayedexpansion

echo ========================================
echo ChileMon Companion App - Windows Builder
echo ========================================

REM --- Root directory (script location -> repo root) ---
set "SCRIPT_DIR=%~dp0"
set "REPO_ROOT=%SCRIPT_DIR%..\..\"

REM Normalize REPO_ROOT (remove trailing space if any)
REM %~dp0 ends with \, so ..\..\ is correct

REM --- Check Python ---
where python >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Python not found. Install Python 3.11+ and try again.
    exit /b 1
)

REM --- Check PyInstaller ---
python -c "import PyInstaller" >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [INFO] Installing PyInstaller...
    pip install pyinstaller
)

REM --- Install companion dependencies ---
echo [INFO] Installing companion dependencies...
pip install sounddevice aiohttp numpy

REM --- Build with PyInstaller ---
echo [INFO] Building companion app...
set "COMPANION_DIR=%REPO_ROOT%companion"

REM --paths adds repo root so app.Services.WebRTCBridge.iax2 resolves
REM --hidden-import ensures iax2 module is bundled in the .exe
REM --collect-all sounddevice bundles sounddevice + its PortAudio DLL

python -m PyInstaller ^
    --onefile ^
    --name "chilemon-companion" ^
    --add-data "%COMPANION_DIR%\config.toml;companion" ^
    --paths "%REPO_ROOT%" ^
    --hidden-import "app.Services.WebRTCBridge.iax2" ^
    --hidden-import "aiohttp" ^
    --hidden-import "sounddevice" ^
    --hidden-import "numpy" ^
    --collect-all "sounddevice" ^
    --distpath "%REPO_ROOT%dist" ^
    --workpath "%REPO_ROOT%build" ^
    --specpath "%REPO_ROOT%" ^
    --clean ^
    --log-level INFO ^
    "%COMPANION_DIR%\main.py"

if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Build failed.
    exit /b 1
)

echo ========================================
echo Build complete!
echo Output: %REPO_ROOT%dist\chilemon-companion.exe
echo ========================================
echo.
echo Next steps:
echo   1. Copy chilemon-companion.exe to the target machine
echo   2. Create %%USERPROFILE%%\.chilemon\config.toml (see companion\config.toml)
echo   3. Run: chilemon-companion.exe --config %%USERPROFILE%%\.chilemon\config.toml
echo.
echo NOTE: On first run, Windows may show a SmartScreen warning.
echo       Click "More info" -^> "Run anyway" to proceed.
echo.
echo Troubleshooting:
echo   - No audio? Run: python -m sounddevice to list devices
echo   - Can't connect? Check Asterisk iax.conf and firewall
