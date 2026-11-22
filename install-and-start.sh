#!/usr/bin/env bash
set -e
cd "$(dirname "$0")"

echo "=========================================================="
echo " AV Touch Panel - Automatic Installer & Bench Launcher"
echo "=========================================================="

OS="$(uname -s)"
ARCH="$(uname -m)"

if ! command -v docker >/dev/null 2>&1; then
    echo "[1/4] Docker not found. Installing Docker automatically..."
    if [ "$OS" = "Darwin" ]; then
        if command -v brew >/dev/null 2>&1; then
            brew install --cask docker
        else
            DMG_URL="https://desktop.docker.com/mac/main/arm64/Docker.dmg"
            [ "$ARCH" = "x86_64" ] && DMG_URL="https://desktop.docker.com/mac/main/amd64/Docker.dmg"
            TMP_DMG="/tmp/DockerDesktop.dmg"
            echo "Downloading Docker Desktop for macOS..."
            curl -fL "$DMG_URL" -o "$TMP_DMG"
            hdiutil attach "$TMP_DMG" -nobrowse -quiet
            cp -R "/Volumes/Docker/Docker.app" /Applications/
            hdiutil detach "/Volumes/Docker" -quiet || true
            rm -f "$TMP_DMG"
        fi
    elif [ "$OS" = "Linux" ]; then
        curl -fsSL https://get.docker.com | sh
        sudo systemctl enable --now docker || true
        sudo usermod -aG docker "$USER" || true
    else
        echo "Unsupported OS ($OS). Install Docker Desktop manually and re-run."
        exit 1
    fi
else
    echo "[1/4] Docker CLI detected."
fi

if ! docker info >/dev/null 2>&1; then
    echo "[2/4] Starting Docker engine..."
    if [ "$OS" = "Darwin" ]; then
        open -a Docker
    elif [ "$OS" = "Linux" ]; then
        sudo systemctl start docker || true
    fi

    TRIES=0
    until docker info >/dev/null 2>&1; do
        TRIES=$((TRIES + 1))
        if [ "$TRIES" -ge 36 ]; then
            echo "Timed out waiting for Docker to start. Complete any first-run prompt in Docker Desktop and re-run."
            exit 1
        fi
        echo "      Waiting for Docker engine ($TRIES/36)..."
        sleep 5
    done
fi
echo "[2/4] Docker engine is running."

if [ ! -f .env ]; then
    cat << 'ENVEOF' > .env
SS_ENVIRONMENT_TYPE="dev"
SS_DATABASE_SERVER="database"
SS_DATABASE_NAME="SS_mysite"
SS_DATABASE_USERNAME="root"
SS_DATABASE_PASSWORD="root"
SS_DEFAULT_ADMIN_USERNAME="admin"
SS_DEFAULT_ADMIN_PASSWORD="password"
PANEL_REQUIRE_CMS_OR_PIN="true"
PANEL_PANEL_PIN="1234"
ENVEOF
fi

if [ ! -f docker-compose.override.yml ]; then
    cat << 'OVREOF' > docker-compose.override.yml
services:
  web:
    ports:
      - "8080:80"
OVREOF
fi

echo "[3/4] Building and starting AV Touch Panel containers..."
docker compose up -d --build

echo "[4/4] Initializing SilverStripe database schema..."
sleep 6
docker compose exec -T web sh -c "rm -rf /tmp/silverstripe-cache* silverstripe-cache* && vendor/bin/sake db:build --flush"

echo ""
echo "=========================================================="
echo " AV Touch Panel Offline Staging Ready!"
echo " Admin CMS:   http://localhost:8080/admin"
echo " Touch Panel: http://localhost:8080/panel"
echo " Login:       admin / password  (Master PIN: 1234)"
echo "=========================================================="

if command -v open >/dev/null 2>&1; then
    open "http://localhost:8080/admin"
elif command -v xdg-open >/dev/null 2>&1; then
    xdg-open "http://localhost:8080/admin"
fi
