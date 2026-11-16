<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> - RØDECaster Video</title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=29">
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
    <link rel="stylesheet" href="/css/touch-panel-light.css?v=9">
    <% include App\RodeCaster\Includes\RodeCasterPanelCSS %>
    <style>
        .rc-mode-controls {
            display: inline-flex;
            align-items: center;
            background: var(--control-bg);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 3px;
            gap: 4px;
        }
        .rc-mode-btn {
            background: transparent;
            border: 1px solid transparent;
            color: var(--text-muted);
            padding: 9px 14px;
            border-radius: 9px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .rc-mode-btn:hover {
            color: var(--text-main);
        }
        .rc-mode-btn.active {
            background: var(--accent);
            color: #082f49;
            box-shadow: 0 2px 10px var(--accent-glow);
        }
    </style>
</head>
<body class="video-panel rodecaster-panel" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">

    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="connectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% end_if %></div>
                <div class="brand-sub">RØDECaster Video Control</div>
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
        <div class="matrix-toolbar" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div class="device-tabs" id="rcDeviceTabs"></div>
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <div class="rc-mode-controls" role="group" aria-label="Switching Mode">
                    <button type="button" id="btnModeStudio" class="rc-mode-btn" onclick="triggerRodeCaster('studio', 0, 'Studio Mode')">STUDIO</button>
                    <button type="button" id="btnModeInstant" class="rc-mode-btn" onclick="triggerRodeCaster('instant', 0, 'Instant Mode')">INSTANT</button>
                </div>
                <div class="rc-volume-control">
                    <label for="rc-master-vol" class="rc-vol-label">HDMI / USB MIX</label>
                    <input id="rc-master-vol" type="range" min="0" max="100" value="65" class="rc-vol-slider" />
                    <span id="rc-master-vol-val" class="rc-vol-value">65%</span>
                </div>
                <div class="rc-transition-controls" id="rcTransitionControls">
                    <button type="button" class="rc-trans-btn cut" onclick="triggerRodeCaster('cut', 0, 'CUT')">CUT</button>
                    <button type="button" class="rc-trans-btn auto" onclick="triggerRodeCaster('auto', 0, 'AUTO')">AUTO</button>
                </div>
                <div id="rcDeviceStatus"></div>
            </div>
        </div>

        <div class="routing-columns">
            <div class="column-box">
                <div class="column-header">
                    <div>
                        <h2 style="margin:0;">Scenes</h2>
                        <span class="hint">Recall multi-source layouts</span>
                    </div>
                </div>
                <div class="rc-button-grid" id="rcScenesGrid"></div>
            </div>

            <div class="column-box">
                <div class="column-header">
                    <div>
                        <h2 style="margin:0;">Inputs</h2>
                        <span class="hint">Direct camera &amp; HDMI switching</span>
                    </div>
                </div>
                <div class="rc-button-grid" id="rcInputsGrid"></div>
            </div>

            <div class="column-box">
                <div class="column-header">
                    <div>
                        <h2 style="margin:0;">Overlays</h2>
                        <span class="hint">Toggle lower thirds &amp; graphics</span>
                    </div>
                </div>
                <div class="rc-button-grid" id="rcOverlaysGrid"></div>
            </div>

            <div class="column-box">
                <div class="column-header">
                    <div>
                        <h2 style="margin:0;">Media</h2>
                        <span class="hint">Trigger video &amp; audio clips</span>
                    </div>
                </div>
                <div class="rc-button-grid" id="rcMediaGrid"></div>
            </div>
        </div>
    </main>

</div>

<div id="toast"></div>
<% include App\RodeCaster\Includes\PanelRodeCasterJS %>
</body>
</html>
