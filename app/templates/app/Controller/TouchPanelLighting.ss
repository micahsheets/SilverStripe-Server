<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title>$SiteConfig.PanelTitle — Lighting Control</title>
    <link rel="stylesheet" href="/css/touch-panel.css">
    <style>
        :root {
            --bg-overlay: rgba(10, 14, 23, 0.85);
            --card-bg: rgba(23, 31, 48, 0.78);
            --card-border: rgba(255, 255, 255, 0.1);
            --accent: #38bdf8;
            --accent-glow: rgba(56, 189, 248, 0.35);
            --danger: #ef4444;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }
        body.lighting-panel {
            margin: 0;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--text-main);
            background: #0a0e17 <% if $SiteConfig.PanelBackground %>url('$SiteConfig.PanelBackground.URL') center/cover fixed<% end_if %>;
        }
        .panel-backdrop {
            min-height: 100vh;
            background: var(--bg-overlay);
            backdrop-filter: blur(12px);
            display: flex;
            flex-direction: column;
        }
        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 24px;
            background: rgba(15, 23, 42, 0.85);
            border-bottom: 1px solid var(--card-border);
            gap: 16px;
            flex-wrap: wrap;
        }
        .brand-wrap { display: flex; align-items: center; gap: 12px; }
        .brand-wrap img { height: 36px; width: auto; }
        .brand-title { font-size: 1.15rem; font-weight: 700; letter-spacing: 0.04em; }
        .nav-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
        .nav-pill {
            padding: 10px 16px;
            border-radius: 10px;
            text-decoration: none;
            color: var(--text-muted);
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--card-border);
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.15s ease;
        }
        .nav-pill.active, .nav-pill:hover {
            color: #fff;
            background: rgba(56, 189, 248, 0.18);
            border-color: var(--accent);
        }
        .nav-pill.lock-btn { color: #fca5a5; border-color: rgba(239, 68, 68, 0.35); }
        .panel-main { max-width: 1280px; width: 100%; margin: 0 auto; padding: 24px; box-sizing: border-box; flex: 1; }
        .toolbar-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
        .device-tabs { display: flex; gap: 10px; flex-wrap: wrap; }
        .device-tab {
            padding: 11px 18px;
            border-radius: 10px;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            color: var(--text-muted);
            font-weight: 600;
            cursor: pointer;
        }
        .device-tab.active { color: #fff; border-color: var(--accent); box-shadow: 0 0 14px var(--accent-glow); }
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
        .recorder-drawer {
            margin-top: 28px; background: rgba(15, 23, 42, 0.9);
            border: 1px solid var(--card-border); border-radius: 16px; padding: 22px; display: none;
        }
        .recorder-drawer.open { display: block; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-top: 14px; }
        .form-group label { display: block; font-size: 0.82rem; color: var(--text-muted); margin-bottom: 6px; font-weight: 600; }
        .form-group select, .form-group input {
            width: 100%; box-sizing: border-box; padding: 11px 12px; border-radius: 10px;
            border: 1px solid var(--card-border); background: rgba(30, 41, 59, 0.9); color: #fff; font-size: 0.95rem;
        }
        .universe-checkboxes { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 4px; }
        .universe-pill {
            display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border-radius: 8px;
            background: rgba(30, 41, 59, 0.9); border: 1px solid var(--card-border); font-size: 0.85rem; cursor: pointer;
        }
        .recorder-actions { display: flex; gap: 12px; align-items: center; margin-top: 18px; flex-wrap: wrap; }
        .btn-primary { padding: 12px 22px; border-radius: 10px; border: none; background: var(--accent); color: #082f49; font-weight: 700; cursor: pointer; }
        .btn-danger { padding: 12px 20px; border-radius: 10px; border: 1px solid rgba(239, 68, 68, 0.6); background: rgba(239, 68, 68, 0.18); color: #fca5a5; font-weight: 700; cursor: pointer; }
        .status-toast {
            position: fixed; bottom: 20px; right: 20px; background: rgba(15, 23, 42, 0.95);
            border: 1px solid var(--accent); padding: 12px 18px; border-radius: 12px; font-size: 0.9rem; z-index: 9999; display: none;
        }
    </style>
</head>
<body class="lighting-panel" data-csrf="$CSRFToken">
<div class="panel-backdrop">
    <header class="panel-header">
        <div class="brand-wrap">
            <% if $SiteConfig.PanelLogo %>
                <img src="$SiteConfig.PanelLogo.URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title">$SiteConfig.PanelTitle</div>
                <div style="font-size:0.78rem; color:var(--text-muted);">Lighting Control • Signed in as $CurrentActorName</div>
            </div>
        </div>

        <nav class="nav-tabs">
            <% if $CanAccessMatrix %><a href="/panel" class="nav-pill">Video Matrix</a><% end_if %>
            <% if $CanAccessAudio %><a href="/panel/audio" class="nav-pill">Audio DSP</a><% end_if %>
            <% if $CanAccessLighting %><a href="/panel/lighting" class="nav-pill active">Lighting</a><% end_if %>
            <% if $CanAccessDiscovery %><a href="/panel/discover" class="nav-pill">Discovery</a><% end_if %>
            <a href="/panel/lock" class="nav-pill lock-btn">Lock Panel</a>
        </nav>
    </header>

    <main class="panel-main">
        <div class="toolbar-row">
            <div id="enttec-device-tabs" class="device-tabs"></div>
            <div class="action-controls">
                <button type="button" id="btn-toggle-recorder" class="btn-secondary">Manage / Record Scenes</button>
                <button type="button" id="btn-blackout" class="btn-blackout">BLACKOUT</button>
            </div>
        </div>

        <div id="enttec-device-meta" style="font-size:0.82rem; color:var(--text-muted); margin-bottom:14px;"></div>

        <div id="enttec-scenes-grid" class="scenes-grid">
            <div style="color:var(--text-muted);">Loading lighting scenes...</div>
        </div>

        <section id="enttec-recorder-drawer" class="recorder-drawer">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h3 style="margin:0; font-size:1.05rem;">Record / Edit Lighting Scene</h3>
                <span id="enttec-model-hint" style="font-size:0.8rem; color:var(--accent);"></span>
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

            <div class="form-group" style="margin-top:14px;">
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
<script src="/js/panel-lighting.js"></script>
</body>
</html>
