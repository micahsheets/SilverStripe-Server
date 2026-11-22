# TECHNICAL OPERATIONS MANUAL

## AV Panel Cloud & EC2 Technician Configuration Guide

**Portal URL:** `https://avpanel.soniceyetec.biz`  
**CMS Administration URL:** `https://avpanel.soniceyetec.biz/admin`

This guide walks AV technicians through logging into the hosted AV Control Panel instance for the first time, setting up their personal password via their VIP email address, activating control modules, configuring rooms and AV hardware endpoints, and managing named snapshots and baseline resets.

---

## 1. Portal Endpoints Overview

| Interface | URL | Purpose |
| :--- | :--- | :--- |
| **Touch Control Panel** | `https://avpanel.soniceyetec.biz/` | End-user AV routing, audio DSP, lighting cues, camera presets, and device control UI |
| **CMS Admin Portal** | `https://avpanel.soniceyetec.biz/admin` | Module activation, room setup, AV hardware configuration, PINs, user permissions, and backups |

---

## 2. First-Time Technician Login & Password Setup

Each technician is provisioned with a personal administrator or operator account tied to their **VIP email address**.

1. Open **`https://avpanel.soniceyetec.biz/admin`** in your web browser (Chrome, Edge, Safari, or Firefox).
2. On the login screen, enter your **VIP email address** (for example, `firstname.lastname@vip...`).
3. Follow the initial login / password-setup prompt to **create and confirm your personal password**.
   * *If you have not received your initial setup email or your link has expired, click **Forgot password?** on the login screen and enter your VIP email address to receive a fresh password-setup link.*
4. Once signed in, configure your personal **Touch Panel Operator PIN** (optional, for personal keypad login):
   * Click **Security** in the left-hand CMS navigation menu and select your user record.
   * Enter a **4–8 digit numeric passcode** in the **Panel PIN** field and click **Save**.

> **Security Tip:** Never share your personal VIP login credentials or individual Operator PIN. Use the **Basic Shared Login PIN** (configured in AV Settings) for general day-to-day room operators.

---

## 3. Activating Control Modules & Global Panel Settings

On a newly initialized environment or after a baseline reset, control modules remain hidden from both the touch panel interface and the `/admin` sidebar until explicitly enabled.

1. In the left-hand CMS menu, click **AV Settings** (or click **Configure Modules in Admin** directly from the front-end *Setup Required* screen).
2. Open the **Panel Settings** record (`https://avpanel.soniceyetec.biz/admin/av-settings/App-Model-PanelSettings/EditForm/field/App-Model-PanelSettings/item/1/edit`).
3. **Main Tab (Branding & Theme):**
   * Enter the **Touch Panel Header Title** (for example, the client or facility name).
   * Select **Dark Theme** or **Light Theme**.
   * Optionally upload a **Company Logo** and **Touch Panel Background Image**, and set the **Background Dark Overlay %**.
4. **Modules Tab (Enable Required Interfaces):**
   * Check only the AV modules active for this site (such as *Video Matrix (VPX)*, *Audio DSP (DTX)*, *Allen & Heath AHM*, *Shure P300 DSP*, *Projectors*, *Lighting (Enttec)*, *PTZ Cameras (VISCA)*, *RØDECaster*, *Auctioneer*, or *IBT*).
   * Disabled modules stay hidden from both the front-end navigation bar and the `/admin` sidebar.
5. **Security Tab (Shared PIN & Auto-Logout):**
   * **Basic Shared Login PIN:** Enter a 4–8 digit numeric PIN for room operators.
   * **Interfaces Accessible via Basic Shared PIN:** Select which enabled modules operators can unlock using the shared PIN.
   * **Auto-Logout Inactivity Timeout (Minutes):** Set an inactivity lock timer (`0` disables auto-logout).
6. Click **Save**, then refresh `/admin` so the newly enabled module tabs appear in the left-hand sidebar.

---

## 4. Configuring Locations & AV Hardware Endpoints

Each enabled AV module operates independently and can optionally be organized under physical **Locations** (rooms or zones).

### Step 1: Create Locations (Rooms / Zones — Optional)
1. Click **Locations** in the left-hand CMS navigation menu.
2. Click **Add Location**, enter a clear room or zone name (for example, `Main Sanctuary`, `Ballroom A`, or `Conference Room`), and click **Create**.
3. Repeat for every room or zone you wish to organize devices under.

