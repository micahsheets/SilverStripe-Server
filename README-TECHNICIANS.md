# AV Touch Panel — Offline Technician Staging Kit

## Prerequisites
- Install **Docker Desktop** (Windows or macOS) or Docker Engine + Compose (Linux).

## 1. Start Offline Staging on Your PC / Mac
- **Windows:** Double-click `start-bench.bat`
- **macOS / Linux:** Open Terminal in this folder and run `./start-bench.sh`
- Your browser will open **http://localhost:8080/admin** (Username: `admin`, Password: `password`).
- Preview the live touch panels anytime at **http://localhost:8080/panel** (Default Master PIN: `1234`).

## 2. Pre-Configure the Client Site (No Hardware Needed)
1. **Rooms / Locations:** Add all rooms under **AV System -> Locations**.
2. **Devices & Static IPs:** Add VPX, IBT, DTX, Enttec, Shure P300, Projectors, RØDECaster, Cameras, and Auctioneer Lanes/Stations with their planned site IP addresses.
3. **Presets, PINs & Branding:** Create scene presets, configure user PINs under **Security**, and upload client logos/backgrounds under **AV System -> Panel Settings**.

## 3. Export & Deploy to the Raspberry Pi (100% Browser-Based)
1. On your Windows/Mac laptop, go to **http://localhost:8080/admin/system-update**.
2. Under **2. Export Site Configuration**, enter a Client/Site name and click **Download Site Configuration (.zip)** to save the `.zip` file to your Downloads folder or USB drive.
3. On installation day, open the Raspberry Pi's admin page (`http://av-panel.local/admin/system-update`), select that `.zip` file under **3. Restore Site Configuration onto This Panel**, and click **Restore Site Configuration**.
