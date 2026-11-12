# Raspberry Pi 4 / 5 Deployment Guide (Aurora VPX Matrix)

## 1. Install Docker on Raspberry Pi OS (64-bit)
```bash
curl -fsSL https://docker.com | sh
sudo usermod -aG docker \$USER
newgrp docker
```

## 2. Clone Repository & Select Branch
```bash
git clone <your-github-repo-url> vpx-matrix
cd vpx-matrix
git checkout framework-only
```

## 3. Configure Production `.env`
Create a `.env` file in the project root (update the subnet CIDR, PIN, and admin password):
```env
SS_ENVIRONMENT_TYPE="live"
SS_DEFAULT_ADMIN_USERNAME="admin"
SS_DEFAULT_ADMIN_PASSWORD="ChangeThisStrongPassword!"
VPX_ALLOWED_SUBNETS="127.0.0.1,192.168.1.0/24"
VPX_PANEL_PIN="2468"
VPX_REQUIRE_CMS_OR_PIN="true"
VPX_AUDIT_RETENTION_DAYS="30"
```

## 4. Build & Start Containers (Host Network Mode)
```bash
docker compose -f docker-compose.yml -f docker-compose.pi.yml up -d --build
docker compose -f docker-compose.yml -f docker-compose.pi.yml exec web composer install --no-dev --optimize-autoloader
docker compose -f docker-compose.yml -f docker-compose.pi.yml exec web vendor/bin/sake dev/build flush=1
```

## 5. Access the System
* **Touch Panel UI:** `http://<raspberry-pi-ip>/`
* **Network Discovery:** `http://<raspberry-pi-ip>/panel/discover`
* **AV System Admin:** `http://<raspberry-pi-ip>/admin`

## 6. Optional: Daily Cron Job for Audit Log Pruning
Run `crontab -e` on the Raspberry Pi and add:
```cron
0 3 * * * docker compose -f /home/pi/vpx-matrix/docker-compose.yml -f /home/pi/vpx-matrix/docker-compose.pi.yml exec -T web vendor/bin/sake tasks:PruneAuditLogsTask >> /var/log/vpx-prune.log 2>&1
```
