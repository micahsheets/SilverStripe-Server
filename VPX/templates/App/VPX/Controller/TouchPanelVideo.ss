<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> - Video Matrix</title>
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
</head>
<body class="video-panel<% if $VPXSettings.PresetsOnlyMode %> presets-only-mode<% end_if %>" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">

    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="connectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% end_if %></div>
                <div class="brand-sub">Video Matrix Control</div>
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
            <div class="room-tabs" id="roomTabs"></div>
            <% if $CanAccessDiscovery %>
                <a href="/panel/discover" class="nav-pill">Network Discovery</a>
            <% end_if %>
        </div>

        <section id="presetsSection">
            <h2>Scene Presets</h2>
            <div class="presets-grid" id="presetsGrid"></div>
        </section>

        <div class="routing-columns">
            <div class="column-box">
                <div class="column-header">
                    <div>
                        <h2 style="margin:0;">1. Select Display(s)</h2>
                        <span class="hint" id="selectionHint">Tap displays to select</span>
                    </div>
                    <div class="bulk-power-controls">
                        <button type="button" class="bulk-btn select" onclick="selectAllVisibleDisplays()">Select All</button>
                        <button type="button" class="bulk-btn clear" onclick="clearSelectedDisplays()">Clear</button>
                        <button type="button" class="bulk-btn on" onclick="powerAllVisibleDisplays(true)">All ON</button>
                        <button type="button" class="bulk-btn off" onclick="powerAllVisibleDisplays(false)">All OFF</button>
                    </div>
                </div>
                <div class="device-grid" id="decodersGrid"></div>
            </div>

            <div class="column-box">
                <div class="column-header">
                    <h2 style="margin:0;">2. Tap Source</h2>
                    <div class="stream-toggles" id="streamToggles"></div>
                </div>
                <div class="device-grid" id="encodersGrid"></div>
            </div>
        </div>
    </main>

</div>

<div id="toast"></div>
<% include App\VPX\Includes\PanelVPXApiJS %>
<% include App\VPX\Includes\PanelVPXUiJS %>
<script src="/js/nav-overflow.js?v=53"></script>
</body>
</html>
