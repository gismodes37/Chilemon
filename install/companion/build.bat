@echo off
REM install/companion/build.bat
REM ChileMon Companion App — Windows PyInstaller Build Script
REM
REM Builds a standalone .exe bundle of the companion app for distribution.
REM
REM Requirements:
REM   - Python 3.10+ installed
REM   - PyInstaller installed (pip install pyinstaller)
REM   - pyaudio installed (pip install pyaudio)
REM   - aiohttp installed (pip install aiohttp)
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
set "REPO_ROOT=%SCRIPT_DIR%..\.."

REM --- Check Python ---
where python >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo [ERROR] Python not found. Install Python 3.10+ and try again.
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
pip install pyaudio aiohttp tomli-w

REM --- Build with PyInstaller ---
echo [INFO] Building companion app...
set "COMPANION_DIR=%REPO_ROOT%\companion"
set "APP_ROOT=%REPO_ROOT%"

python -m PyInstaller ^
    --onefile ^
    --name "chilemon-companion" ^
    --add-data "%COMPANION_DIR%\config.toml;." ^
    --hidden-import "aiohttp" ^
    --hidden-import "pyaudio" ^
    --distpath "%REPO_ROOT%\dist" ^
    --workpath "%REPO_ROOT%\build" ^
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
echo Output: %REPO_ROOT%\dist\chilemon-companion.exe
echo ========================================
echo.
echo Next steps:
echo   1. Copy chilemon-companion.exe to the target machine
echo   2. Create %%USERPROFILE%%\.chilemon\config.toml (see companion\config.toml)
echo   3. Run: chilemon-companion.exe --config %%USERPROFILE%%\.chilemon\config.toml
echo.
echo NOTE: pyaudio requires the PortAudio DLL. If the exe doesn't start,
echo install PortAudio from: https://files.portaudio.com/download.htm
