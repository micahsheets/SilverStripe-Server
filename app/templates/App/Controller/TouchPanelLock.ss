<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>Unlock AV Touch Panel<% end_if %></title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=28">
    <style>
        body.lock-panel { justify-content: center; align-items: center; padding: 24px; }
        .lock-box { background: var(--card-bg); border: 1px solid var(--card-border); border-radius: 20px; padding: 28px; width: 320px; text-align: center; box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35); }
        .pin-display { background: var(--control-bg); border: 1px solid var(--card-border); color: var(--accent); font-size: 1.8rem; letter-spacing: 8px; padding: 12px; border-radius: 12px; margin: 16px 0; min-height: 56px; }
        .keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .key-btn { background: var(--control-bg); border: 1px solid var(--card-border); color: var(--text-main); font-size: 1.4rem; font-weight: 700; padding: 16px; border-radius: 12px; cursor: pointer; }
        .key-btn:active { background: var(--accent); color: #0f172a; }
        .key-btn.submit { background: var(--success); border-color: var(--success); color: #0f172a; }
        .key-btn.clear { background: rgba(239, 68, 68, 0.18); border-color: rgba(239, 68, 68, 0.42); color: #fca5a5; font-size: 1rem; }
        body[data-theme="light"] .key-btn.clear { background: rgba(254, 226, 226, 0.85); color: #b91c1c; }
        .err-msg { color: #fca5a5; font-size: 0.85rem; margin-bottom: 10px; }
        body[data-theme="light"] .err-msg { color: #b91c1c; }
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
</style>
<% end_if %>
    <link rel="stylesheet" href="/css/touch-panel-light.css?v=28">
</head>
<body class="lock-panel" data-theme="$PanelTheme">
    <form class="lock-box" method="POST" action="/panel/unlock">
        <% if $PanelSettings.PanelLogo.exists %>
            <img src="$PanelSettings.PanelLogo.ScaleHeight(44).URL" alt="Logo" style="max-height:44px; width:auto; margin-bottom:10px;">
        <% end_if %>
        <h2 style="margin:0;"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>VPX AV PANEL LOCKED<% end_if %></h2>
        <div class="pin-display" id="pinDots"></div>
        <% if $ErrorMessage %><div class="err-msg">$ErrorMessage</div><% end_if %>
        <input type="hidden" name="SecurityID" value="$CSRFToken">
        <input type="hidden" name="pin" id="pinInput">
        <div class="keypad">
            <button type="button" class="key-btn" onclick="addDigit('1')">1</button>
            <button type="button" class="key-btn" onclick="addDigit('2')">2</button>
            <button type="button" class="key-btn" onclick="addDigit('3')">3</button>
            <button type="button" class="key-btn" onclick="addDigit('4')">4</button>
            <button type="button" class="key-btn" onclick="addDigit('5')">5</button>
            <button type="button" class="key-btn" onclick="addDigit('6')">6</button>
            <button type="button" class="key-btn" onclick="addDigit('7')">7</button>
            <button type="button" class="key-btn" onclick="addDigit('8')">8</button>
            <button type="button" class="key-btn" onclick="addDigit('9')">9</button>
            <button type="button" class="key-btn clear" onclick="clearPin()">CLEAR</button>
            <button type="button" class="key-btn" onclick="addDigit('0')">0</button>
            <button type="submit" class="key-btn submit">GO</button>
        </div>
    </form>
    <script>
        let pin = '';
        function addDigit(d) { if (pin.length < 8) { pin += d; updatePin(); } }
        function clearPin() { pin = ''; updatePin(); }
        function updatePin() {
            document.getElementById('pinInput').value = pin;
            document.getElementById('pinDots').textContent = '•'.repeat(pin.length);
        }
    </script>
</body>
</html>
