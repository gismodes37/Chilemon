@echo off
setlocal
rem Logon-task target. No arguments keeps the schtasks /tr command simple.
call "%~dp0start-companion.bat" --autostart
exit /b %ERRORLEVEL%
