<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discover VPX Devices<% if $SiteConfig.PanelTitle %> - $SiteConfig.PanelTitle<% end_if %></title>
    <link rel="stylesheet" href="/css/touch-panel.css">
    <% if $SiteConfig.PanelBackground.exists %>
    <style>
        body {
            background-image:
                linear-gradient(rgba(15, 23, 42, 0.{$SiteConfig.BackgroundOverlayOpacity}), rgba(15, 23, 42, 0.{$SiteConfig.BackgroundOverlayOpacity})),
                url('$SiteConfig.PanelBackground.ScaleWidth(1920).URL');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        header, .scan-bar, table {
            background-color: rgba(30, 41, 59, 0.88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
    </style>
    <% end_if %>
    <style>
        body { overflow: auto; height: auto; min-height: 100vh; }
        .wrap { max-width: 1100px; margin: 24px auto; padding: 0 20px; width: 100%; }
        .scan-bar { display: flex; gap: 12px; background: var(--bg-panel); padding: 18px; border-radius: 14px; border: 1px solid var(--border); flex-wrap: wrap; align-items: center; }
        .scan-input { background: var(--bg-dark); border: 1px solid var(--border); color: var(--text-main); padding: 11px 14px; border-radius: 10px; font-size: 0.95rem; min-width: 240px; }
        .btn { background: var(--accent); color: #0f172a; border: none; padding: 11px 18px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.92rem; }
        .btn.save { background: var(--success); }
        .btn.back { background: var(--bg-card); color: var(--text-main); text-decoration: none; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; background: var(--bg-panel); border-radius: 14px; overflow: hidden; }
        th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--border); font-size: 0.9rem; }
        th { color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; }
        .tbl-input, .tbl-select { background: var(--bg-dark); border: 1px solid var(--border); color: var(--text-main); padding: 7px 10px; border-radius: 8px; width: 100%; }
        .status-pill { padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .status-new { background: rgba(34, 197, 94, 0.2); color: #86efac; }
        .status-exists { background: rgba(148, 163, 184, 0.2); color: var(--text-muted); }
    </style>
</head>
<body>
    <header>
        <div class="brand">
            <% if $SiteConfig.PanelLogo.exists %>
                <img src="$SiteConfig.PanelLogo.ScaleHeight(36).URL" alt="$SiteConfig.PanelTitle" style="max-height:36px; width:auto;">
            <% end_if %>
            <span><% if $SiteConfig.PanelTitle %>$SiteConfig.PanelTitle<% else %>VPX NETWORK DISCOVERY<% end_if %></span>
        </div>
        <a href="/panel" class="btn back">Back to Matrix</a>
    </header>

    <div class="wrap">
        <div class="scan-bar">
            <input type="text" id="subnetInput" class="scan-input" placeholder="Subnet CIDR (e.g. 192.168.1.0/24)" value="192.168.1.0/24">
            <button type="button" class="btn" id="scanBtn" onclick="runScan()">Scan Network (Broadcast + Subnet)</button>
            <button type="button" class="btn save" id="importBtn" style="display:none;" onclick="importSelected()">Add / Update Selected in Database</button>
        </div>

        <table id="resultsTable" style="display:none;">
            <thead>
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="checkAll" onchange="toggleAllRows(this.checked)"></th>
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Friendly Name</th>
                    <th>VPX Hostname</th>
                    <th>Mode</th>
                    <th>Location / Room</th>
                </tr>
            </thead>
            <tbody id="resultsBody"></tbody>
        </table>
    </div>

    <div id="toast"></div>
    <script src="/js/panel-discover.js"></script>
</body>
</html>
