<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> — Audio DSP</title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=70">
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
    <style>
        .view-toggle-group { display:inline-flex; border-radius:999px; border:1px solid rgba(148,163,184,0.35); overflow:hidden; background:rgba(15,23,42,0.55); }
        body[data-theme="light"] .view-toggle-group { background:rgba(255,255,255,0.85); }
        .view-toggle-btn { border:none; background:transparent; color:inherit; padding:7px 14px; font-size:0.84rem; font-weight:700; cursor:pointer; opacity:0.78; }
        .view-toggle-btn.active { background:#0ea5e9; color:#ffffff; opacity:1; }
        #dsp-container.is-grid-view .audio-controls {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(255px, 1fr)) !important;
            gap: 12px !important;
        }
        #dsp-container.is-grid-view .control-group {
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 14px;
            padding: 14px;
            margin: 0 !important;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        body[data-theme="light"] #dsp-container.is-grid-view .control-group {
            background: rgba(255, 255, 255, 0.92);
        }
    </style>
</head>
<body class="audio-panel" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">

    <header class="panel-header">
        <div class="brand-wrap">
            <span style="display:inline-flex; align-items:center; gap:6px;"><span class="status-dot" id="connectionDot" title="LAN Status"></span><span class="status-dot" id="dtxConnectionDot" title="Audio DSP Device Status"></span></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% end_if %></div>
                <div class="brand-sub">Audio DSP Control</div>
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
        <div class="room-tabs unified-room-tabs" style="margin-bottom:10px;">
            <button type="button" class="room-tab active" data-location-id="0">All Rooms</button>
            <% loop $Locations %>
                <button type="button" class="room-tab" data-location-id="$ID">$Title</button>
            <% end_loop %>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px; flex:0 0 auto;">
            <div id="audio-device-tabs" class="device-tabs"></div>
            <div style="display:flex; gap:8px; align-items:center;">
                <div class="view-toggle-group" role="group" aria-label="Layout View"><button type="button" class="view-toggle-btn" data-view-mode="grid">Grid</button><button type="button" class="view-toggle-btn active" data-view-mode="list">List</button></div>
                <button type="button" id="btn-refresh-dtx" class="nav-pill">Refresh Channels</button>
                <% if $CanAccessDiscovery %>
                    <button type="button" id="btn-discover-dtx" class="nav-pill">Scan Network for DTX</button>
                <% end_if %>
            </div>
        </div>

        <% if $Devices %>
            <div class="dsp-grid" id="dsp-container">
                <% loop $Devices %>
                    <div class="dsp-card audio-device-card" data-device-id="$ID" data-location-id="$LocationID" data-device-model="$DeviceModel" style="display:none;">
                        <div class="dsp-header">
                            <span class="dsp-title">$Title</span>
                            <span class="dsp-meta" data-status-meta>$DeviceModel | $IPAddress — Syncing…</span>
                        </div>

                        <div class="audio-controls" data-channels-container>
                            <div style="opacity:0.75; padding:12px 0;">Loading channels from $Title…</div>
                        </div>
                    </div>
                <% end_loop %>
            </div>
        <% else %>
            <div class="dsp-card" style="color:#fca5a5;">
                No active Aurora DTX audio devices found. Please add them in the AV System Admin.
            </div>
        <% end_if %>
    </main>

</div>

<div id="status-toast" class="status-toast"></div>
<% include App\DTX\Includes\PanelAudioJS %>
<script src="/js/nav-overflow.js?v=68"></script>
<% include App\Includes\SystemPowerModal %>
</body>
</html>
