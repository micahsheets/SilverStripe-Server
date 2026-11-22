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

# Use sudo docker on Linux if current user isn't in docker group yet
DOCKER_CMD="docker"
if [ "$OS" = "Linux" ] && ! docker info >/dev/null 2>&1; then
    if sudo docker info >/dev/null 2>&1; then
        DOCKER_CMD="sudo docker"
    fi
fi

if ! $DOCKER_CMD info >/dev/null 2>&1; then
    echo "[2/4] Starting Docker engine..."
    if [ "$OS" = "Darwin" ]; then
        open -a Docker
    elif [ "$OS" = "Linux" ]; then
        sudo systemctl start docker || true
        DOCKER_CMD="sudo docker"
    fi

    TRIES=0
    until $DOCKER_CMD info >/dev/null 2>&1; do
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
SS_ENVIRONMENT_TYPE="live"
SS_DATABASE_SERVER="database"
SS_DATABASE_NAME="SS_mysite"
SS_DATABASE_USERNAME="root"
SS_DATABASE_PASSWORD="root"
PANEL_REQUIRE_CMS_OR_PIN="true"
PANEL_PANEL_PIN="1234"
ENVEOF
fi

if [ ! -f docker-compose.override.yml ]; then
    cat << 'OVREOF' > docker-compose.override.yml
services:
  web:
    restart: unless-stopped
    ports:
      - "8080:80"
  database:
    restart: unless-stopped
OVREOF
fi

echo "[3/4] Building and starting AV Touch Panel containers..."
$DOCKER_CMD compose up -d --build

echo "[4/4] Initializing SilverStripe database, assets, and admin account..."
for i in $(seq 1 15); do
    if $DOCKER_CMD compose exec -T database mariadb-admin ping -h localhost -uroot -proot --silent >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

$DOCKER_CMD compose exec -T web sh -c '
  mkdir -p public/_resources/vendor/silverstripe public/assets
  if [ ! -d vendor/silverstripe ]; then
    composer install --no-dev --no-interaction --optimize-autoloader
  fi
  for pkg in admin asset-admin framework htmleditor-tinymce login-forms; do
    if [ -d "vendor/silverstripe/$pkg/client" ] && [ ! -d "public/_resources/vendor/silverstripe/$pkg/client" ]; then
      mkdir -p "public/_resources/vendor/silverstripe/$pkg"
      cp -r "vendor/silverstripe/$pkg/client" "public/_resources/vendor/silverstripe/$pkg/client"
    fi
  done
  chown -R www-data:www-data public/_resources public/assets
  rm -rf /tmp/silverstripe-cache* silverstripe-cache*
  vendor/bin/sake db:build --flush
'

# Seed initial DB admin user ONLY if no Member exists yet (so /admin/security password changes always work)
$DOCKER_CMD compose exec -T web php -r '
  require "vendor/autoload.php";
  use SilverStripe\Control\HTTPApplication;
  use SilverStripe\Control\HTTPRequestBuilder;
  use SilverStripe\Core\CoreKernel;
  use SilverStripe\Security\Member;
  use SilverStripe\Security\Group;
  use SilverStripe\Security\Permission;

  $_SERVER["REQUEST_METHOD"] = "GET";
  $request = HTTPRequestBuilder::createFromEnvironment();
  $kernel = new CoreKernel(BASE_PATH);
  $app = new HTTPApplication($kernel);
  $app->execute($request, function () {
    if (Member::get()->count() === 0) {
      $group = Group::get()->filter("Code", "administrators")->first();
      if (!$group) {
        $group = Group::create();
        $group->Title = "Administrators";
        $group->Code = "administrators";
        $group->write();
        Permission::grant($group->ID, "ADMIN");
      }
      $member = Member::create();
      $member->FirstName = "Admin";
      $member->Email = "admin";
      $member->Password = "password";
      $member->write();
      $group->Members()->add($member);
      echo "Seeded initial DB admin user (admin / password).\n";
    }
  }, true);
'

echo ""
echo "=========================================================="
echo " AV Touch Panel Ready!"
echo " Admin CMS:   http://localhost:8080/admin"
echo " Touch Panel: http://localhost:8080/panel"
echo " Default Login: admin / password  (Master PIN: 1234)"
echo " Change your admin password anytime in /admin/security"
echo "=========================================================="

if command -v open >/dev/null 2>&1; then
    open "http://localhost:8080/admin"
elif command -v xdg-open >/dev/null 2>&1; then
    xdg-open "http://localhost:8080/admin"
fi
