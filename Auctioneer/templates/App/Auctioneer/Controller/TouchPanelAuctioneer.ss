<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>Auctioneer Mic Kiosk<% end_if %></title>
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
        html, body { height: 100%; margin: 0; overflow-x: hidden; }
        .auc-shell { min-height: 100vh; display: flex; flex-direction: column; padding: 10px 12px; box-sizing: border-box; gap: 10px; }
        .auc-topbar { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px 12px; border-radius: 14px; background: rgba(15, 23, 42, 0.72); border: 1px solid rgba(148, 163, 184, 0.25); }
        body[data-theme="light"] .auc-topbar { background: rgba(255, 255, 255, 0.88); }
        .auc-brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .auc-brand img { max-height: 32px; width: auto; object-fit: contain; flex-shrink: 0; }
        .auc-station-badge { font-weight: 700; font-size: 0.95rem; letter-spacing: 0.02em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .auc-station-sub { font-size: 0.75rem; opacity: 0.75; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .auc-btn { border: 1px solid rgba(148, 163, 184, 0.35); background: rgba(30, 41, 59, 0.75); color: inherit; border-radius: 12px; padding: 10px 14px; font-weight: 600; font-size: 0.92rem; cursor: pointer; touch-action: manipulation; }
        body[data-theme="light"] .auc-btn { background: #f1f5f9; }
        .auc-btn.primary { background: #2563eb; border-color: #3b82f6; color: #fff; }
        .auc-btn.success { background: #16a34a; border-color: #22c55e; color: #fff; }
        .auc-btn.danger { background: #dc2626; border-color: #ef4444; color: #fff; }
        .auc-btn:disabled { opacity: 0.4; cursor: not-allowed; }

        .auc-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; flex: 1; align-content: start; }
        @media (min-width: 680px) { .auc-grid { grid-template-columns: repeat(3, 1fr); } }
        .auc-name-btn { min-height: 68px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 10px; border-radius: 14px; background: rgba(15, 23, 42, 0.72); border: 1px solid rgba(148, 163, 184, 0.28); color: inherit; font-size: 1.05rem; font-weight: 700; cursor: pointer; touch-action: manipulation; }
        body[data-theme="light"] .auc-name-btn { background: rgba(255, 255, 255, 0.92); }
        .auc-name-btn:active { transform: scale(0.98); border-color: #3b82f6; }

        .auc-pager { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .auc-page-label { font-size: 0.88rem; font-weight: 600; opacity: 0.85; }

        .auc-splash-screen { flex: 1; display: none; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 12px; gap: 12px; }
        .auc-splash-logo { max-width: 220px; max-height: 96px; width: auto; height: auto; object-fit: contain; }
        .auc-splash-title { font-size: 1.35rem; font-weight: 800; letter-spacing: 0.02em; margin: 0; }
        .auc-splash-lane { font-size: 0.92rem; opacity: 0.82; margin: 0; }
        .auc-splash-logout-btn { margin-top: 6px; min-width: 220px; padding: 15px 28px; font-size: 1.1rem; font-weight: 700; border-radius: 999px; background: #dc2626; border: 1px solid #ef4444; color: #ffffff; cursor: pointer; touch-action: manipulation; box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35); }
        .auc-splash-logout-btn:active { transform: scale(0.98); }

        .auc-modal { position: fixed; inset: 0; background: rgba(2, 6, 23, 0.86); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 12px; }
        .auc-modal.open { display: flex; }
        .auc-pin-card { width: min(380px, 100%); background: #0f172a; color: #f8fafc; border: 1px solid rgba(148, 163, 184, 0.35); border-radius: 18px; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
        body[data-theme="light"] .auc-pin-card { background: #ffffff; color: #0f172a; }
        .auc-pin-display { text-align: center; font-size: 1.6rem; letter-spacing: 0.35em; padding: 10px; border-radius: 10px; background: rgba(15, 23, 42, 0.55); border: 1px solid rgba(148, 163, 184, 0.3); min-height: 2.2rem; }
        body[data-theme="light"] .auc-pin-display { background: #f8fafc; }
        .auc-keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .auc-key { height: 54px; font-size: 1.25rem; font-weight: 700; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.3); background: rgba(30, 41, 59, 0.85); color: inherit; cursor: pointer; touch-action: manipulation; }
        body[data-theme="light"] .auc-key { background: #e2e8f0; }
        .auc-key:active { background: #2563eb; color: #fff; }

        .auc-dsp-panel { display: flex; flex-direction: column; gap: 10px; flex: 1; }
        .auc-card { padding: 10px 12px; border-radius: 14px; background: rgba(15, 23, 42, 0.72); border: 1px solid rgba(148, 163, 184, 0.25); }
        body[data-theme="light"] .auc-card { background: rgba(255, 255, 255, 0.92); }
        .auc-slider-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 8px; }
        .auc-slider-head { display: flex; justify-content: space-between; font-size: 0.86rem; font-weight: 600; }
        .auc-slider-row input[type="range"] { width: 100%; height: 28px; }
        .auc-eq-graph-wrap { width: 100%; height: 150px; border-radius: 10px; overflow: hidden; background: #090e1a; border: 1px solid rgba(148, 163, 184, 0.3); margin-bottom: 8px; }
        #peqCanvas { width: 100%; height: 100%; display: block; touch-action: none; }
        .auc-band-pills { display: grid; grid-template-columns: repeat(8, 1fr); gap: 4px; margin-bottom: 8px; }
        .auc-band-pill { padding: 7px 0; border-radius: 8px; border: 1px solid rgba(148, 163, 184, 0.35); background: rgba(30, 41, 59, 0.7); color: inherit; font-size: 0.82rem; font-weight: 700; cursor: pointer; text-align: center; touch-action: manipulation; }
        body[data-theme="light"] .auc-band-pill { background: #e2e8f0; }
        .auc-band-pill.active { background: #2563eb; border-color: #60a5fa; color: #fff; }
        .auc-band-pill.disabled-band { opacity: 0.45; }
        .auc-band-meta { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
        .auc-eq-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        @media (max-width: 520px) { .auc-eq-grid { grid-template-columns: 1fr; } }
        .auc-action-bar { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: auto; }
        .auc-input, .auc-select { width: 100%; box-sizing: border-box; padding: 9px 10px; border-radius: 10px; border: 1px solid rgba(148, 163, 184, 0.4); background: rgba(15, 23, 42, 0.5); color: inherit; font-size: 0.92rem; }
        body[data-theme="light"] .auc-input, body[data-theme="light"] .auc-select { background: #f8fafc; }
    </style>
</head>
<body class="audio-panel" data-theme="$PanelTheme" data-initial-station="$InitialStationID">
<div class="panel-backdrop auc-shell">

    <header class="auc-topbar" id="aucTopbar">
        <div class="auc-brand">
            <% if $PanelSettings.PanelLogo.exists %>
                <img src="$PanelSettings.PanelLogo.ScaleHeight(32).URL" alt="Logo">
            <% end_if %>
            <div style="min-width:0;">
                <div class="auc-station-badge" id="aucStationTitle">Select Lane &amp; Panel</div>
                <div class="auc-station-sub" id="aucStationSub"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle · <% end_if %>DTX Microphone Channel</div>
            </div>
        </div>
        <div id="aucTopbarActions" style="display:flex; gap:6px; align-items:center; flex-shrink:0;">
            <button type="button" class="auc-btn" onclick="openStationModal()">Lane / Mic</button>
            <button type="button" class="auc-btn" onclick="openAddAuctioneerModal()">+ User</button>
        </div>
    </header>

    <!-- VIEW 1: Paginated Auctioneer Login Directory -->
    <section id="aucLoginView" style="display:flex; flex-direction:column; gap:10px; flex:1;">
        <div style="display:flex; gap:8px;">
            <input type="search" id="aucSearchInput" class="auc-input" placeholder="Filter auctioneer name..." oninput="aucFilterNames()">
        </div>

        <div class="auc-grid" id="aucNameGrid"></div>

        <div class="auc-pager">
            <button type="button" class="auc-btn" id="aucPrevBtn" onclick="aucChangePage(-1)">&larr; Prev</button>
            <span class="auc-page-label" id="aucPageLabel">Page 1 of 1</span>
            <button type="button" class="auc-btn" id="aucNextBtn" onclick="aucChangePage(1)">Next &rarr;</button>
        </div>
    </section>

    <!-- ACTIVE AUCTIONEER STATUS & MIC LIVE / MUTE BAR -->
    <div class="auc-card" id="aucActiveHeaderCard" style="display:none; justify-content:space-between; align-items:center; gap:8px;">
        <div>
            <div style="font-size:0.75rem; opacity:0.75;" id="aucActiveRoleLabel">ACTIVE AUCTIONEER</div>
            <div style="font-size:1.15rem; font-weight:800;" id="aucActiveName">-</div>
        </div>
        <button type="button" class="auc-btn success" id="aucMuteBtn" onclick="toggleAucMute()">MIC LIVE</button>
    </div>

    <!-- VIEW 2A: Splash-Style Active View for Standard Auctioneers (CanEditSettings = 0) -->
    <section id="aucSplashActiveView" class="auc-splash-screen">
        <% if $PanelSettings.PanelLogo.exists %>
            <img src="$PanelSettings.PanelLogo.ScaleWidth(360).URL" alt="Facility Logo" class="auc-splash-logo">
        <% end_if %>
        <h1 class="auc-splash-title"><% if $PanelSettings.PanelTitle %>$PanelSettings.PanelTitle<% else %>Auctioneer Mic Active<% end_if %></h1>
        <p class="auc-splash-lane" id="aucSplashLaneInfo">Lane 01 · Mic Input 1</p>
        <button type="button" class="auc-splash-logout-btn" onclick="logoutAuctioneer()">Log Out</button>
    </section>

    <!-- VIEW 2B: Full DSP Control & DASH-Style 8-Band PEQ Editor (CanEditSettings = 1) -->
    <section id="aucActiveView" class="auc-dsp-panel" style="display:none;">
        <div class="auc-card">
            <div class="auc-slider-row">
                <div class="auc-slider-head"><span>Mic Preamp Sensitivity</span><span id="lblSens">+30 dB</span></div>
                <input type="range" id="rngSens" min="0" max="16" step="1" value="10" oninput="onDspSliderInput()">
            </div>
            <div class="auc-slider-row" style="margin-bottom:0;">
                <div class="auc-slider-head"><span>Input Channel Gain</span><span id="lblGain">0.0 dB</span></div>
                <input type="range" id="rngGain" min="-72" max="12" step="0.5" value="0" oninput="onDspSliderInput()">
            </div>
        </div>

        <div class="auc-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span style="font-size:0.82rem; font-weight:700; opacity:0.9;">8-BAND PARAMETRIC EQ</span>
                <label style="font-size:0.85rem; font-weight:600; display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" id="chkPeq" checked onchange="onDspSliderInput()"> PEQ Master On
                </label>
            </div>

            <!-- Interactive DASH-style Frequency Response Graph -->
            <div class="auc-eq-graph-wrap">
                <canvas id="peqCanvas"></canvas>
            </div>

            <!-- Band 1 - 8 Selector Pills -->
            <div class="auc-band-pills" id="aucBandPills"></div>

            <!-- Selected Band Enable & Filter Type -->
            <div class="auc-band-meta">
                <label style="font-size:0.85rem; font-weight:600; display:flex; align-items:center; gap:6px; white-space:nowrap;">
                    <input type="checkbox" id="chkBandEnable" checked onchange="onBandParamInput()"> Band <span id="lblBandNum">1</span> On
                </label>
                <select id="selBandType" class="auc-select" onchange="onBandParamInput()">
                    <option value="6">Parametric (Peaking)</option>
                    <option value="2">Low Shelf</option>
                    <option value="3">High Shelf</option>
                    <option value="4">Low Pass (LPF)</option>
                    <option value="5">High Pass (HPF)</option>
                </select>
            </div>

            <!-- Selected Band Frequency, Gain, and Q Sliders -->
            <div class="auc-eq-grid">
                <div class="auc-slider-row" style="margin-bottom:0;">
                    <div class="auc-slider-head"><span>Freq</span><span id="lblBandFreq">125 Hz</span></div>
                    <input type="range" id="rngBandFreq" min="0" max="1000" step="1" value="265" oninput="onBandParamInput()">
                </div>
                <div class="auc-slider-row" style="margin-bottom:0;">
                    <div class="auc-slider-head"><span>Gain</span><span id="lblBandGain">0.0 dB</span></div>
                    <input type="range" id="rngBandGain" min="-24" max="18" step="0.5" value="0" oninput="onBandParamInput()">
                </div>
                <div class="auc-slider-row" style="margin-bottom:0;">
                    <div class="auc-slider-head"><span>Q</span><span id="lblBandQ">0.71</span></div>
                    <input type="range" id="rngBandQ" min="0" max="1000" step="1" value="456" oninput="onBandParamInput()">
                </div>
            </div>
        </div>

        <div class="auc-card">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                <span style="font-size:0.8rem; font-weight:700; opacity:0.85;">COMPRESSOR</span>
                <label style="font-size:0.85rem; font-weight:600; display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" id="chkComp" checked onchange="onDspSliderInput()"> Enabled
                </label>
            </div>
            <div class="auc-slider-row">
                <div class="auc-slider-head"><span>Threshold</span><span id="lblCompThresh">-24 dB</span></div>
                <input type="range" id="rngCompThresh" min="-48" max="0" step="1" value="-24" oninput="onDspSliderInput()">
            </div>
            <div class="auc-slider-row" style="margin-bottom:0;">
                <div class="auc-slider-head"><span>Ratio</span><span id="lblCompRatio">3.0:1</span></div>
                <input type="range" id="rngCompRatio" min="1" max="20" step="0.5" value="3" oninput="onDspSliderInput()">
            </div>
        </div>

        <div class="auc-action-bar" id="aucActionBar">
            <button type="button" class="auc-btn success" id="aucSaveBtn" onclick="openSaveTargetModal()">Save Settings...</button>
            <button type="button" class="auc-btn danger" onclick="logoutAuctioneer()">Log Out &amp; Reset</button>
        </div>
    </section>

</div>

<div class="auc-modal" id="aucPinModal">
    <div class="auc-pin-card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong id="aucPinTargetName">Enter PIN</strong>
            <button type="button" class="auc-btn" style="padding:6px 10px;" onclick="closePinModal()">Cancel</button>
        </div>
        <div class="auc-pin-display" id="aucPinDots"></div>
        <div class="auc-keypad">
            <button type="button" class="auc-key" onclick="appendPin('1')">1</button>
            <button type="button" class="auc-key" onclick="appendPin('2')">2</button>
            <button type="button" class="auc-key" onclick="appendPin('3')">3</button>
            <button type="button" class="auc-key" onclick="appendPin('4')">4</button>
            <button type="button" class="auc-key" onclick="appendPin('5')">5</button>
            <button type="button" class="auc-key" onclick="appendPin('6')">6</button>
            <button type="button" class="auc-key" onclick="appendPin('7')">7</button>
            <button type="button" class="auc-key" onclick="appendPin('8')">8</button>
            <button type="button" class="auc-key" onclick="appendPin('9')">9</button>
            <button type="button" class="auc-key" onclick="clearPin()">Clear</button>
            <button type="button" class="auc-key" onclick="appendPin('0')">0</button>
            <button type="button" class="auc-key" style="background:#16a34a; color:#fff;" onclick="submitPinLogin()">OK</button>
        </div>
    </div>
</div>

<div class="auc-modal" id="aucSaveTargetModal">
    <div class="auc-pin-card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong>Save Settings to User &amp; Lane</strong>
            <button type="button" class="auc-btn" style="padding:6px 10px;" onclick="closeSaveTargetModal()">Cancel</button>
        </div>
        <label style="font-size:0.82rem; font-weight:600; opacity:0.85;">Save Into Auctioneer Profile</label>
        <select id="selSaveProfile" class="auc-select"></select>

        <label style="font-size:0.82rem; font-weight:600; opacity:0.85;">Target Lane &amp; Mic Channel</label>
        <select id="selSaveTarget" class="auc-select"></select>

        <label style="font-size:0.85rem; display:flex; align-items:center; gap:8px;">
            <input type="checkbox" id="chkSaveEntireLane"> Apply to all mics on target lane
        </label>

        <button type="button" class="auc-btn success" onclick="confirmSaveSettings()">Save &amp; Push to Lane</button>
    </div>
</div>

<div class="auc-modal" id="aucStationModal">
    <div class="auc-pin-card" style="width:min(460px, 100%); max-height:85vh; overflow:auto;">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong>Assign This 4&quot; Touch Panel</strong>
            <button type="button" class="auc-btn" style="padding:6px 10px;" onclick="closeStationModal()">Close</button>
        </div>
        <div id="aucStationList" style="display:flex; flex-direction:column; gap:8px;"></div>
    </div>
</div>

<div class="auc-modal" id="aucNewUserModal">
    <div class="auc-pin-card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong>Add Auctioneer Profile</strong>
            <button type="button" class="auc-btn" style="padding:6px 10px;" onclick="closeAddAuctioneerModal()">Close</button>
        </div>
        <input type="text" id="newAucName" class="auc-input" placeholder="Auctioneer Full Name">
        <input type="tel" id="newAucPin" class="auc-input" maxlength="8" inputmode="numeric" placeholder="PIN (4-8 digits)">
        <label style="font-size:0.88rem; display:flex; align-items:center; gap:8px;">
            <input type="checkbox" id="newAucCanEdit"> Can Edit &amp; Save Settings Across Lanes
        </label>
        <button type="button" class="auc-btn primary" onclick="createAuctioneerProfile()">Create Auctioneer</button>
    </div>
</div>

<div id="toast"></div>
<% include App\Auctioneer\Includes\PanelAuctioneerJS %>
</body>
</html>
