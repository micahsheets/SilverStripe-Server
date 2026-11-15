# Silverstripe 6 AV Control System — Project Summary & Handoff

## 1. Environment & Stack Conventions
* **Framework:** Silverstripe 6 (`silverstripe/framework` + `silverstripe/admin` only; `silverstripe/cms` and `silverstripe/siteconfig` removed).
* **Database:** MariaDB using `mysqli` driver (`SS_DATABASE_CLASS=MySQLDatabase`).
* **Runtime & Host:** Docker Compose on macOS (`zsh` shell).
  * PHP/Apache service container name: `web` (`php:8.3-apache` with OPcache enabled).
  * Build/flush & reload command:
    ```zsh
    docker compose exec web vendor/bin/sake db:build --flush && docker compose restart web
    ```
  * Inline PHP CLI commands in `zsh` must use heredocs (`docker compose exec -T web php << 'EOF' ... EOF`) to avoid shell quoting errors.
* **Code Formatting Rules for Handoff:**
  * Split files over ~180 lines into 2–3 copyable parts.
  * Prefer `array()` over empty `[]` at the end of lines when sharing code snippets.

---

## 2. Admin Panel & Routing Architecture
* **Admin Interface (`/admin/av-system`):** Managed via `App\Admin\AVSystemAdmin` (`ModelAdmin`) with `$managed_models`:
  * `App\Model\VPXDevice`
  * `App\Model\RoutePreset`
  * `App\Model\EnttecDevice`
  * `App\Model\Location`
  * `App\Model\PanelSettings`
  * `App\Model\RouteAuditLog`
* **CMS Permission Cleanup (`app/_config/permissions.yml`):** Hides leftover `SITETREE_*`, `VIEW_DRAFT_CONTENT`, `EDIT_SITECONFIG`, `CMS_ACCESS_CMSMain`, and unused report/model admin permissions via `SilverStripe\Security\Permission.hidden_permissions`.
* **URL Routes (`app/_config/routes.yml`):**
  ```yaml
  ---
  Name: approutes
  After:
    - '#rootroutes'
    - '#coreroutes'
  ---
  SilverStripe\Control\Director:
    rules:
      '': 'App\Controller\TouchPanelController'
      'panel/lighting//Action/ID': 'App\Controller\EnttecController'
      'panel//Action/ID': 'App\Controller\TouchPanelController'
      'api/vpx//Action/ID': 'App\Controller\UDPController'
      'api/enttec//Action/ID': 'App\Controller\EnttecController'
  ```

---

## 3. Authentication, Granular Permissions & Audit Logging
* **Three-Tier PIN Authentication (`App\Security\VPXSecurityGuard::verifyPIN`):**
  1. **Tier 1 — Individual User PIN (`/admin/security`):** `App\Extension\MemberPINExtension` adds `PanelPIN` (`Varchar(255)`, bcrypt-hashed) to `SilverStripe\Security\Member`. Uses masked `TextField` (`NewPanelPIN`) with `autocomplete="one-time-code"` to prevent browser password autofill. Read via `$member->getField('PanelPIN')`.
  2. **Tier 2 — Shared Basic PIN (`/admin/av-system`):** Configured on `PanelSettings` (`BasicPanelPIN`) with checkbox selection of allowed interfaces (`BasicAllowedPermissions`).
  3. **Tier 3 — Emergency Master PIN (`.env`):** `VPX_PANEL_PIN` fallback; records an audit log entry whenever used.
* **Session Persistence:** `TouchPanelController::index()`, `unlock()`, and `VPXSecurityGuard::lock()` explicitly call `$session->save($request)` so session state persists across redirects.
* **Custom Permissions (`App\Security\VPXPermissionProvider`):**
  * `VPX_ACCESS_MATRIX` — Video Matrix Control Panel (`/panel`)
  * `VPX_ACCESS_AUDIO_DTX` — Aurora DTX Audio Control (`/panel/audio`)
  * `VPX_ACCESS_LIGHTING_ENTTEC` — Enttec Lighting Control (`/panel/lighting`)
  * `VPX_ACCESS_DISCOVERY` — Hardware Discovery & Network Scanner (`/panel/discover`)
