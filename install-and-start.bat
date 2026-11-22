@echo off
setlocal EnableDelayedExpansion
cd /d "%~dp0"

echo ==========================================================
echo  AV Touch Panel - Automatic Installer and Bench Launcher
echo ==========================================================
echo.

set "DOCKER_EXE=C:\Program Files\Docker\Docker\Docker Desktop.exe"
set "DOCKER_BIN=C:\Program Files\Docker\Docker\resources\bin"

:: 1. Check if Docker is already installed
if exist "%DOCKER_EXE%" goto CHECK_PATH
where docker >nul 2>&1
if not errorlevel 1 goto CHECK_PATH

echo [1/4] Docker not found. Installing Docker Desktop via Windows Package Manager...
where winget >nul 2>&1
if errorlevel 1 goto NO_WINGET

winget install --id Docker.DockerDesktop -e --accept-source-agreements --accept-package-agreements
if exist "%DOCKER_EXE%" goto CHECK_PATH

echo.
echo Docker Desktop installer finished, or Windows requires a reboot to finish enabling WSL2.
echo If a Docker installer window is still open, let it finish, then double-click install-and-start.bat again.
pause
exit /b 1

:NO_WINGET
echo ERROR: winget is not available on this PC.
echo Please install Docker Desktop manually from https://www.docker.com/products/docker-desktop/
echo Then double-click install-and-start.bat again.
pause
exit /b 1

:CHECK_PATH
if exist "%DOCKER_BIN%" set "PATH=%PATH%;%DOCKER_BIN%"
echo [1/4] Docker Desktop is installed.

:: 2. Start Docker Desktop if the engine is not yet running
docker info >nul 2>&1
if not errorlevel 1 goto DOCKER_READY

echo [2/4] Starting Docker Desktop engine - this may take 30 to 90 seconds on first launch...
if exist "%DOCKER_EXE%" start "" "%DOCKER_EXE%"

set /a TRIES=0
:WAIT_DOCKER
timeout /t 5 /nobreak >nul
docker info >nul 2>&1
if not errorlevel 1 goto DOCKER_READY
set /a TRIES+=1
if !TRIES! GEQ 48 goto DOCKER_TIMEOUT
echo       Waiting for Docker engine - attempt !TRIES! of 48...
goto WAIT_DOCKER

:DOCKER_TIMEOUT
echo.
echo ERROR: Timed out waiting for Docker Desktop to finish starting.
echo Complete any first-run prompts in Docker Desktop, then double-click install-and-start.bat again.
pause
exit /b 1

:DOCKER_READY
echo [2/4] Docker engine is running.

:: 3. Generate .env and port 8080 override if missing
if exist .env goto CHECK_OVERRIDE
> .env echo SS_ENVIRONMENT_TYPE="dev"
>> .env echo SS_DATABASE_SERVER="database"
>> .env echo SS_DATABASE_NAME="SS_mysite"
>> .env echo SS_DATABASE_USERNAME="root"
>> .env echo SS_DATABASE_PASSWORD="root"
>> .env echo SS_DEFAULT_ADMIN_USERNAME="admin"
>> .env echo SS_DEFAULT_ADMIN_PASSWORD="password"
>> .env echo PANEL_REQUIRE_CMS_OR_PIN="true"
>> .env echo PANEL_PANEL_PIN="1234"

:CHECK_OVERRIDE
if exist docker-compose.override.yml goto START_CONTAINERS
> docker-compose.override.yml echo services:
>> docker-compose.override.yml echo   web:
>> docker-compose.override.yml echo     ports:
>> docker-compose.override.yml echo       - "8080:80"

:START_CONTAINERS
echo [3/4] Building and starting AV Touch Panel containers...
docker compose up -d --build
if errorlevel 1 goto COMPOSE_FAIL

echo [4/4] Initializing SilverStripe database schema...
timeout /t 8 /nobreak >nul
docker compose exec -T web sh -c "rm -rf /tmp/silverstripe-cache* silverstripe-cache* && vendor/bin/sake db:build --flush"

echo.
echo ==========================================================
echo  AV Touch Panel Offline Staging Ready!
echo  Admin CMS:   http://localhost:8080/admin
echo  Touch Panel: http://localhost:8080/panel
echo  Login:       admin / password  -  Master PIN: 1234
echo ==========================================================
start http://localhost:8080/admin
pause
exit /b 0

:COMPOSE_FAIL
echo.
echo ERROR: Failed to start containers. Check Docker Desktop status and double-click install-and-start.bat again.
pause
exit /b 1
