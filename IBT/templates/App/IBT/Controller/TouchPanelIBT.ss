<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> - IBT-1-BX Matrix</title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=53">
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
    <link rel="stylesheet" href="/css/touch-panel-light.css?v=53">
    <% include App\VPX\Includes\VPXPreviewCSS %>
    <style>
        .ibt-modal-backdrop { position:fixed; inset:0; background:rgba(2,6,23,0.78); z-index:9999; display:none; align-items:center; justify-content:center; padding:16px; }
        .ibt-modal-backdrop.open { display:flex; }
        .ibt-modal { width:min(940px, 100%); max-height:88vh; overflow:auto; background:#0f172a; color:#f8fafc; border:1px solid rgba(148,163,184,0.35); border-radius:16px; padding:18px; }
        body[data-theme="light"] .ibt-modal { background:#ffffff; color:#0f172a; }
        .ibt-disc-table { width:100%; border-collapse:collapse; margin-top:12px; font-size:0.88rem; }
        .ibt-disc-table th, .ibt-disc-table td { padding:8px; border-bottom:1px solid rgba(148,163,184,0.22); text-align:left; }
        .ibt-disc-input, .ibt-disc-select { padding:5px 8px; border-radius:6px; border:1px solid rgba(148,163,184,0.4); background:rgba(15,23,42,0.5); color:inherit; }
        body[data-theme="light"] .ibt-disc-input, body[data-theme="light"] .ibt-disc-select { background:#f8fafc; }
    </style>
</head>
<body class="video-panel<% if $IBTSettings.PresetsOnlyMode %> presets-only-mode<% end_if %>" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">

    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="ibtConnectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% end_if %></div>
                <div class="brand-sub">Aurora IBT-1-BX Matrix Control</div>
            </div>
        </div>

        <nav class="nav-tabs">
            <% loop $PanelNavigation %>
                <a href="$Route" class="nav-pill <% if $IsActive %>active<% end_if %>">$Title</a>
            <% end_loop %>
            <a href="/panel/lock" class="nav-pill lock-btn">Lock Panel</a>
        </nav>
    </header>

    <main class="panel-main">
        <div class="matrix-toolbar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div class="room-tabs" id="ibtRoomTabs"></div>
            <% if $CanAccessDiscovery %>
                <button type="button" class="nav-pill" onclick="openIbtDiscoverModal()">Scan Network for IBT</button>
            <% end_if %>
        </div>

        <section id="ibtPresetsSection">
            <h2>Scene Presets</h2>
            <div class="presets-grid" id="ibtPresetsGrid"></div>
        </section>

        <div class="routing-columns">
            <div class="column-box">
                <div class="column-header">
                    <div>
                        <h2 style="margin:0;">1. Select Receiver(s)</h2>
                        <span class="hint" id="ibtSelectionHint">Tap displays to select</span>
                    </div>
                    <div class="bulk-power-controls">
                        <button type="button" class="bulk-btn select" onclick="selectAllIbtDisplays()">Select All</button>
                        <button type="button" class="bulk-btn clear" onclick="clearIbtDisplays()">Clear</button>
                        <button type="button" class="bulk-btn on" onclick="powerAllIbtDisplays(true)">All ON</button>
                        <button type="button" class="bulk-btn off" onclick="powerAllIbtDisplays(false)">All OFF</button>
                    </div>
                </div>
                <div class="device-grid" id="ibtDecodersGrid"></div>
            </div>

            <div class="column-box">
                <div class="column-header">
                    <h2 style="margin:0;">2. Tap Transmitter</h2>
                    <div class="stream-toggles" id="ibtStreamToggles"></div>
                </div>
                <div class="device-grid" id="ibtEncodersGrid"></div>
            </div>
        </div>
    </main>
</div>

<div class="ibt-modal-backdrop" id="ibtDiscoverModal">
    <div class="ibt-modal">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
            <h2 style="margin:0;">Aurora IBT-1-BX Network Discovery</h2>
            <button type="button" class="nav-pill" onclick="closeIbtDiscoverModal()">Close</button>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; align-items:center;">
            <input type="text" id="ibtSubnetInput" class="ibt-disc-input" placeholder="Subnet e.g. 192.168.1.0/24 (optional)" style="min-width:240px;">
            <button type="button" id="ibtScanBtn" class="nav-pill active" onclick="runIbtScan()">Scan Network</button>
            <button type="button" id="ibtImportBtn" class="nav-pill" style="display:none;" onclick="importIbtSelected()">Import Selected</button>
        </div>
        <table class="ibt-disc-table" id="ibtResultsTable" style="display:none;">
            <thead>
                <tr>
                    <th><input type="checkbox" checked onchange="document.querySelectorAll('.ibt-row-chk').forEach(c=>c.checked=this.checked)"></th>
                    <th>IP / MAC</th>
                    <th>Status</th>
                    <th>Device Name</th>
                    <th>Hostname</th>
                    <th>Mode</th>
                    <th>Room</th>
                </tr>
            </thead>
            <tbody id="ibtResultsBody"></tbody>
        </table>
    </div>
</div>

<div id="toast"></div>
<% include App\IBT\Includes\PanelIBTJS %>
<script src="/js/nav-overflow.js?v=53"></script>
<% include App\Includes\SystemPowerModal %>
</body>
</html>
