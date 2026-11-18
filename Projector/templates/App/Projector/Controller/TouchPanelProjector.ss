<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> — Projector Control</title>
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
</head>
<body class="projector-panel" data-theme="$PanelTheme" data-csrf="$CSRFToken">
<div class="panel-backdrop">

    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="connectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% end_if %></div>
                <div class="brand-sub">Projector &amp; Display Control</div>
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
        <div class="room-tabs unified-room-tabs" style="margin-bottom:14px;">
            <button type="button" class="room-tab active" data-location-id="0">All Rooms</button>
            <% loop $Locations %>
                <button type="button" class="room-tab" data-location-id="$ID">$Title</button>
            <% end_loop %>
        </div>
        <% if $Projectors %>
            <div class="device-grid" id="projectorsGrid">
                <% loop $Projectors %>
                    <div class="dsp-card projector-card" data-device-id="$ID">
                        <div class="dsp-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                            <span class="dsp-title" style="font-weight:700; font-size:1.1rem;">$Title</span>
                            <span class="dsp-meta" style="font-size:0.82rem; color:var(--text-muted);">$IPAddress</span>
                        </div>

                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                            <button type="button" class="bulk-btn on" data-cmd="power_on">Power ON</button>
                            <button type="button" class="bulk-btn off" data-cmd="power_off">Power OFF</button>
                            <button type="button" class="nav-pill" data-cmd="mute_toggle">AV Mute</button>
                        </div>

                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <button type="button" class="nav-pill" data-cmd="input_hdmi1">HDMI 1</button>
                            <button type="button" class="nav-pill" data-cmd="input_hdmi2">HDMI 2</button>
                            <button type="button" class="nav-pill" data-cmd="input_hdbaset">HDBaseT</button>
                        </div>
                    </div>
                <% end_loop %>
            </div>
        <% else %>
            <div class="dsp-card" style="color:var(--text-muted);">
                Drop-in Projector module active. Add <strong>ProjectorDevice</strong> records under <strong>AV System → Projectors</strong> to populate controls.
            </div>
        <% end_if %>
    </main>

</div>

<div id="status-toast" class="status-toast"></div>
<% include App\Projector\Includes\PanelProjectorJS %>
<script src="/js/nav-overflow.js?v=53"></script>
<% include App\Includes\SystemPowerModal %>
</body>
</html>
