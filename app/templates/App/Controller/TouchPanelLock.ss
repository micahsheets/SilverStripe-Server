<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>Unlock AV Touch Panel<% end_if %></title>
    <link rel="stylesheet" href="/css/touch-panel.css?v=70">
    <style>
        body.lock-panel {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            min-height: 100vh;
            box-sizing: border-box;
        }
        .lock-box {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 28px;
            padding: 32px 36px;
            width: 100%;
            max-width: 560px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
            box-sizing: border-box;
        }
        .lock-title {
            margin: 0;
            font-size: 1.85rem;
            font-weight: 800;
            letter-spacing: 0.03em;
        }
        .pin-display {
            background: var(--control-bg);
            border: 2px solid var(--card-border);
            color: var(--accent);
            font-size: 2.6rem;
            letter-spacing: 14px;
            padding: 14px 20px;
            border-radius: 16px;
            margin: 20px 0 24px 0;
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
        }
        .keypad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        .key-btn {
            background: var(--control-bg);
            border: 2px solid var(--card-border);
            color: var(--text-main);
            font-size: 2.15rem;
            font-weight: 700;
            min-height: 88px;
            padding: 16px;
            border-radius: 18px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.08s ease, background-color 0.12s ease;
        }
        .key-btn:active {
            background: var(--accent);
            color: #0f172a;
            transform: scale(0.97);
        }
        .key-btn.submit {
            background: var(--success);
            border-color: var(--success);
            color: #0f172a;
            font-size: 1.75rem;
            font-weight: 800;
            letter-spacing: 0.05em;
        }
        .key-btn.clear {
            background: rgba(239, 68, 68, 0.22);
            border-color: rgba(239, 68, 68, 0.55);
            color: #fca5a5;
            font-size: 1.45rem;
            font-weight: 800;
            letter-spacing: 0.04em;
        }
        body[data-theme="light"] .key-btn.clear {
            background: rgba(254, 226, 226, 0.9);
            border-color: rgba(220, 38, 38, 0.5);
            color: #b91c1c;
        }
        .err-msg {
            color: #fca5a5;
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 14px;
        }
        body[data-theme="light"] .err-msg {
            color: #b91c1c;
        }
    </style>
<% if $PanelSettings.PanelBackground %>
<style>
    html body {
        background-image:
            linear-gradient(rgba(15, 23, 42, 0.{$PanelSettings.BackgroundOverlayOpacity}), rgba(15, 23, 42, 0.{$PanelSettings.BackgroundOverlayOpacity})),
            url("$PanelSettings.PanelBackground.ScaleWidth(1920).URL") !important;
        background-size: cover !important;
        background-position: center !important;
        background-attachment: fixed !important;
    }
    html body[data-theme="light"] {
        background-image:
            linear-gradient(rgba(248, 250, 252, 0.{$PanelSettings.BackgroundOverlayOpacity}), rgba(248, 250, 252, 0.{$PanelSettings.BackgroundOverlayOpacity})),
            url("$PanelSettings.PanelBackground.ScaleWidth(1920).URL") !important;
    }
</style>
<% end_if %>
    <link rel="stylesheet" href="/css/touch-panel-light.css?v=70">
</head>
<body class="lock-panel" data-theme="$PanelTheme" style="cursor:pointer;">
    <form class="lock-box" method="POST" action="/panel/unlock" style="cursor:default;" onclick="event.stopPropagation()">
        <% if $PanelSettings.PanelLogo.exists %>
            <img src="$PanelSettings.PanelLogo.ScaleHeight(64).URL" alt="Logo" style="max-height:64px; width:auto; margin-bottom:12px;">
        <% end_if %>
        <h2 class="lock-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>AV PANEL LOCKED<% end_if %></h2>
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
        document.body.addEventListener('click', function (e) {
            if (e.target.closest('.lock-box') || e.target.closest('.sys-pwr-modal') || e.target.closest('#sysPowerHeaderBtn')) {
                return;
            }
            window.location.href = '/panel/splash';
        });
        let pin = "";
        function addDigit(d) { if (pin.length < 8) { pin += d; updatePin(); } }
        function clearPin() { pin = ""; updatePin(); }
        function updatePin() {
            document.getElementById("pinInput").value = pin;
            document.getElementById("pinDots").textContent = "•".repeat(pin.length);
        }
    </script>
<% include App\Includes\SystemPowerModal %>
</body>
</html>
