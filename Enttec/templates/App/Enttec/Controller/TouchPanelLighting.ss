<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control Touch Panel<% end_if %> — Lighting Control</title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=53">
    <style>
        #enttec-device-meta { font-size: 0.82rem; color: var(--text-muted); margin-bottom: 14px; line-height: 1.4; }
        .toolbar-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
        .action-controls { display: flex; gap: 12px; align-items: center; }
        .btn-blackout {
            padding: 14px 26px;
            border-radius: 12px;
            border: 1px solid rgba(239, 68, 68, 0.7);
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: #fff;
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: 0.04em;
            cursor: pointer;
            box-shadow: 0 4px 18px rgba(220, 38, 38, 0.35);
        }
        .btn-blackout:active { transform: scale(0.97); }
        .btn-secondary {
            padding: 13px 18px;
            border-radius: 12px;
            border: 1px solid var(--card-border);
            background: var(--card-bg);
            color: var(--text-main);
            font-weight: 600;
            cursor: pointer;
        }
        .scenes-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; }
        @media (max-width: 900px) { .scenes-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 560px) { .scenes-grid { grid-template-columns: 1fr; } }
        .scene-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            padding: 24px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.16s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 112px;
            position: relative;
        }
        .scene-card:hover { border-color: var(--accent); transform: translateY(-2px); }
        .scene-card.active {
            background: rgba(56, 189, 248, 0.2);
            border-color: var(--accent);
            box-shadow: 0 0 22px var(--accent-glow);
        }
        .scene-slot-badge {
            position: absolute; top: 10px; left: 12px; font-size: 0.72rem;
            color: var(--text-muted); background: rgba(0, 0, 0, 0.35); padding: 2px 8px; border-radius: 6px;
        }
        .scene-type-badge {
            position: absolute; top: 10px; right: 12px; font-size: 0.7rem;
            color: var(--accent); text-transform: uppercase; letter-spacing: 0.05em;
        }
        .scene-title { font-size: 1.15rem; font-weight: 700; word-break: break-word; }
        .recorder-drawer { margin-top: 28px; display: none; }
        .recorder-drawer.open { display: block; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-top: 14px; }
        .form-group label { display: block; font-size: 0.82rem; color: var(--text-muted); margin-bottom: 6px; font-weight: 600; }
        .form-group select, .form-group input {
            width: 100%; padding: 11px 12px; border-radius: 10px;
            border: 1px solid var(--card-border); background: var(--control-bg); color: var(--text-main); font-size: 0.95rem;
        }
        .universe-checkboxes { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 4px; }
        .universe-pill {
            display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 8px;
            background: var(--control-bg); border: 1px solid var(--card-border); font-size: 0.85rem; cursor: pointer;
        }
        .recorder-actions { display: flex; gap: 12px; align-items: center; margin-top: 18px; flex-wrap: wrap; }
        .btn-primary { padding: 12px 22px; border-radius: 10px; border: none; background: var(--accent); color: #082f49; font-weight: 700; cursor: pointer; }
        .btn-danger { padding: 12px 20px; border-radius: 10px; border: 1px solid rgba(239, 68, 68, 0.6); background: rgba(239, 68, 68, 0.18); color: #fca5a5; font-weight: 700; cursor: pointer; }
    </style>
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
<body class="lighting-panel" data-theme="$PanelTheme" data-csrf="$SecurityID">
<div class="panel-backdrop">
    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot" id="connectionDot"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title">$PanelSettings.PanelTitle</div>
                <div class="brand-sub">Lighting Control • Signed in as $CurrentActorName</div>
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
        <div class="toolbar-row">
            <div id="enttec-device-tabs" class="device-tabs"></div>
            <div class="action-controls">
                <button type="button" id="btn-toggle-recorder" class="btn-secondary">Manage / Record Scenes</button>
                <button type="button" id="btn-blackout" class="btn-blackout">BLACKOUT</button>
            </div>
        </div>

        <div id="enttec-device-meta"></div>

        <div id="enttec-scenes-grid" class="scenes-grid">
            <div style="color:var(--text-muted);">Loading lighting scenes...</div>
        </div>

        <section id="enttec-recorder-drawer" class="recorder-drawer">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                <h3 style="margin:0; font-size:1.05rem;">Record / Edit Lighting Scene</h3>
                <div style="display:flex; align-items:center; gap:12px;">
                    <span id="enttec-model-hint" style="font-size:0.8rem; color:var(--accent);"></span>
                    <button type="button" id="btn-toggle-advanced" class="nav-pill" style="padding:7px 12px; font-size:0.8rem;" aria-expanded="false">
                        Advanced
                    </button>
                </div>
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label for="slot-select">Playlist Slot</label>
                    <select id="slot-select"></select>
                </div>
                <div class="form-group">
                    <label for="scene-name-input">Scene Name</label>
                    <input type="text" id="scene-name-input" placeholder="e.g., House Full, Presentation, Stage Warm">
                </div>
                <div class="form-group">
                    <label for="cue-type-select">Capture Type</label>
                    <select id="cue-type-select">
                        <option value="0">Static Snapshot</option>
                        <option value="1" id="opt-dynamic-capture">Dynamic Recording</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="source-type-select">Input Source</label>
                    <select id="source-type-select">
                        <option value="0">DMX Input</option>
                        <option value="1">Art-Net</option>
                        <option value="2">sACN</option>
                    </select>
                </div>
            </div>

            <div id="enttec-advanced-options" class="form-group" style="display:none; margin-top:14px; padding-top:14px; border-top:1px solid var(--card-border);">
                <label>Universes to Capture</label>
                <div id="universe-checkboxes" class="universe-checkboxes"></div>
            </div>

            <div class="recorder-actions">
                <button type="button" id="btn-record-scene" class="btn-primary">Record Static Preset</button>
                <button type="button" id="btn-stop-recording" class="btn-blackout" style="display:none;">
                    Stop Recording (<span id="record-timer">00:00.0</span>)
                </button>
                <button type="button" id="btn-delete-scene" class="btn-danger" style="display:none;">Delete Playlist</button>
            </div>
        </section>
    </main>
</div>

<div id="status-toast" class="status-toast"></div>
<% include App\Enttec\Includes\PanelLightingJS %>
<script src="/js/nav-overflow.js?v=53"></script>
</body>
</html>
