# TECHNICAL DEPLOYMENT MANUAL

## AV Panel Raspberry Pi Deployment & Quick-Start Guide

**Instructions for flashing the Golden Master system image from a Windows PC, connecting to the AV control network, and configuring rooms and AV hardware modules in the CMS.**

This guide walks a Windows PC technician—with no prior Linux or macOS experience required—through deploying the pre-configured AV Control Panel system image onto new Raspberry Pi hardware, accessing the web management portal over your local network, and completing room and hardware setup using the built-in administrator account.

## 1. System Overview & Boot Behavior

* **Automatic Kiosk Launch:** Upon power-on, the Raspberry Pi boots directly into the full-screen Touch Control Panel (`/panel/splash`) without displaying desktop icons, taskbars, or terminal prompts.

* **Dynamic Touchscreen & USB Mouse Cursor:**

  * During normal touchscreen operation, the mouse pointer is **completely hidden** for a clean touch interface.

  * For on-site maintenance, plugging a **standard USB mouse** into any USB port on the Raspberry Pi immediately makes the pointer cursor appear. Unplugging the USB mouse hides the cursor again automatically.

* **Automatic Storage Expansion:** On the very first boot of a newly flashed microSD card or SSD, the system automatically expands its storage partition to use 100% of the drive's capacity.

* **Expected Startup Times:

  * **Raspberry Pi 5 with NVMe / USB 3.0 SSD:** ~15–22 seconds from power-on to `/panel/splash`.

  * **Raspberry Pi 4 or Pi 5 with microSD Card:** ~40 seconds from power-on to `/panel/splash`.

## 2. Pre-Installation Checklist

Before starting, confirm you have the following equipment and network details ready:

* **Hardware:**

  * Raspberry Pi 4 or Raspberry Pi 5 with official power supply and Ethernet patch cable

  * **Storage Option A (microSD):** 16 GB or larger Class 10 / A1+ microSD card and a USB SD card reader for your Windows PC

  * **Storage Option B (Pi 5 SSD):** 128 GB (or larger) NVMe SSD with Pi 5 M.2 HAT+ (or USB 3.0 SSD) and a USB-to-NVMe/SATA adapter to connect it to your Windows PC during flashing

  * HDMI touchscreen display (with USB touch cable connected to the Raspberry Pi)

* **Software & Files on Your Windows PC:**

  * Golden Master system image: `av-panel-golden.img` (or compressed `av-panel-golden.img.xz`)

  * **Raspberry Pi Imager for Windows** (or **balenaEtcher for Windows**) installed

* **Network Requirements:**

  * An active Ethernet drop on the **same subnet/VLAN** as your AV devices and setup laptop (mDNS `.local` discovery does not cross VLANs unless an mDNS reflector is configured on your router/switch)

  * Static IP addresses assigned (or reserved via DHCP) for all controlled AV endpoints (VPX, IPX, Enttec, RØDECaster, DTX, VISCA cameras)

## 3. Flash the Golden Master Image from Windows

1. Connect the target **microSD card** (or **NVMe/USB SSD** via USB adapter) to your Windows PC.

2. Open **Raspberry Pi Imager** on Windows:

   * **Raspberry Pi Device:** Click **Choose Device** and select **Raspberry Pi 4** or **Raspberry Pi 5** (matching your target hardware).

   * **Operating System:** Click **Choose OS**, scroll to the bottom of the list, and select **Use custom**. Browse to and select `av-panel-golden.img` (or `av-panel-golden.img.xz`).

   * **Storage:** Click **Choose Storage** and select your microSD card or external SSD. *(Double-check the drive size so you do not select an external backup hard drive.)*

3. Click **Next**. When prompted **"Would you like to apply OS customization settings?"**, select **No**—the Golden Master image is already pre-configured with the system hostname, network bindings, kiosk display settings, and Docker services.

4. Click **Yes** on the overwrite warning and wait for writing and verification to complete.

5. **Handle Windows Format Popups:** During or immediately after flashing, Windows may display one or more pop-up dialogs stating *"You need to format the disk in drive X: before you can use it."* Click **Cancel** on every prompt—Windows cannot read Linux partitions natively, and formatting the drive will erase the image.

6. Safely eject the microSD card or USB SSD adapter from Windows.

