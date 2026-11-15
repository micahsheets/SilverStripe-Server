<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>VPX AV MATRIX<% end_if %></title>
    <link rel="stylesheet" href="/css/touch-panel.css">
    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            width: 100%;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #0a0e17;
            color: #f8fafc;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }
        .splash-screen {
            position: fixed;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 32px;
            cursor: pointer;
            transition: transform 0.15s ease, opacity 0.2s ease;
        }
        .splash-screen:active {
            transform: scale(0.99);
        }
        .splash-card {
            background: rgba(15, 23, 42, 0.72);
            border: 1px solid rgba(255, 255, 255, 0.14);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-radius: 24px;
            padding: 48px 56px;
            max-width: 560px;
            width: 100%;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
        }
        body[data-theme="light"] .splash-card {
            background: rgba(255, 255, 255, 0.88);
            border: 1px solid rgba(15, 23, 42, 0.14);
            color: #0f172a;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.14);
        }
        .splash-logo {
            max-height: 84px;
            width: auto;
            margin-bottom: 24px;
        }
        .splash-title {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            margin: 0 0 12px 0;
        }
        .splash-prompt {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 28px;
            border-radius: 999px;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            background: rgba(56, 189, 248, 0.18);
            border: 1px solid rgba(56, 189, 248, 0.45);
            color: #38bdf8;
            animation: pulsePrompt 2.4s infinite ease-in-out;
        }
        body[data-theme="light"] .splash-prompt {
            background: #0284c7;
            border-color: #0284c7;
            color: #ffffff;
        }
        @keyframes pulsePrompt {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.72; transform: scale(0.98); }
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
    </style>
    <% end_if %>
</head>
<body data-theme="$PanelTheme">
    <main class="splash-screen" id="splashTrigger" role="button" tabindex="0" aria-label="Tap to start">
        <div class="splash-card">
            <% if $PanelSettings.PanelLogo %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(84).URL" alt="Logo" class="splash-logo">
            <% end_if %>
            <h1 class="splash-title">
                <% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>VPX AV MATRIX<% end_if %>
            </h1>
            <div class="splash-prompt">Tap Anywhere to Begin</div>
        </div>
    </main>

    <script>
        (function () {
            const targetUrl = '$NextRoute.JS';
            const trigger = document.getElementById('splashTrigger');

            function enterPanel() {
                window.location.href = targetUrl;
            }

            trigger.addEventListener('click', enterPanel);
            trigger.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    enterPanel();
                }
            });
        })();
    </script>
</body>
</html>
