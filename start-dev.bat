@echo off
rem DWAHI-ALBADIA: run the whole project locally (backend in Docker, website, and optionally the mobile apps).
rem Double-click to get a menu, or:  start-dev.bat [web|customer|tech|all] [quiet]
rem   quiet = do not open the browser and do not wait for a key at the end.
setlocal EnableExtensions EnableDelayedExpansion
chcp 65001 >nul
title DWAHI-ALBADIA - start
cd /d "%~dp0"

set "MODE=%~1"
set "QUIET=%~2"
set "SDK=%LOCALAPPDATA%\Android\Sdk"
set "DOCKER_APP=%LOCALAPPDATA%\Programs\DockerDesktop\Docker Desktop.exe"
if not exist "%DOCKER_APP%" set "DOCKER_APP=%ProgramFiles%\Docker\Docker\Docker Desktop.exe"

if "%MODE%"=="" (
  echo.
  echo  DWAHI-ALBADIA - what do you want to run?
  echo    1  Website and admin panel
  echo    2  Website + customer app on the emulator
  echo    3  Website + technician app on the emulator
  echo    4  Website + both apps
  echo.
  choice /c 1234 /n /m " Choose 1-4: "
  if errorlevel 4 (set "MODE=all") else if errorlevel 3 (set "MODE=tech") else if errorlevel 2 (set "MODE=customer") else (set "MODE=web")
)

rem ---------- 1) Docker and the backend ----------
echo.
echo [1/4] Docker...
docker info >nul 2>&1
if not errorlevel 1 goto :docker_ok
if not exist "%DOCKER_APP%" goto :no_docker
echo  Starting Docker Desktop, this can take a minute...
start "" "%DOCKER_APP%"
set /a tries=0
:wait_docker
ping -n 6 127.0.0.1 >nul
docker info >nul 2>&1
if not errorlevel 1 goto :docker_ok
set /a tries+=1
if !tries! geq 40 goto :docker_slow
goto :wait_docker
:no_docker
echo  Docker Desktop was not found. Install it, then run this file again.
goto :fail
:docker_slow
echo  Docker did not start within 3 minutes.
goto :fail
:docker_ok
echo  Docker is running.

echo [2/4] Backend containers: API, MySQL, Redis, queue worker, scheduler...
docker compose up -d
if errorlevel 1 goto :fail
set /a tries=0
:wait_api
set "CODE=000"
for /f %%c in ('curl -s -o nul -w "%%{http_code}" http://localhost:8000/api/v1/health') do set "CODE=%%c"
if "%CODE%"=="200" goto :api_ok
set /a tries+=1
if !tries! geq 60 (
  echo  The API did not become healthy. Check: docker compose logs api
  goto :fail
)
ping -n 4 127.0.0.1 >nul
goto :wait_api
:api_ok
echo  API is healthy: http://localhost:8000/api/v1/health

rem ---------- 2) The website ----------
echo [3/4] Website...
netstat -ano | findstr /r /c:":3000 .*LISTENING" >nul
if not errorlevel 1 (
  echo  The website is already running on port 3000.
  goto :web_ok
)
if not exist "frontend\node_modules" (
  echo  Installing website packages the first time...
  pushd frontend
  call npm ci
  popd
)
start "DWAHI website" cmd /k "cd /d %~dp0frontend && npm run dev"
set /a tries=0
:wait_web
set "CODE=000"
for /f %%c in ('curl -s -o nul -w "%%{http_code}" http://localhost:3000/ar') do set "CODE=%%c"
if "%CODE%"=="200" goto :web_ok
set /a tries+=1
if !tries! geq 60 (
  echo  The website did not start. Look at the "DWAHI website" window.
  goto :fail
)
ping -n 4 127.0.0.1 >nul
goto :wait_web
:web_ok
echo  Website: http://localhost:3000/ar    Admin: http://localhost:3000/ar/admin
if /i not "%QUIET%"=="quiet" start "" http://localhost:3000/ar

rem ---------- 3) Mobile apps (optional) ----------
if /i "%MODE%"=="web" goto :done
echo [4/4] Android emulator...
if not exist "%SDK%\emulator\emulator.exe" (
  echo  Android SDK was not found in %SDK%. Skipping the apps.
  goto :done
)
set "ADB=%SDK%\platform-tools\adb.exe"
"%ADB%" devices | findstr /r /c:"^emulator-.*device" >nul
if not errorlevel 1 goto :boot_ok
rem Software rendering: the default GPU mode crashes this emulator on this machine
start "" "%SDK%\emulator\emulator.exe" -avd Pixel_8_Pro -gpu swiftshader_indirect -no-snapshot-load -no-snapshot-save -no-boot-anim
"%ADB%" wait-for-device
set /a tries=0
:wait_boot
set "BOOT="
for /f %%b in ('call "%ADB%" shell getprop sys.boot_completed 2^>nul') do set "BOOT=%%b"
if "!BOOT!"=="1" goto :boot_ok
set /a tries+=1
if !tries! geq 60 goto :boot_slow
ping -n 6 127.0.0.1 >nul
goto :wait_boot
:boot_slow
echo  The emulator did not finish booting.
goto :done
:boot_ok
echo  Emulator is ready.
if /i "%MODE%"=="customer" start "DWAHI customer app" cmd /k "cd /d %~dp0mobile\customer_app && flutter run -d emulator"
if /i "%MODE%"=="tech" start "DWAHI technician app" cmd /k "cd /d %~dp0mobile\technician_app && flutter run -d emulator"
if /i "%MODE%"=="all" (
  start "DWAHI customer app" cmd /k "cd /d %~dp0mobile\customer_app && flutter run -d emulator"
  start "DWAHI technician app" cmd /k "cd /d %~dp0mobile\technician_app && flutter run -d emulator"
)
echo  The app builds in its own window, the first time takes a few minutes.

:done
echo.
echo  Ready. Test accounts: docs\test-accounts.md
echo  To stop everything: stop-dev.bat
if /i not "%QUIET%"=="quiet" pause
exit /b 0

:fail
echo.
echo  Could not start the project.
if /i not "%QUIET%"=="quiet" pause
exit /b 1