## 4. Hardware Assembly & First Boot

1. **Install Storage Media:**

   * If using a **microSD card**, insert it into the microSD slot on the underside of the Raspberry Pi.

   * If using an **NVMe SSD on a Raspberry Pi 5**, ensure **no microSD card is inserted** in the SD slot so the Pi 5 boots directly from the SSD.

2. **Connect Peripherals:**

   * Connect the touchscreen HDMI cable and USB touch cable to the Raspberry Pi.

   * Connect an Ethernet cable from the Raspberry Pi to your AV network switch.

3. **Apply Power:** Plug in the official Raspberry Pi power supply.

   * Wait **~20 seconds** (Pi 5 SSD) or **~40 seconds** (microSD) for the operating system, MariaDB database, and Apache/PHP web server containers to start automatically.

   * The connected touchscreen will load `/panel/splash` automatically with no mouse cursor visible.

## 5. Access the Web Interface from a Windows PC

Open a web browser (Chrome, Edge, or Firefox) on a Windows PC connected to the **same network subnet/VLAN** as the Raspberry Pi and navigate to:

| Interface | Primary mDNS URL | Purpose | 
| ----- | ----- | ----- | 
| **Touch Control Panel** | `http://av-panel.local/` | End-user AV routing, presets, and device control UI | 
| **CMS Admin Portal** | `http://av-panel.local/admin` | Room setup, AV hardware configuration, PINs, and user permissions | 

### Finding the IP Address on Windows if `av-panel.local` Does Not Load

If your corporate network blocks `.local` (mDNS) name resolution:

1. Press **Win + R**, type `cmd`, and press **Enter** to open Windows Command Prompt.

2. Run `ping av-panel.local -4` to see if Windows resolves its IPv4 address.

3. If `ping` cannot find the host, log in to your router or network switch's DHCP lease table, look for the client named **`av-panel`**, and browse directly to its IPv4 address (for example, `http://192.168.1.50/admin`).

## 6. First-Time Admin Login, Password & Global PIN

1. Open `http://av-panel.local/admin` in your browser.

2. Sign in using the default administrator credentials:

| Credential Field | Default Value | 
| ----- | ----- | 
| **Email / Username** | `admin` | 
| **Password** | `password` | 

3. **Change the Default Administrator Password:**

   * Click **Security** in the left-hand CMS navigation menu.

   * Click the **Administrator (`admin`)** account record.

   * Enter a strong, site-specific password and click **Save**.

4. **Configure the Global Panel PIN:**

   * Open **Settings** in the left-hand CMS menu.

   * Locate the **Global PIN / Panel PIN** field and enter your desired numeric passcode.

   * Click **Save** to require PIN authorization before end users can access protected controls or setup screens on the touch panel.

5. **Configure Staff & Operator Permissions:**

   * Go to **Security → Groups** and open a role group (such as **AV Operators** or **Administrators**).

   * Select the **Permissions** tab and check the specific AV Panel CMS modules the group should manage (such as *Locations*, *VPX Devices*, *IPX Devices*, *Enttec Devices*, *RØDECaster Devices*, *DTX Devices*, or *Visca Cameras*).

   * Click **Save**, then assign staff user accounts under the **Members** tab.

## 7. Configuring Locations & AV Hardware Modules

Each AV hardware module in the CMS operates independently and can optionally be organized under a physical **Location** (room or zone). Follow this setup order:

### Step 1: Create Locations (Rooms / Zones — Optional)

1. Click **Locations** in the left-hand CMS navigation menu.

2. Click **Add Location**, enter a clear room or zone name (for example, `Main Sanctuary` or `Conference Room A`), and click **Create**.

3. Repeat for every room or zone you wish to group devices under. *(Assigning a Location is optional for all device modules.)*

### Step 2: Register Networked AV Hardware Modules

> **Automatic Network Discovery Tip:** For **DTX**, **VPX**, **IPX**, and **VISCA Cameras**, you can use the CMS **Discovery Service** to automatically scan the local AV subnet and detect compatible endpoints instead of entering IP addresses manually. *(Note: Network discovery requires the Raspberry Pi and target AV devices to reside on the same subnet/VLAN.)*

