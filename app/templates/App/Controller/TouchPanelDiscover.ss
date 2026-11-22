<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>Discover Devices<% if $PanelSettings.PanelTitle %> — $PanelSettings.PanelTitle<% end_if %></title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=70">
    <style>
        .scan-bar { display: flex; gap: 12px; background: var(--card-bg); padding: 18px; border-radius: 16px; border: 1px solid var(--card-border); flex-wrap: wrap; align-items: center; }
        .scan-input { background: var(--control-bg); border: 1px solid var(--card-border); color: var(--text-main); padding: 11px 14px; border-radius: 10px; font-size: 0.95rem; min-width: 240px; }
        .btn { background: var(--accent); color: #0f172a; border: none; padding: 11px 18px; border-radius: 10px; font-weight: 700; cursor: pointer; font-size: 0.92rem; }
        .btn.save { background: var(--success); }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 16px; overflow: hidden; }
        th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--card-border); font-size: 0.9rem; }
        th { color: var(--text-muted); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.8px; }
        .tbl-input, .tbl-select { background: var(--control-bg); border: 1px solid var(--card-border); color: var(--text-main); padding: 7px 10px; border-radius: 8px; width: 100%; }
        .status-pill { padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
        .status-new { background: rgba(34, 197, 94, 0.2); color: #86efac; }
        .status-exists { background: rgba(148, 163, 184, 0.2); color: var(--text-muted); }
    </style>
<% if $PanelSettings.PanelBackground %>
<style>
    html body {
        background-image:
            linear-gradient(rgba(15, 23, 42, 0.{$PanelSettings.BackgroundOverlayOpacity}), rgba(15, 23, 42, 0.{$PanelSettings.BackgroundOverlayOpacity})),
            url('$PanelSettings.PanelBackground.ScaleWidth(1920).URL') !important;
        background-size: cover !important;
        background-position: center !important;
        background-attachment: fixed !important;
    }
    html body[data-theme="light"] {
        background-image:
            linear-gradient(rgba(248, 250, 252, 0.{$PanelSettings.BackgroundOverlayOpacity}), rgba(248, 250, 252, 0.{$PanelSettings.BackgroundOverlayOpacity})),
            url('$PanelSettings.PanelBackground.ScaleWidth(1920).URL') !important;
    }
    html body .panel-backdrop,
    html body[data-theme="light"] .panel-backdrop {
        background: transparent !important;
        backdrop-filter: none !important;
        -webkit-backdrop-filter: none !important;
    }
</style>
<% end_if %>
    <link rel="stylesheet" href="/css/touch-panel-light.css?v=70">
</head>
<body class="discover-panel" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">
    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="connectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>VPX NETWORK DISCOVERY<% end_if %></div>
                <div class="brand-sub">Hardware Discovery</div>
            </div>
        </div>

        <nav class="nav-tabs">
            <% loop $PanelNavigation %>
                <a href="$Route" class="nav-pill <% if $IsActive %>active<% end_if %>">$Title</a>
            <% end_loop %>
            <% if $PanelNavigation %>
                <a href="$DefaultPanelRoute" class="nav-pill">&#8592; Back to Panel</a>
            <% end_if %>
            <a href="/panel/lock" class="nav-pill lock-btn">Lock Panel</a>
        </nav>
    </header>

    <main class="panel-main">
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
    </main>
</div>

<div id="toast"></div>
<script src="/js/panel-discover.js"></script>
<script src="/js/nav-overflow.js?v=68"></script>
<% include App\Includes\SystemPowerModal %>
</body>
</html>
