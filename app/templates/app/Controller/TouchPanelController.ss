<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>AV Control Touch Panel</title>
    <link rel="stylesheet" href="/css/touch-panel.css">
</head>
<body>
    <header>
        <div class="brand">
            <span class="status-dot" id="connectionDot"></span>
            <span>VPX AV MATRIX</span>
        </div>
        <div class="room-tabs" id="roomTabs"></div>
    </header>

    <main>
        <section id="presetsSection">
            <h2>Scene Presets</h2>
            <div class="presets-grid" id="presetsGrid"></div>
        </section>

        <div class="routing-columns">
            <div class="column-box">
                <div class="column-header">
                    <h2 style="margin:0;">1. Select Display(s)</h2>
                    <span class="hint" id="selectionHint">Tap displays to select</span>
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
