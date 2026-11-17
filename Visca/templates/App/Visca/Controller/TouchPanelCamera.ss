<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> — Camera Control</title>
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
    <style>
        .ptz-discover-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(2, 6, 23, 0.82);
            backdrop-filter: blur(8px);
            z-index: 9999;
            padding: 16px;
            overflow-y: auto;
        }
        .ptz-discover-box {
            max-width: 780px;
            margin: 12px auto;
            background: var(--card-bg, #0f172a);
            border: 1px solid var(--card-border, rgba(148,163,184,0.25));
            border-radius: 18px;
            padding: 18px;
            color: var(--text-main, #f8fafc);
        }
        .ptz-disc-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            padding: 12px;
            margin-top: 10px;
            border-radius: 12px;
            background: rgba(15, 23, 42, 0.55);
            border: 1px solid rgba(56, 189, 248, 0.25);
        }
        .ptz-disc-inputs {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 8px;
        }
        .ptz-disc-inputs input {
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(148, 163, 184, 0.35);
            color: #f8fafc;
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 0.88rem;
            width: 100%;
            box-sizing: border-box;
        }
    </style>
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
        <div style="display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:14px;">
            <div class="room-tabs unified-room-tabs" style="margin-bottom:0;">
                <button type="button" class="room-tab active" data-location-id="0">All Rooms</button>
                <% loop $Locations %>
                    <button type="button" class="room-tab" data-location-id="$ID">$Title</button>
                <% end_loop %>
            </div>
            <% if $CanAccessDiscovery %>
                <button type="button" id="btn-discover-ptz" class="nav-pill" style="border-color:#38bdf8;">Scan / Set PTZOptics IP</button>
            <% end_if %>
        </div>

        <% if $Cameras %>
            <div id="camera-device-tabs" class="device-tabs" style="margin-bottom:18px;"></div>
            <div class="camera-grid">
                <% loop $Cameras %>
                    <div class="cam-card" data-device-id="$ID" data-location-id="$LocationID">
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
        <% else %>
            <div class="cam-card" style="text-align:center; padding:24px;">
                <p style="margin:0 0 12px 0;">No active cameras configured yet.</p>
                <% if $CanAccessDiscovery %>
                    <button type="button" class="nav-pill" onclick="document.getElementById('btn-discover-ptz')?.click()">Scan Network for PTZOptics Move SE</button>
                <% end_if %>
            </div>
        <% end_if %>
    </main>
</div>

<% if $CanAccessDiscovery %>
<div id="ptzDiscoverModal" class="ptz-discover-modal">
    <div class="ptz-discover-box">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:12px;">
            <div>
                <h3 style="margin:0; font-size:1.1rem;">PTZOptics Cross-Subnet Discovery & IP Setup</h3>
                <div style="font-size:0.8rem; opacity:0.75;">Finds Move SE cameras across subnets (e.g. 192.168.100.4) via ONVIF / mDNS multicast</div>
            </div>
            <button type="button" id="btn-close-ptz-modal" class="nav-pill">Close</button>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">
            <input type="text" id="ptzSubnetInput" placeholder="Optional IP or CIDR (e.g. 192.168.100.4 or 192.168.100.0/24)" style="flex:1; min-width:220px; background:rgba(15,23,42,0.9); border:1px solid rgba(148,163,184,0.35); color:#f8fafc; padding:10px 12px; border-radius:10px;">
            <button type="button" id="btn-run-ptz-scan" class="nav-pill" style="background:#38bdf8; color:#0f172a; font-weight:800;">Scan Now</button>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; font-size:0.8rem; opacity:0.85; margin-bottom:8px;">
            <span>Camera Admin Auth:</span>
            <input type="text" id="ptzAuthUser" value="admin" placeholder="Username" style="width:100px; background:rgba(15,23,42,0.9); border:1px solid rgba(148,163,184,0.35); color:#f8fafc; padding:6px 8px; border-radius:8px;">
            <input type="password" id="ptzAuthPass" value="admin" placeholder="Password" style="width:110px; background:rgba(15,23,42,0.9); border:1px solid rgba(148,163,184,0.35); color:#f8fafc; padding:6px 8px; border-radius:8px;">
        </div>

        <div id="ptzDiscoverStatus" style="font-size:0.85rem; color:#38bdf8; margin:6px 0;"></div>
        <div id="ptzDiscoverResults"></div>
    </div>
</div>
<% end_if %>

<div id="status-toast" class="status-toast"></div>
<% include App\Visca\Includes\PanelCameraJS %>
<script src="/js/nav-overflow.js?v=53"></script>
</body>
</html>
