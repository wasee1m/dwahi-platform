@echo off
rem DWAHI-ALBADIA: stop what start-dev.bat started (website, emulator, backend containers). Data is kept.
rem   stop-dev.bat [quiet]   quiet = do not wait for a key at the end
setlocal EnableExtensions
chcp 65001 >nul
title DWAHI-ALBADIA - stop
cd /d "%~dp0"

echo Stopping the website on port 3000...
for /f "tokens=5" %%p in ('netstat -ano ^| findstr /r /c:":3000 .*LISTENING"') do taskkill /PID %%p /T /F >nul 2>&1

echo Stopping the emulator...
if exist "%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe" "%LOCALAPPDATA%\Android\Sdk\platform-tools\adb.exe" emu kill >nul 2>&1

echo Stopping the backend containers (the database is kept)...
docker compose stop

echo.
echo Stopped. Run start-dev.bat to start again.
if /i not "%~1"=="quiet" pause
