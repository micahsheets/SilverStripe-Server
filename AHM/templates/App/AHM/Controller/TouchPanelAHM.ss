<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> — Allen & Heath AHM</title>
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
        .ahm-toolbar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:space-between; margin-bottom:16px; }
        .ahm-section-title { font-size:0.85rem; text-transform:uppercase; letter-spacing:0.08em; opacity:0.75; margin:18px 0 10px 0; font-weight:800; }
        .ahm-presets-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(165px, 1fr)); gap:10px; margin-bottom:18px; }
        .ahm-preset-btn { padding:14px; border-radius:14px; border:1px solid rgba(148,163,184,0.28); background:rgba(15,23,42,0.78); color:inherit; font-weight:700; font-size:0.95rem; text-align:left; cursor:pointer; transition:all 0.15s ease; }
        body[data-theme="light"] .ahm-preset-btn { background:rgba(255,255,255,0.92); }
        .ahm-preset-btn.active { border-color:#38bdf8; background:rgba(14,165,233,0.24); box-shadow:0 0 16px rgba(56,189,248,0.25); }
        .ahm-strips-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(255px, 1fr)); gap:12px; }
        .ahm-strip-card { background:rgba(15,23,42,0.76); border:1px solid rgba(148,163,184,0.22); border-radius:16px; padding:14px; display:flex; flex-direction:column; gap:10px; }
        body[data-theme="light"] .ahm-strip-card { background:rgba(255,255,255,0.94); }
        .ahm-strip-top { display:flex; justify-content:space-between; align-items:flex-start; gap:8px; }
        .ahm-strip-tag { font-size:0.72rem; opacity:0.7; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; }
        .ahm-strip-name { font-size:1.02rem; font-weight:800; }
        .ahm-db-readout { font-family:monospace; font-size:0.92rem; font-weight:800; color:#38bdf8; }
        .ahm-fader-row { display:flex; align-items:center; gap:10px; width:100%; }
        .ahm-slider { width:100%; accent-color:#38bdf8; height:48px; cursor:pointer; }
        .ahm-mute-btn { flex:0 0 88px; width:88px; height:48px; border-radius:14px; border:2px solid rgba(148,163,184,0.45); background:rgba(30,41,59,0.85); color:inherit; font-size:1.05rem; font-weight:800; letter-spacing:0.03em; cursor:pointer; }
        .ahm-mute-btn.is-muted { background:#dc2626; border-color:#ef4444; color:#ffffff; box-shadow:0 0 14px rgba(239,68,68,0.45); }
        .ahm-subtabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .view-toggle-group { display:inline-flex; border-radius:999px; border:1px solid rgba(148,163,184,0.35); overflow:hidden; background:rgba(15,23,42,0.55); }
        body[data-theme="light"] .view-toggle-group { background:rgba(255,255,255,0.85); }
        .view-toggle-btn { border:none; background:transparent; color:inherit; padding:7px 14px; font-size:0.84rem; font-weight:700; cursor:pointer; opacity:0.78; }
        .view-toggle-btn.active { background:#0ea5e9; color:#ffffff; opacity:1; }
        #ahmWorkspace.is-list-view .ahm-strips-grid { display:flex; flex-direction:column; gap:8px; }
        #ahmWorkspace.is-list-view .ahm-strip-card { flex-direction:row; align-items:center; padding:10px 14px; gap:14px; border-radius:12px; }
        #ahmWorkspace.is-list-view .ahm-strip-top { flex:0 0 230px; align-items:center; }
        #ahmWorkspace.is-list-view .ahm-fader-row { flex:1; }
    </style>
</head>
<body class="audio-panel" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">
    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="ahmConnectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% end_if %></div>
                <div class="brand-sub">Allen &amp; Heath AHM Matrix</div>
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
        <div class="ahm-toolbar">
            <div class="device-tabs" id="ahmDeviceTabs"></div>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                <span id="ahmModelBadge" style="font-size:0.8rem; opacity:0.8; font-weight:700;"></span>
                <div class="view-toggle-group" role="group" aria-label="Layout View">
                    <button type="button" class="view-toggle-btn active" data-view-mode="grid">Grid</button>
                    <button type="button" class="view-toggle-btn" data-view-mode="list">List</button>
                </div>
                <button type="button" class="nav-pill" id="btnRefreshAhm">Sync Mixer</button>
            </div>
        </div>
        <div id="ahmWorkspace"></div>
    </main>
</div>
<div id="status-toast" class="status-toast"></div>
<% include App\AHM\Includes\PanelAHMJS %>
<script src="/js/nav-overflow.js?v=53"></script>
</body>
</html>
