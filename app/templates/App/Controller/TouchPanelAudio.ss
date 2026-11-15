<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> — Audio DSP</title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=28">
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
    <link rel="stylesheet" href="/css/touch-panel-light.css?v=28">
</head>
<body class="audio-panel" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">

    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="connectionDot"></span>
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
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
            <div id="audio-device-tabs" class="device-tabs"></div>
            <% if $CanAccessDiscovery %>
                <button type="button" id="btn-discover-dtx" class="nav-pill">Scan Network for DTX</button>
            <% end_if %>
        </div>

        <% if $Devices %>
            <div class="dsp-grid" id="dsp-container">
                <% loop $Devices %>
                    <div class="dsp-card audio-device-card" data-device-id="$ID" style="display:none;">
                        <div class="dsp-header">
                            <span class="dsp-title">$Title</span>
                            <span class="dsp-meta">$DeviceModel | $IPAddress</span>
                        </div>

                        <div class="audio-controls">
                            <div class="control-group">
                                <label>Master Out</label>
                                <input type="range" class="glass-slider vol-slider" min="0" max="100" value="50" data-channel="master">
                                <button class="btn-mute" data-action="mute" data-channel="master">Mute</button>
                            </div>

                            <div class="control-group">
                                <label>Mic 1 In</label>
                                <input type="range" class="glass-slider vol-slider" min="0" max="100" value="70" data-channel="mic1">
                                <button class="btn-mute" data-action="mute" data-channel="mic1">Mute</button>
                            </div>
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
<script src="/js/panel-audio.js"></script>
</body>
</html>