### Step 2: Register AV Hardware Devices
1. Select the enabled hardware module tab from the left-hand CMS menu (such as **VPX Matrix**, **Audio DSP (DTX)**, **Allen & Heath AHM**, **Shure P300 DSP**, **Projectors**, **Enttec**, **RØDECaster**, or **VISCA Cameras**).
2. Click **Add [Device]**.
3. Enter a descriptive **Title / Friendly Name**, the endpoint's **IPv4 Address**, and its **Network Port** *(digits only, without commas)*.
4. Optionally assign a **Location** from the dropdown menu and click **Create**.

> **Network Discovery:** For compatible endpoints on a reachable local subnet or VPN bridge, you can also open **`https://avpanel.soniceyetec.biz/panel/discover`** to scan and import discovered devices in bulk.

### Step 3: Verify Front-End Operation & Status Indicators
1. Open **`https://avpanel.soniceyetec.biz/`** and tap the splash screen to unlock with your PIN.
2. Check the **top-left status dot** in the panel header:
   * **Gray:** Zero AV devices are currently configured in Admin.
   * **Green:** LAN is active and configured AV endpoints are reachable.
   * **Red:** Network or configured AV endpoints are unreachable.
3. Test routing, volume/mute controls, lighting cues, camera presets, and display power commands to verify live hardware response.

---

## 5. Managing Staff & Operator Permissions

1. Go to **Security → Groups** in the left-hand CMS menu.
2. Open an existing role group (such as **Administrators** or **AV Operators**), or click **Add Group** to create a site-specific role.
3. Under the **Permissions** tab, check the CMS admin sections and touch panel interfaces members of that group are permitted to access.
4. Under the **Members** tab, assign the appropriate technician or staff VIP accounts and click **Save**.

---

## 6. Named Snapshots, Baseline Reset & Portable Backups

The **Backup & Restore** section (`https://avpanel.soniceyetec.biz/admin/system-backup`) allows technicians to save named server snapshots, switch between staging configurations, reset the database back to a clean starting baseline, or export portable `.zip` backups.

### A. Saving a Named Snapshot
1. Open **Backup & Restore** in the left-hand CMS menu.
2. Under **1. Server Snapshots & Tech Baseline Reset**, enter a descriptive name (for example, `Clean Tech Baseline` or `Client Site - Main Hall`).
3. Check **Set as Default Tech Baseline** if this snapshot should serve as the master starting point for future setups.
4. Click **Save as New Snapshot**.

### B. Loading a Snapshot to Work On (or Saving Changes Back to It)
1. In the **Select Snapshot to Work On** bar at the top of Section 1, choose any saved snapshot from the dropdown menu.
2. Click **Load Selected Snapshot** (or click **Load Snapshot** next to any item in the list) to load that configuration into the live database.
3. After making edits in `/admin`, return to **Backup & Restore** and click **Save Current Changes to Selected** (or **Update / Save Here**) to save your updates back into that snapshot.

### C. Resetting the System to the Tech Baseline
* To wipe test edits or stage a fresh installation starting from your clean template, click **Reset to Baseline** in the yellow **Tech Baseline** banner at the top of **Backup & Restore** and confirm the prompt.

### D. Exporting & Restoring Portable Backup Files (`.zip`)
* **Download Portable Backup:** Under **2. Download Portable Backup (.zip)**, select the modules to export (enabled modules are pre-checked automatically), keep **Include uploaded branding logos & background wallpapers** checked, and click **Download Backup (.zip)**.
* **Restore from File:** Under **3. Restore from Backup File (.zip)**, choose a `.zip` archive, inspect the detected modules and record counts, select **Clean Replace** or **Merge / Update**, and click **Restore Selected Modules**.

---

## 7. Quick Troubleshooting Reference

| Symptom | Resolution |
| :--- | :--- |
| **Did not receive or forgot VIP login password** | Click **Forgot password?** on `https://avpanel.soniceyetec.biz/admin` and enter your VIP email address to receive a password setup/reset link. |
| **Front end shows "No Control Modules Activated"** | Log in to `/admin`, open **AV Settings → Panel Settings → Modules**, check the required modules, and click **Save**. |
| **Module tab is missing in `/admin` sidebar** | Module tabs in `/admin` remain hidden until enabled under **AV Settings → Panel Settings → Modules**. Refresh the page after saving. |
| **Top-left status dot is gray** | Neutral gray indicates `0` AV devices are configured in the database. Add at least one device under its module tab in `/admin`. |
| **PIN rejected on touch panel lock screen** | Confirm your account has a numeric **Panel PIN** set in **Security**, or verify the **Basic Shared Login PIN** and allowed interfaces in **AV Settings → Security**. |