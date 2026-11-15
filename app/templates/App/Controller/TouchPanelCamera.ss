<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> — Camera Control</title>
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
    <link rel="stylesheet" href="/css/touch-panel-light.css?v=29">
</head>
<body class="camera-panel" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">
    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="connectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% end_if %></div>
                <div class="brand-sub">Camera Control</div>
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
        <% if $Cameras %>
            <div class="camera-grid">
                <% loop $Cameras %>
                    <div class="cam-card" data-device-id="$ID">
                        <h3 style="color:var(--cam-accent); margin-top:0;">$Title</h3>

                        <div class="d-pad">
                            <div class="ptz-btn up" data-dir="up">&#8593;</div>
                            <div class="ptz-btn left" data-dir="left">&#8592;</div>
                            <div class="ptz-btn home" data-dir="stop">STOP</div>
                            <div class="ptz-btn right" data-dir="right">&#8594;</div>
                            <div class="ptz-btn down" data-dir="down">&#8595;</div>
                        </div>

                        <div class="zoom-controls">
                            <button class="zoom-btn" data-zoom="wide">Zoom Out</button>
                            <button class="zoom-btn" data-zoom="tele">Zoom In</button>
                        </div>

                        <div style="margin-top:24px; display:flex; justify-content:space-between; align-items:center;">
                            <h4 style="margin:0;">Presets</h4>
                            <label style="font-size:0.8rem; display:flex; align-items:center; gap:6px;">
                                <input type="checkbox" class="save-mode-toggle"> Enable Save Mode
                            </label>
                        </div>
                        <div class="preset-grid">
                            <div class="preset-btn" data-preset="1">Pos 1</div>
                            <div class="preset-btn" data-preset="2">Pos 2</div>
                            <div class="preset-btn" data-preset="3">Pos 3</div>
                            <div class="preset-btn" data-preset="4">Pos 4</div>
                            <div class="preset-btn" data-preset="5">Pos 5</div>
                            <div class="preset-btn" data-preset="6">Pos 6</div>
                        </div>
                    </div>
                <% end_loop %>
            </div>
        <% end_if %>
    </main>
</div>
<script src="/js/panel-camera.js"></script>
</body>
</html>
