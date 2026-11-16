<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> - Shure P300 DSP</title>
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
        .p300-device-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .p300-hero-card {
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: center;
            justify-content: space-between;
        }
        body[data-theme="light"] .p300-hero-card { background: rgba(255, 255, 255, 0.9); }
        .p300-master-mute-btn {
            min-height: 56px;
            padding: 0 26px;
            border-radius: 12px;
            border: 2px solid #22c55e;
            background: rgba(34, 197, 94, 0.16);
            color: #4ade80;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .p300-master-mute-btn.is-muted {
            border-color: #ef4444;
            background: #dc2626;
            color: #ffffff;
            box-shadow: 0 0 18px rgba(239, 68, 68, 0.45);
        }
        .p300-presets-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .p300-preset-btn {
            padding: 14px 12px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            background: rgba(30, 41, 59, 0.75);
            color: inherit;
            font-weight: 600;
            text-align: left;
            cursor: pointer;
        }
        body[data-theme="light"] .p300-preset-btn { background: #ffffff; }
        .p300-preset-btn.active {
            border-color: #38bdf8;
            background: rgba(14, 165, 233, 0.22);
        }
        .p300-channels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 12px;
        }
        .p300-chan-card {
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 14px;
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        body[data-theme="light"] .p300-chan-card { background: rgba(255, 255, 255, 0.92); }
        .p300-chan-top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
        .p300-chan-num { font-size:0.75rem; opacity:0.7; font-weight:600; }
        .p300-chan-name { font-size:1rem; font-weight:700; }
        .p300-db-readout { font-family: monospace; font-size: 0.9rem; font-weight: 700; }
        .p300-slider { width: 100%; accent-color: #38bdf8; height: 48px; cursor: pointer; }
        .slider-mute-row {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
        }
        .slider-mute-row .p300-slider { width: 100%; accent-color: #38bdf8; height: 48px; cursor: pointer; }
        .p300-chan-mute { flex: 0 0 90px; width: 90px; min-width: 90px; height: 48px; padding: 0 10px; border-radius: 16px; border: 2px solid rgba(148, 163, 184, 0.45); background: rgba(30, 41, 59, 0.85); color: inherit; font-size: 1.15rem; font-weight: 800; letter-spacing: 0.03em; display: inline-flex; align-items: center; justify-content: center; text-align: center; cursor: pointer; }
        .p300-chan-mute.is-muted {
            background: #dc2626;
            border-color: #ef4444;
            color: #fff;
        }
    </style>
</head>
<body class="audio-panel" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">

    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="p300ConnectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% end_if %></div>
                <div class="brand-sub">Shure IntelliMix P300 DSP</div>
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
        <div class="p300-device-bar">
            <div class="device-tabs" id="p300DeviceTabs"></div>
            <button type="button" class="nav-pill" onclick="flashActiveP300()">Locate / Flash LEDs</button>
        </div>

        <div id="p300Workspace"></div>
    </main>

</div>

<div id="toast"></div>
<% include App\P300\Includes\PanelP300JS %>
<script src="/js/nav-overflow.js?v=53"></script>
</body>
</html>
