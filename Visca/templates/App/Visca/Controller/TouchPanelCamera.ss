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
        .cam-workspace {
            display: grid;
            grid-template-columns: minmax(320px, 1.3fr) minmax(290px, 1fr);
            gap: 18px;
            align-items: start;
        }
        @media (max-width: 820px) {
            .cam-workspace {
                grid-template-columns: 1fr;
            }
        }
        .cam-preview-card {
            background: var(--card-bg, rgba(15, 23, 42, 0.78));
            border: 1px solid var(--card-border, rgba(148, 163, 184, 0.22));
            border-radius: 18px;
            padding: 14px;
            position: sticky;
            top: 12px;
        }
        @media (max-width: 820px) {
            .cam-preview-card {
                position: static;
            }
        }
        .cam-preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .cam-preview-stage {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            background: #020617;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid rgba(56, 189, 248, 0.25);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .cam-preview-stage img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }
        .cam-preview-badge {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(2, 6, 23, 0.78);
            border: 1px solid rgba(56, 189, 248, 0.4);
            color: #e2e8f0;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .cam-preview-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22c55e;
        }
        .cam-preview-msg {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 16px;
            font-size: 0.9rem;
            color: #94a3b8;
            background: rgba(2, 6, 23, 0.72);
        }
        .cam-workspace .camera-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
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
                <button type="button" id="btn-discover-ptz" class="nav-pill" style="border-color:#38bdf8;">Scan / Set Camera IP</button>
            <% end_if %>
        </div>

        <% if $Cameras %>
            <div id="camera-device-tabs" class="device-tabs" style="margin-bottom:16px;"></div>

            <div class="cam-workspace">
                <div class="cam-preview-card" id="camPreviewCard">
                    <div class="cam-preview-header">
                        <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                            <strong style="color:var(--cam-accent, #38bdf8); font-size:0.95rem;">Live Preview</strong>
                            <span id="camPreviewActiveName" style="font-size:0.85rem; opacity:0.8;"></span>
                        </div>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <button type="button" id="btnCamPreviewToggle" class="nav-pill" style="padding:5px 11px; font-size:0.78rem;">Pause</button>
                            <button type="button" id="btnCamPreviewRefresh" class="nav-pill" style="padding:5px 11px; font-size:0.78rem;">Refresh</button>
                        </div>
                    </div>
                    <div class="cam-preview-stage">
                        <img id="camPreviewImg" alt="Live camera preview">
                        <div class="cam-preview-badge">
                            <span class="cam-preview-dot" id="camPreviewDot"></span>
                            <span id="camPreviewLabel">Connecting…</span>
                        </div>
                        <div class="cam-preview-msg" id="camPreviewMsg">Loading camera preview…</div>
                    </div>
                </div>

                <div class="camera-grid">
                    <% loop $Cameras %>
                        <div class="cam-card" data-device-id="$ID" data-location-id="$LocationID" data-title="$Title" data-ip="$IPAddress">
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

                            <div style="margin-top:20px; display:flex; justify-content:space-between; align-items:center;">
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
            </div>
        <% else %>
            <div class="cam-card" style="text-align:center; padding:24px;">
                <p style="margin:0 0 12px 0;">No active cameras configured yet.</p>
                <% if $CanAccessDiscovery %>
                    <button type="button" class="nav-pill" onclick="document.getElementById('btn-discover-ptz')?.click()">Scan Network for Cameras</button>
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
                <h3 style="margin:0; font-size:1.1rem;">PTZ Camera Cross-Subnet Discovery & IP Setup</h3>
                <div style="font-size:0.8rem; opacity:0.75;">Finds PTZOptics, AIDA Imaging, and OBSBOT cameras across subnets via ONVIF / mDNS / VISCA</div>
            </div>
            <button type="button" id="btn-close-ptz-modal" class="nav-pill">Close</button>
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px;">
            <input type="text" id="ptzSubnetInput" placeholder="Optional IP or CIDR (e.g. 192.168.100.120 or 192.168.100.0/24)" style="flex:1; min-width:220px; background:rgba(15,23,42,0.9); border:1px solid rgba(148,163,184,0.35); color:#f8fafc; padding:10px 12px; border-radius:10px;">
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
<% include App\Includes\SystemPowerModal %>
</body>
</html>
