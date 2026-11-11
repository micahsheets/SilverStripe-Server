<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Unlock AV Touch Panel</title>
    <link rel="stylesheet" href="/css/touch-panel.css">
    <style>
        body { justify-content: center; align-items: center; }
        .lock-box { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 20px; padding: 28px; width: 320px; text-align: center; }
        .pin-display { background: var(--bg-dark); border: 1px solid var(--border); color: var(--accent); font-size: 1.8rem; letter-spacing: 8px; padding: 12px; border-radius: 12px; margin: 16px 0; min-height: 56px; }
        .keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .key-btn { background: var(--bg-card); border: none; color: var(--text-main); font-size: 1.4rem; font-weight: 700; padding: 16px; border-radius: 12px; cursor: pointer; }
        .key-btn:active { background: var(--accent); color: #0f172a; }
        .key-btn.submit { background: var(--success); color: #0f172a; }
        .key-btn.clear { background: rgba(239, 68, 68, 0.25); color: #fca5a5; font-size: 1rem; }
        .err-msg { color: #fca5a5; font-size: 0.85rem; margin-bottom: 10px; }
    </style>
</head>
<body>
    <form class="lock-box" method="POST" action="/panel/unlock">
        <h2 style="margin:0;">VPX AV PANEL LOCKED</h2>
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