1. Select the hardware module tab from the left-hand CMS menu, or open the **Discovery Service** to import detected **DTX**, **VPX**, **IPX**, and **VISCA Camera** devices automatically.

2. To add a device manually, click **Add \[Device\]**, enter a descriptive label, the device's **static IPv4 address**, and its **network port number** *(enter digits only, without commas)*.

3. Optionally select an assigned **Location** from the dropdown menu and click **Create**.

| CMS Module | Supported Hardware | Fields (`Location` is Optional) | 
| ----- | ----- | ----- | 
| **VPX Devices** | VPX AV-over-IP Encoders & Decoders | Name, IP Address, Port, Type (Encoder/Decoder), Location *(Optional)* | 
| **IPX Devices** | IPX AV-over-IP Endpoints | Name, IP Address, Port, Location *(Optional)* | 
| **Enttec Devices** | Enttec S-Play Show & Lighting Controllers | Name, IP Address, Port, Location *(Optional)* | 
| **RØDECaster Devices** | RØDECaster Audio Production Mixers | Name, IP Address, Port, Location *(Optional)* | 
| **DTX Devices** | DTX Audio / Matrix Processors | Name, IP Address, Port, Location *(Optional)* | 
| **Visca Cameras** | VISCA-over-IP PTZ Cameras | Name, IP Address, Port, Location *(Optional)* | 

### Step 3: Verify Touch Panel Operation

1. Open `http://av-panel.local/` on your browser or check the connected Raspberry Pi touchscreen.

2. Confirm that your newly created locations, video/audio routes, lighting cues, and camera presets appear and trigger hardware responses accurately.

## 8. Deploying Multiple Panels on the Same Network

If you deploy two or more Raspberry Pi units onto the **same local network subnet** using the Golden Master image, each additional unit must be given a unique hostname so they do not conflict on `http://av-panel.local/`.

1. Boot **only the new unit** on the network (keep previously deployed `av-panel` units disconnected temporarily so you connect to the right device).

2. On your Windows PC, open **Windows Terminal** or **PowerShell** (press **Win + X** and select **Terminal** or **PowerShell**).

3. Connect to the Raspberry Pi via SSH:

   ```
   ssh admin@av-panel.local
   ```

   *(If prompted about host authenticity, type `yes` and press **Enter**, then enter the `admin` Linux SSH password.)*

4. Copy and paste the following complete command block (replacing `av-panel-2` with your desired unique unit name, such as `av-panel-3`) and press **Enter**:

   ```
   NEW_HOST="av-panel-2"
   sudo hostnamectl set-hostname "$NEW_HOST"
   sudo sed -i "s/127.0.1.1.*/127.0.1.1\t$NEW_HOST/" /etc/hosts
   sudo systemctl restart avahi-daemon
   cd ~/av-panel
   sed -i "s|http://av-panel.local|http://$NEW_HOST.local|g" docker-compose.pi.yml
   docker compose up -d --force-recreate web
   ```

5. Close PowerShell (`exit`) and access the renamed unit in your browser at `http://av-panel-2.local/` and `http://av-panel-2.local/admin`.

## 9. Troubleshooting & On-Site Maintenance

| Symptom | Resolution | 
| ----- | ----- | 
| **Need a mouse cursor on the touchscreen for local troubleshooting** | Plug any standard **USB mouse** into the Raspberry Pi. The pointer cursor appears automatically within half a second and disappears again when unplugged. | 
| **Web interface unreachable right after power-on** | Wait **20 seconds** (Pi 5 SSD) or **40 seconds** (microSD) after applying power for the MariaDB and Apache/PHP containers to finish starting. | 
| **`http://av-panel.local` does not open in Windows** | Ensure your Windows PC is on the exact same VLAN/subnet as the Pi (mDNS does not cross VLANs without a router reflector), or browse directly to the Pi's IPv4 address (`http://<pi-ip>/admin`). | 
| **Pi 5 does not boot from NVMe SSD** | Verify that **no microSD card** is inserted in the Raspberry Pi 5 card slot and that the M.2 HAT+ ribbon cable is seated firmly. | 
| **Check running web/database services from Windows** | Open **PowerShell** on Windows, run `ssh admin@av-panel.local`, and execute `cd ~/av-panel && docker compose ps` to confirm both `web` and `db` containers report status `Up`. | 