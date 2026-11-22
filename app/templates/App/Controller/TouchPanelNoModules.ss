<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV Control<% end_if %> — Setup Required</title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=71">
    <style>
        .setup-wrap {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
        }
        .setup-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 24px;
            padding: 44px 48px;
            max-width: 560px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
        }
        .setup-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            background: rgba(245, 158, 11, 0.18);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.4);
            margin-bottom: 18px;
        }
        .setup-card h2 {
            margin: 0 0 12px 0;
            font-size: 1.65rem;
            font-weight: 800;
        }
        .setup-card p {
            margin: 0 0 28px 0;
            color: var(--text-muted);
            font-size: 1rem;
            line-height: 1.6;
        }
        .setup-actions {
            display: flex;
            gap: 14px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .setup-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 14px 26px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            text-decoration: none;
            background: var(--accent);
            color: #0f172a;
        }
        .setup-btn.secondary {
            background: var(--control-bg);
            color: var(--text-main);
            border: 1px solid var(--card-border);
        }
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
    <link rel="stylesheet" href="/css/touch-panel-light.css?v=71">
</head>
<body data-theme="$PanelTheme">
<div class="panel-backdrop">
    <header class="panel-header">
        <div class="brand-wrap">
            <span class="status-dot no-devices" id="connectionDot" title="No AV devices configured"></span>
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(36).URL" alt="Logo">
            <% end_if %>
            <div>
                <div class="brand-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV CONTROL<% end_if %></div>
                <div class="brand-sub">System Setup</div>
            </div>
        </div>

        <nav class="nav-tabs">
            <a href="/admin" class="nav-pill">Admin CMS</a>
            <a href="/panel/lock" class="nav-pill lock-btn">Lock Panel</a>
        </nav>
    </header>

    <main class="setup-wrap">
        <div class="setup-card">
            <div class="setup-badge">Setup Required</div>
            <h2>No Control Modules Activated</h2>
            <p>No AV system modules have been enabled for this installation yet. Open the Administration CMS to select active modules and add your hardware devices.</p>
            <div class="setup-actions">
                <a href="/admin/av-settings/App-Model-PanelSettings/EditForm/field/App-Model-PanelSettings/item/$PanelSettings.ID/edit" class="setup-btn">Configure Modules in Admin</a>
                <a href="/panel/discover" class="setup-btn secondary">Network Discovery</a>
            </div>
        </div>
    </main>
</div>

<script src="/js/nav-overflow.js?v=71"></script>
<% include App\Includes\SystemPowerModal %>
</body>
</html>
