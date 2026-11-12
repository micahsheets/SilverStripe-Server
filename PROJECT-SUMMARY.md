# Aurora VPX IPBaseT Matrix Controller — Project Summary

## Overview
A decoupled **Silverstripe 6** (`silverstripe/recipe-core` + `silverstripe/admin` + `silverstripe/asset-admin`) web application and touch-panel interface for controlling **Aurora Multimedia VPX** AV-over-IP Encoders and Decoders over UDP port `6970`.

## Git Branches & Deployment
* **`main`:** Full Silverstripe CMS + AV Matrix build.
* **`framework-only`:** Streamlined build without `silverstripe/cms` or `silverstripe/siteconfig` (recommended for Raspberry Pi 4/5).
* **Multi-Architecture Docker:** Native `arm64` / `amd64` `php:8.3-apache` image with `sockets`, `mysqli`, `pdo_mysql`, `gd` (WebP/JPEG/FreeType), `intl`, `zip`, `bcmath`, and `opcache`.
* **Raspberry Pi Guide:** See `DEPLOY-PI.md` and `docker-compose.pi.yml` (`network_mode: host` for Layer-2 UDP broadcast discovery).

## Architecture & Key Files

### Data Models (`app/src/Model/`)
* **`Location`:** Rooms/zones used to group devices and filter the touch panel.
* **`VPXDevice`:** Encoders (`Mode = Encoder`) and Decoders (`Mode = Decoder`) with `IPAddress`, `UDPPort` (`6970`), `Hostname`, `PowerOnCommand`, `PowerOffCommand`, and active stream subscriptions (`SubscribedVideoEncoderID`, `SubscribedAudioEncoderID`, `SubscribedUSBEncoderID`).
* **`RoutePreset` & `RoutePresetAction`:** Multi-display routing scenes triggered in one tap.
* **`PanelSettings`:** Singleton branding configuration (`PanelTitle`, `PanelLogo`, `PanelBackground`, `BackgroundOverlayOpacity`).
* **`RouteAuditLog`:** Read-only log of every route, preset, and CEC/RS-232 command with automatic probabilistic pruning (`VPX_AUDIT_RETENTION_DAYS`).

### Services & Tasks (`app/src/Service/` & `app/src/Task/`)
* **`VPXUDPService`:** Sends Aurora ASCII/hex-escaped UDP commands (`join`, `leave`, `cec`, `serial`), updates database routing state, probes TCP port `80` for live online/offline status, and records audit logs.
* **`VPXDiscoveryService`:** Parallel non-blocking UDP broadcast + unicast CIDR scanner with HTTP fallback to auto-discover VPX hardware.
* **`PruneAuditLogsTask`:** CLI task (`vendor/bin/sake tasks:PruneAuditLogsTask --days=30`).

### Controllers, Security & UI
* **`AVSystemAdmin` (`app/src/Admin/AVSystemAdmin.php`):** ModelAdmin at `/admin/av-system` managing Devices, Presets, Locations, Panel Branding, and Audit Logs.
* **`UDPController` (`app/src/Controller/UDPController.php`):** JSON API at `/api/vpx/*` (`status`, `route`, `preset`, `command`, `ping`, `discover`, `import`).
* **`TouchPanelController` (`app/src/Controller/TouchPanelController.php`):** Touch UI at `/` and `/panel`, discovery UI at `/panel/discover`, and PIN lock at `/panel/lock`.
* **`VPXSecurityGuard` (`app/src/Security/VPXSecurityGuard.php`):** Enforces `.env` IP subnet allowlists (`VPX_ALLOWED_SUBNETS`), CMS permission or numeric Operator PIN (`VPX_PANEL_PIN`), and `X-CSRF-TOKEN` validation.
* **Frontend Assets:** `public/css/touch-panel.css`, `public/js/panel-api.js`, `public/js/panel-ui.js`, and `public/js/panel-discover.js`.
