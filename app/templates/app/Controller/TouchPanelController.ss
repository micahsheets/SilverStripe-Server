<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><% if $SiteConfig.PanelTitle %>$SiteConfig.PanelTitle<% else %>AV Control Touch Panel<% end_if %></title>
    <link rel="stylesheet" href="/css/touch-panel.css">
    <% if $SiteConfig.PanelBackground.exists %>
    <style>
        body {
            background-image:
                linear-gradient(rgba(15, 23, 42, 0.{$SiteConfig.BackgroundOverlayOpacity}), rgba(15, 23, 42, 0.{$SiteConfig.BackgroundOverlayOpacity})),
                url('$SiteConfig.PanelBackground.ScaleWidth(1920).URL');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
        }
        .column-box, header {
            background-color: rgba(30, 41, 59, 0.88);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
    </style>
    <% end_if %>
</head>
<body>
    <header>
        <div class="brand">
            <span class="status-dot" id="connectionDot"></span>
            <% if $SiteConfig.PanelLogo.exists %>
                <img src="$SiteConfig.PanelLogo.ScaleHeight(36).URL" alt="$SiteConfig.PanelTitle" style="max-height:36px; width:auto;">
            <% end_if %>
            <span><% if $SiteConfig.PanelTitle %>$SiteConfig.PanelTitle<% else %>VPX AV MATRIX<% end_if %></span>
        </div>
        <div class="header-actions">
            <div class="room-tabs" id="roomTabs"></div>
            <a href="/panel/discover" class="room-tab" style="text-decoration:none;">Discover</a>
            <a href="/panel/lock" class="lock-btn">Lock Panel</a>
        </div>
    </header>

    <main>
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

    <div id="toast"></div>
    <script src="/js/panel-api.js"></script>
    <script src="/js/panel-ui.js"></script>
</body>
</html>