* **Audit Logging (`App\Model\RouteAuditLog`):** Static `RouteAuditLog::record($actionType, $summary, $commandSent, $success, $errorDetail)` logs all routing, lighting, discovery, and master-PIN events with actor name and client IP.
## 4. Implemented Control Modules

### A. Aurora VPX Video Matrix (`/panel` & `/panel/discover`)
* **Controllers:** `App\Controller\TouchPanelController` (UI & discovery) and `App\Controller\UDPController` (`/api/vpx`).
* **Features:** Transceiver routing, preset recall, CEC display power control, subnet hardware discovery, and dark glassmorphic UI (`templates/App/Controller/TouchPanelController.ss`, `TouchPanelLock.ss`, `public/css/touch-panel.css`).

### B. Enttec S-Play Lighting Control (`/panel/lighting`)
* **Data Model (`App\Model\EnttecDevice`):**
  * Fields: `Title`, `IPAddress`, `UDPPort` (default `5000`), `BlackoutCommand` (default `'stop'`), `Model` (`Enum('SP1,Mini', 'SP1')`), `Universes` (`Int`, default `4`), `SlotMetadata` (`Text` JSON cache mapping slot IDs to recorded `source` and `universes`), and `has_one Location`.
* **Backend Service (`App\Service\EnttecSPlayService`):**
  * **HTTP REST Proxy (`POST http://<IP>/api`):**
    * `command: 8` — List playlists; `command: 29` — List cues (merged by `cue_uuid` first via `reset($track['cues'])`, enriched with `SlotMetadata`).
    * `command: 0` — Play playlist (`playlist_id`, `playlist_uuid`).
    * `command: 13` → `16` → `18` — Capture static snapshot (`type: 0`) and bind to playlist slot.
    * `command: 14` → `15` → `16` → `18` — Arm dynamic capture, stop and extract `frames` (omitting `frames` if empty so hardware buffer is preserved), save cue (`type: 1`), and bind to playlist slot.
    * `command: 19` & `17` — Delete playlist and cue (SP1 only).
  * **UDP Blackout (`triggerBlackout`):** Sends `BlackoutCommand` (default `'stop'`) via UDP socket to `IPAddress:UDPPort`.
* **Controller & Frontend (`App\Controller\EnttecController`, `TouchPanelLighting.ss`, `public/js/panel-lighting.js`):**
  * Enforces `VPX_ACCESS_LIGHTING_ENTTEC` and CSRF token validation (`X-CSRF-TOKEN`) on all `POST` endpoints.
  * **Hardware Model Rules:**
    * **S-Play Mini (`isMini() === true`):** Restricts slots to `0..15`, disables playlist deletion, and enforces a 60-second auto-stop timer on dynamic recordings.
    * **S-Play SP1 (`isMini() === false`):** Allows creating new slots (`maxId + 1`), deleting existing non-blackout playlists, and unlimited dynamic recording duration.
  * **Blackout Protection:** Any playlist named `"Blackout"` is excluded from scene cards and slot dropdowns so it cannot be overwritten or deleted.
  * **Universe Numbering:** `sACN` (`source = 2`) uses 1-based universe IDs (`1..N`); `DMX` (`0`) and `Art-Net` (`1`) use 0-based universe IDs (`0..N-1`). Payloads pass only active selected universe IDs (no `-1` padding).

---

## 5. Next Milestone: Aurora DTX Audio DSP Control (`/panel/audio`)
* **Goal:** Build the audio control screen matching the dark glassmorphic touch-panel layout and header navigation.
* **Requirements:**
  * Data model (`DTXDevice` / zone/channel configuration) registered in `AVSystemAdmin`.
  * Backend service and controller (`DTXController` at `/panel/audio` and `/api/dtx`) gated by `VPXPermissionProvider::PERM_ACCESS_AUDIO_DTX` and `VPXSecurityGuard::isValidCSRF()`.
  * Audit logging via `RouteAuditLog::record()` for volume, mute, source selection, and audio preset recalls.
  * Provide existing Aurora DTX control script or protocol command reference when starting implementation.
