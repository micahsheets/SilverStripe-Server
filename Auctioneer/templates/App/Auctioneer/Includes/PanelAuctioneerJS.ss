<script>
const BAND_COLORS = ['#38bdf8', '#34d399', '#fbbf24', '#f87171', '#a78bfa', '#f472b6', '#2dd4bf', '#fb923c'];

const aucState = {
    csrfToken: '',
    lanes: [],
    auctioneers: [],
    filteredAuctioneers: [],
    page: 0,
    pageSize: 6,
    activeStation: null,
    pendingAuctioneer: null,
    pinBuffer: '',
    liveSettings: null,
    selectedBand: 0,
    draggingNode: false,
    applyTimer: null
};

function showAucToast(msg, isError = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = isError ? 'show error' : 'show';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.className = ''; }, isError ? 6000 : 2800);
}

async function aucPost(url, payload) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': aucState.csrfToken },
        body: JSON.stringify(payload)
    });
    const text = await res.text();
    try { return JSON.parse(text); } catch (e) { throw new Error('HTTP ' + res.status + ': ' + text.slice(0, 120)); }
}

// Logarithmic helpers for Frequency (20Hz - 20kHz) and Q (0.02 - 50)
function freqToSlider(hz) {
    const clamped = Math.max(20, Math.min(20000, Number(hz) || 1000));
    return Math.round((Math.log10(clamped / 20) / Math.log10(1000)) * 1000);
}
function sliderToFreq(pos) {
    const frac = Math.max(0, Math.min(1, Number(pos) / 1000));
    const hz = 20 * Math.pow(1000, frac);
    return hz >= 1000 ? Math.round(hz / 10) * 10 : Math.round(hz);
}
function qToSlider(q) {
    const clamped = Math.max(0.02, Math.min(50, Number(q) || 0.71));
    return Math.round((Math.log10(clamped / 0.02) / Math.log10(2500)) * 1000);
}
function sliderToQ(pos) {
    const frac = Math.max(0, Math.min(1, Number(pos) / 1000));
    return Number((0.02 * Math.pow(2500, frac)).toFixed(2));
}
function formatHz(hz) {
    return hz >= 1000 ? (hz / 1000).toFixed(hz % 1000 === 0 ? 0 : 2) + ' kHz' : hz + ' Hz';
}

async function initAuctioneerPanel() {
    const bodyStation = Number(document.body.dataset.initialStation || 0);
    const savedStation = Number(localStorage.getItem('av_auc_station_id') || 0);
    const stationId = bodyStation > 0 ? bodyStation : savedStation;

    const res = await fetch('/api/auctioneer/bootstrap' + (stationId > 0 ? ('?station=' + stationId) : ''));
    const data = await res.json();
    if (!data.success) {
        showAucToast(data.error || 'Failed to load Auctioneer kiosk', true);
        return;
    }

    aucState.csrfToken = data.csrfToken || '';
    aucState.lanes = data.lanes || [];
    aucState.auctioneers = data.auctioneers || [];
    aucState.filteredAuctioneers = [...aucState.auctioneers];

    bindPeqCanvasEvents();

    if (data.activeStation) {
        setStation(data.activeStation);
    } else if (aucState.lanes.length > 0 && aucState.lanes[0].stations.length > 0) {
        setStation(aucState.lanes[0].stations[0]);
        openStationModal();
    } else {
        openStationModal();
    }

    renderAuctioneerGrid();
}

function setStation(station) {
    aucState.activeStation = station;
    localStorage.setItem('av_auc_station_id', String(station.id));
    document.getElementById('aucStationTitle').textContent = station.laneTitle + ' · ' + station.title;
    document.getElementById('aucStationSub').textContent = station.dtxTitle + ' · Mic Input ' + station.dtxChannel;

    const topbarActions = document.getElementById('aucTopbarActions');
    const loginView = document.getElementById('aucLoginView');
    const activeHeaderCard = document.getElementById('aucActiveHeaderCard');
    const splashView = document.getElementById('aucSplashActiveView');
    const editView = document.getElementById('aucActiveView');

    if (station.auctioneer) {
        aucState.liveSettings = JSON.parse(JSON.stringify(station.auctioneer.settings));
        const canEdit = !!station.auctioneer.canEditSettings;

        topbarActions.style.display = canEdit ? 'flex' : 'none';
        const sysBtn = document.getElementById('aucSystemBtn');
        if (sysBtn) sysBtn.style.display = canEdit ? 'inline-block' : 'none';
        const mainPanelBtn = document.getElementById('aucMainPanelBtn');
        if (mainPanelBtn) mainPanelBtn.style.display = canEdit ? 'inline-flex' : 'none';
        activeHeaderCard.style.display = 'flex';
        document.getElementById('aucActiveName').textContent = station.auctioneer.title;
        document.getElementById('aucActiveRoleLabel').textContent = canEdit ? 'ACTIVE AUCTIONEER (EDIT MODE)' : 'ACTIVE AUCTIONEER';
        syncSlidersFromSettings(aucState.liveSettings);

        loginView.style.display = 'none';
        if (canEdit) {
            splashView.style.display = 'none';
            editView.style.display = 'flex';
            requestAnimationFrame(drawPeqGraph);
        } else {
            editView.style.display = 'none';
            document.getElementById('aucSplashLaneInfo').textContent = `${station.laneTitle} · ${station.title} (Mic ${station.dtxChannel})`;
            splashView.style.display = 'flex';
        }
    } else {
        aucState.liveSettings = null;
        topbarActions.style.display = 'flex';
        const sysBtnOut = document.getElementById('aucSystemBtn');
        if (sysBtnOut) sysBtnOut.style.display = 'none';
        const mainPanelBtnOut = document.getElementById('aucMainPanelBtn');
        if (mainPanelBtnOut) mainPanelBtnOut.style.display = 'none';
        activeHeaderCard.style.display = 'none';
        splashView.style.display = 'none';
        editView.style.display = 'none';
        loginView.style.display = 'flex';
    }
}

function aucFilterNames() {
    const q = document.getElementById('aucSearchInput').value.trim().toLowerCase();
    aucState.filteredAuctioneers = q
        ? aucState.auctioneers.filter(a => a.title.toLowerCase().includes(q))
        : [...aucState.auctioneers];
    aucState.page = 0;
    renderAuctioneerGrid();
}

function aucChangePage(delta) {
    const totalPages = Math.max(1, Math.ceil(aucState.filteredAuctioneers.length / aucState.pageSize));
    aucState.page = Math.max(0, Math.min(totalPages - 1, aucState.page + delta));
    renderAuctioneerGrid();
}

function renderAuctioneerGrid() {
    const grid = document.getElementById('aucNameGrid');
    grid.innerHTML = '';
    const total = aucState.filteredAuctioneers.length;
    const totalPages = Math.max(1, Math.ceil(total / aucState.pageSize));
    if (aucState.page >= totalPages) aucState.page = totalPages - 1;

    document.getElementById('aucPageLabel').textContent = `Page ${aucState.page + 1} of ${totalPages} (${total})`;
    document.getElementById('aucPrevBtn').disabled = aucState.page <= 0;
    document.getElementById('aucNextBtn').disabled = aucState.page >= totalPages - 1;

    if (total === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1; opacity:0.75; text-align:center; padding:18px;">No auctioneers found. Tap <strong>+ User</strong> to add one.</div>';
        return;
    }

    const slice = aucState.filteredAuctioneers.slice(
        aucState.page * aucState.pageSize,
        (aucState.page + 1) * aucState.pageSize
    );

    slice.forEach(auc => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'auc-name-btn';
        btn.textContent = auc.title;
        btn.onclick = () => openPinModal(auc);
        grid.appendChild(btn);
    });
}

function openPinModal(auc) {
    if (!aucState.activeStation) {
        showAucToast('Select a Lane & Touch Panel first', true);
        openStationModal();
        return;
    }
    aucState.pendingAuctioneer = auc;
    aucState.pinBuffer = '';
    document.getElementById('aucPinTargetName').textContent = auc.title + ' — Enter PIN';
    document.getElementById('aucPinDots').textContent = '';
    document.getElementById('aucPinModal').classList.add('open');
}

function closePinModal() {
    document.getElementById('aucPinModal').classList.remove('open');
    aucState.pinBuffer = '';
}

function appendPin(digit) {
    if (aucState.pinBuffer.length >= 8) return;
    aucState.pinBuffer += digit;
    document.getElementById('aucPinDots').textContent = '•'.repeat(aucState.pinBuffer.length);
}

function clearPin() {
    aucState.pinBuffer = '';
    document.getElementById('aucPinDots').textContent = '';
}

async function submitPinLogin() {
    if (!aucState.pendingAuctioneer || !aucState.activeStation) return;
    try {
        const data = await aucPost('/api/auctioneer/login', {
            station_id: aucState.activeStation.id,
            auctioneer_id: aucState.pendingAuctioneer.id,
            pin: aucState.pinBuffer
        });
        if (!data.success) {
            clearPin();
            showAucToast(data.error || 'Invalid PIN', true);
            return;
        }
        closePinModal();
        setStation(data.station);
        showAucToast('Loaded ' + data.station.auctioneer.title + ' onto ' + data.station.title);
    } catch (e) {
        showAucToast(e.message, true);
    }
}

function renderBandPills() {
    const wrap = document.getElementById('aucBandPills');
    if (!wrap || !aucState.liveSettings) return;
    wrap.innerHTML = '';
    const bands = aucState.liveSettings.peq.band || [];
    for (let i = 0; i < 8; i++) {
        const b = bands[i] || { enabled: 1 };
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'auc-band-pill'
            + (aucState.selectedBand === i ? ' active' : '')
            + (!b.enabled ? ' disabled-band' : '');
        btn.style.borderBottom = '3px solid ' + BAND_COLORS[i];
        btn.textContent = String(i + 1);
        btn.onclick = () => selectEqBand(i);
        wrap.appendChild(btn);
    }
}

function selectEqBand(idx) {
    aucState.selectedBand = Math.max(0, Math.min(7, idx));
    syncSelectedBandControls();
    renderBandPills();
    drawPeqGraph();
}

function syncSelectedBandControls() {
    if (!aucState.liveSettings) return;
    const idx = aucState.selectedBand;
    const band = aucState.liveSettings.peq.band[idx] || { enabled: 1, type: 6, freq: 1000, gain: 0, q: 0.71 };
    document.getElementById('lblBandNum').textContent = String(idx + 1);
    document.getElementById('chkBandEnable').checked = !!band.enabled;
    const typeVal = [2, 3, 4, 5, 6].includes(Number(band.type)) ? String(band.type) : '6';
    document.getElementById('selBandType').value = typeVal;
    document.getElementById('rngBandFreq').value = freqToSlider(band.freq);
    document.getElementById('rngBandGain').value = band.gain ?? 0;
    document.getElementById('rngBandQ').value = qToSlider(band.q);
    updateBandLabels(band);
}

function updateBandLabels(band) {
    const fmt = v => (v >= 0 ? '+' : '') + Number(v).toFixed(1) + ' dB';
    document.getElementById('lblBandFreq').textContent = formatHz(band.freq);
    document.getElementById('lblBandGain').textContent = fmt(band.gain ?? 0);
    document.getElementById('lblBandQ').textContent = Number(band.q ?? 0.71).toFixed(2);
}

function onBandParamInput() {
    if (!aucState.liveSettings) return;
    const idx = aucState.selectedBand;
    const band = aucState.liveSettings.peq.band[idx];
    if (!band) return;

    band.enabled = document.getElementById('chkBandEnable').checked ? 1 : 0;
    band.type = parseInt(document.getElementById('selBandType').value, 10) || 6;
    band.freq = sliderToFreq(document.getElementById('rngBandFreq').value);
    band.gain = parseFloat(document.getElementById('rngBandGain').value);
    band.q = sliderToQ(document.getElementById('rngBandQ').value);

    updateBandLabels(band);
    renderBandPills();
    drawPeqGraph();

    clearTimeout(aucState.applyTimer);
    aucState.applyTimer = setTimeout(pushLiveDspSettings, 140);
}

function syncSlidersFromSettings(s) {
    document.getElementById('rngSens').value = s.sensitivity ?? 10;
    document.getElementById('rngGain').value = s.gain ?? 0;
    document.getElementById('chkPeq').checked = !!(s.peq && s.peq.enabled);
    document.getElementById('chkComp').checked = !!(s.comp && s.comp.enabled);
    document.getElementById('rngCompThresh').value = s.comp ? s.comp.threshold : -24;
    document.getElementById('rngCompRatio').value = s.comp ? s.comp.ratio : 3;
    updateDspLabels(s);
    syncSelectedBandControls();
    renderBandPills();
    drawPeqGraph();
}

function updateDspLabels(s) {
    const fmt = v => (v >= 0 ? '+' : '') + Number(v).toFixed(1);
    document.getElementById('lblSens').textContent = '+' + ((s.sensitivity ?? 0) * 3) + ' dB';
    document.getElementById('lblGain').textContent = fmt(s.gain ?? 0) + ' dB';
    document.getElementById('lblCompThresh').textContent = Number(s.comp ? s.comp.threshold : -24).toFixed(0) + ' dB';
    document.getElementById('lblCompRatio').textContent = Number(s.comp ? s.comp.ratio : 3).toFixed(1) + ':1';

    const muteBtn = document.getElementById('aucMuteBtn');
    if (s.muted) {
        muteBtn.textContent = 'MUTED';
        muteBtn.className = 'auc-btn danger';
    } else {
        muteBtn.textContent = 'MIC LIVE';
        muteBtn.className = 'auc-btn success';
    }
}

function onDspSliderInput() {
    if (!aucState.liveSettings) return;
    aucState.liveSettings.sensitivity = parseInt(document.getElementById('rngSens').value, 10);
    aucState.liveSettings.gain = parseFloat(document.getElementById('rngGain').value);
    aucState.liveSettings.peq.enabled = document.getElementById('chkPeq').checked ? 1 : 0;
    aucState.liveSettings.comp.enabled = document.getElementById('chkComp').checked ? 1 : 0;
    aucState.liveSettings.comp.threshold = parseFloat(document.getElementById('rngCompThresh').value);
    aucState.liveSettings.comp.ratio = parseFloat(document.getElementById('rngCompRatio').value);
    updateDspLabels(aucState.liveSettings);
    drawPeqGraph();

    clearTimeout(aucState.applyTimer);
    aucState.applyTimer = setTimeout(pushLiveDspSettings, 140);
}

// Biquad magnitude response in dB at frequency f (48kHz sample rate)
function bandMagDb(band, f) {
    if (!band || !band.enabled) return 0;
    const fs = 48000;
    const f0 = Math.max(20, Math.min(20000, Number(band.freq) || 1000));
    const gainDb = Number(band.gain) || 0;
    const Q = Math.max(0.02, Math.min(50, Number(band.q) || 0.71));
    const type = Number(band.type) || 6;

    const w0 = (2 * Math.PI * f0) / fs;
    const cosw0 = Math.cos(w0);
    const sinw0 = Math.sin(w0);
    const alpha = sinw0 / (2 * Q);
    const A = Math.pow(10, gainDb / 40);

    let b0 = 1, b1 = 0, b2 = 0, a0 = 1, a1 = 0, a2 = 0;

    if (type === 2) { // Low Shelf
        const sq = 2 * Math.sqrt(A) * alpha;
        b0 = A * ((A + 1) - (A - 1) * cosw0 + sq);
        b1 = 2 * A * ((A - 1) - (A + 1) * cosw0);
        b2 = A * ((A + 1) - (A - 1) * cosw0 - sq);
        a0 = (A + 1) + (A - 1) * cosw0 + sq;
        a1 = -2 * ((A - 1) + (A + 1) * cosw0);
        a2 = (A + 1) + (A - 1) * cosw0 - sq;
    } else if (type === 3) { // High Shelf
        const sq = 2 * Math.sqrt(A) * alpha;
        b0 = A * ((A + 1) + (A - 1) * cosw0 + sq);
        b1 = -2 * A * ((A - 1) + (A + 1) * cosw0);
        b2 = A * ((A + 1) + (A - 1) * cosw0 - sq);
        a0 = (A + 1) - (A - 1) * cosw0 + sq;
        a1 = 2 * ((A - 1) - (A + 1) * cosw0);
        a2 = (A + 1) - (A - 1) * cosw0 - sq;
    } else if (type === 4) { // Low Pass
        b0 = (1 - cosw0) / 2;
        b1 = 1 - cosw0;
        b2 = (1 - cosw0) / 2;
        a0 = 1 + alpha;
        a1 = -2 * cosw0;
        a2 = 1 - alpha;
    } else if (type === 5) { // High Pass
        b0 = (1 + cosw0) / 2;
        b1 = -(1 + cosw0);
        b2 = (1 + cosw0) / 2;
        a0 = 1 + alpha;
        a1 = -2 * cosw0;
        a2 = 1 - alpha;
    } else { // Parametric (Peaking EQ)
        if (Math.abs(gainDb) < 0.01) return 0;
        b0 = 1 + alpha * A;
        b1 = -2 * cosw0;
        b2 = 1 - alpha * A;
        a0 = 1 + alpha / A;
        a1 = -2 * cosw0;
        a2 = 1 - alpha / A;
    }

    const w = (2 * Math.PI * f) / fs;
    const cos1 = Math.cos(w), sin1 = Math.sin(w);
    const cos2 = Math.cos(2 * w), sin2 = Math.sin(2 * w);

    const numRe = (b0 + b1 * cos1 + b2 * cos2) / a0;
    const numIm = (-b1 * sin1 - b2 * sin2) / a0;
    const denRe = 1 + (a1 * cos1 + a2 * cos2) / a0;
    const denIm = (-a1 * sin1 - a2 * sin2) / a0;

    const mag2 = (numRe * numRe + numIm * numIm) / Math.max(1e-12, denRe * denRe + denIm * denIm);
    return 10 * Math.log10(Math.max(1e-8, mag2));
}

function drawPeqGraph() {
    const canvas = document.getElementById('peqCanvas');
    if (!canvas || !aucState.liveSettings) return;
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    const W = Math.max(260, Math.round(rect.width || 320));
    const H = Math.max(120, Math.round(rect.height || 150));
    if (canvas.width !== W * dpr || canvas.height !== H * dpr) {
        canvas.width = W * dpr;
        canvas.height = H * dpr;
    }

    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, W, H);

    const minDb = -24, maxDb = 18;
    const xForFreq = f => (Math.log10(Math.max(20, Math.min(20000, f)) / 20) / Math.log10(1000)) * W;
    const yForDb = db => ((maxDb - Math.max(minDb, Math.min(maxDb, db))) / (maxDb - minDb)) * H;

    // Grid lines: Frequency
    ctx.strokeStyle = 'rgba(148, 163, 184, 0.16)';
    ctx.lineWidth = 1;
    ctx.font = '10px sans-serif';
    ctx.fillStyle = 'rgba(148, 163, 184, 0.65)';
    [50, 100, 200, 500, 1000, 2000, 5000, 10000].forEach(f => {
        const x = Math.round(xForFreq(f)) + 0.5;
        ctx.beginPath();
        ctx.moveTo(x, 0);
        ctx.lineTo(x, H);
        ctx.stroke();
        const label = f >= 1000 ? (f / 1000) + 'k' : String(f);
        ctx.fillText(label, x + 2, H - 4);
    });

    // Grid lines: dB
    [12, 6, 0, -6, -12, -18].forEach(db => {
        const y = Math.round(yForDb(db)) + 0.5;
        ctx.strokeStyle = db === 0 ? 'rgba(148, 163, 184, 0.42)' : 'rgba(148, 163, 184, 0.14)';
        ctx.beginPath();
        ctx.moveTo(0, y);
        ctx.lineTo(W, y);
        ctx.stroke();
        if (db !== 0) ctx.fillText((db > 0 ? '+' : '') + db, 4, y - 2);
    });

    const peq = aucState.liveSettings.peq || { enabled: 1, band: [] };
    const bands = peq.band || [];
    const masterOn = !!peq.enabled;

    // Combined Frequency Response Curve
    ctx.beginPath();
    for (let px = 0; px <= W; px += 2) {
        const f = 20 * Math.pow(1000, px / W);
        let totalDb = 0;
        if (masterOn) {
            for (let i = 0; i < bands.length; i++) {
                totalDb += bandMagDb(bands[i], f);
            }
        }
        const y = yForDb(totalDb);
        if (px === 0) ctx.moveTo(px, y);
        else ctx.lineTo(px, y);
    }
    ctx.strokeStyle = masterOn ? '#38bdf8' : '#64748b';
    ctx.lineWidth = 2.4;
    ctx.stroke();

    // Fill under curve relative to 0 dB
    ctx.lineTo(W, yForDb(0));
    ctx.lineTo(0, yForDb(0));
    ctx.closePath();
    ctx.fillStyle = masterOn ? 'rgba(56, 189, 248, 0.15)' : 'rgba(100, 116, 139, 0.08)';
    ctx.fill();

    // Band Control Nodes (1 - 8)
    bands.forEach((b, i) => {
        const x = xForFreq(b.freq);
        const y = yForDb(b.gain);
        const isSel = i === aucState.selectedBand;
        ctx.beginPath();
        ctx.arc(x, y, isSel ? 9 : 7, 0, Math.PI * 2);
        ctx.fillStyle = b.enabled ? BAND_COLORS[i] : '#475569';
        ctx.fill();
        ctx.lineWidth = isSel ? 2.5 : 1.5;
        ctx.strokeStyle = isSel ? '#ffffff' : '#0f172a';
        ctx.stroke();

        ctx.fillStyle = '#090e1a';
        ctx.font = 'bold 9px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(String(i + 1), x, y);
    });
    ctx.textAlign = 'left';
    ctx.textBaseline = 'alphabetic';
}

function bindPeqCanvasEvents() {
    const canvas = document.getElementById('peqCanvas');
    if (!canvas || canvas.dataset.bound === '1') return;
    canvas.dataset.bound = '1';

    const getPos = e => {
        const r = canvas.getBoundingClientRect();
        const clientX = e.touches ? e.touches[0].clientX : e.clientX;
        const clientY = e.touches ? e.touches[0].clientY : e.clientY;
        return { x: clientX - r.left, y: clientY - r.top, w: r.width, h: r.height };
    };

    const startDrag = e => {
        if (!aucState.liveSettings) return;
        const p = getPos(e);
        const bands = aucState.liveSettings.peq.band || [];
        let bestIdx = -1, bestDist = 28;
        bands.forEach((b, i) => {
            const bx = (Math.log10(Math.max(20, Math.min(20000, b.freq)) / 20) / Math.log10(1000)) * p.w;
            const by = ((18 - Math.max(-24, Math.min(18, b.gain))) / 42) * p.h;
            const d = Math.hypot(p.x - bx, p.y - by);
            if (d < bestDist) { bestDist = d; bestIdx = i; }
        });
        if (bestIdx >= 0) {
            aucState.draggingNode = true;
            selectEqBand(bestIdx);
            e.preventDefault();
        }
    };

    const moveDrag = e => {
        if (!aucState.draggingNode || !aucState.liveSettings) return;
        const p = getPos(e);
        const band = aucState.liveSettings.peq.band[aucState.selectedBand];
        if (!band) return;

        const fracX = Math.max(0, Math.min(1, p.x / Math.max(1, p.w)));
        const rawHz = 20 * Math.pow(1000, fracX);
        band.freq = rawHz >= 1000 ? Math.round(rawHz / 10) * 10 : Math.round(rawHz);

        const fracY = Math.max(0, Math.min(1, p.y / Math.max(1, p.h)));
        const rawGain = 18 - fracY * 42;
        band.gain = Math.round(rawGain * 2) / 2;

        syncSelectedBandControls();
        drawPeqGraph();
        clearTimeout(aucState.applyTimer);
        aucState.applyTimer = setTimeout(pushLiveDspSettings, 140);
        e.preventDefault();
    };

    const endDrag = () => { aucState.draggingNode = false; };

    canvas.addEventListener('mousedown', startDrag);
    window.addEventListener('mousemove', moveDrag);
    window.addEventListener('mouseup', endDrag);
    canvas.addEventListener('touchstart', startDrag, { passive: false });
    window.addEventListener('touchmove', moveDrag, { passive: false });
    window.addEventListener('touchend', endDrag);
    window.addEventListener('resize', drawPeqGraph);
}

function toggleAucMute() {
    if (!aucState.liveSettings) return;
    aucState.liveSettings.muted = !aucState.liveSettings.muted;
    updateDspLabels(aucState.liveSettings);
    pushLiveDspSettings();
}

async function pushLiveDspSettings() {
    if (!aucState.activeStation || !aucState.liveSettings) return;
    try {
        const data = await aucPost('/api/auctioneer/apply', {
            station_id: aucState.activeStation.id,
            settings: aucState.liveSettings
        });
        if (!data.success) showAucToast(data.error || 'DSP update error', true);
    } catch (e) {
        showAucToast(e.message, true);
    }
}

function openSaveTargetModal() {
    if (!aucState.activeStation || !aucState.activeStation.auctioneer || !aucState.activeStation.auctioneer.canEditSettings) {
        showAucToast('Your profile does not have permission to save settings.', true);
        return;
    }

    const profSel = document.getElementById('selSaveProfile');
    profSel.innerHTML = '';
    aucState.auctioneers.forEach(a => {
        const opt = document.createElement('option');
        opt.value = String(a.id);
        opt.textContent = a.title + (a.id === aucState.activeStation.auctioneer.id ? ' (Current User)' : '');
        if (a.id === aucState.activeStation.auctioneer.id) opt.selected = true;
        profSel.appendChild(opt);
    });

    const targetSel = document.getElementById('selSaveTarget');
    targetSel.innerHTML = '';
    aucState.lanes.forEach(lane => {
        const grp = document.createElement('optgroup');
        grp.label = `${lane.title} (${lane.dtxTitle})`;
        lane.stations.forEach(st => {
            const opt = document.createElement('option');
            opt.value = JSON.stringify({ laneId: lane.id, stationId: st.id });
            opt.textContent = `${lane.title} · ${st.title} (Ch ${st.dtxChannel})` + (st.id === aucState.activeStation.id ? ' [This Panel]' : '');
            if (st.id === aucState.activeStation.id) opt.selected = true;
            grp.appendChild(opt);
        });
        targetSel.appendChild(grp);
    });

    document.getElementById('chkSaveEntireLane').checked = false;
    document.getElementById('aucSaveTargetModal').classList.add('open');
}

function closeSaveTargetModal() {
    document.getElementById('aucSaveTargetModal').classList.remove('open');
}

async function confirmSaveSettings() {
    if (!aucState.activeStation || !aucState.liveSettings) return;
    const targetUserId = Number(document.getElementById('selSaveProfile').value || 0);
    let parsedTarget = { laneId: aucState.activeStation.laneId, stationId: aucState.activeStation.id };
    try {
        parsedTarget = JSON.parse(document.getElementById('selSaveTarget').value);
    } catch (e) {}

    try {
        const data = await aucPost('/api/auctioneer/saveprofile', {
            station_id: aucState.activeStation.id,
            target_auctioneer_id: targetUserId,
            target_lane_id: parsedTarget.laneId,
            target_station_id: parsedTarget.stationId,
            apply_to_entire_lane: document.getElementById('chkSaveEntireLane').checked,
            settings: aucState.liveSettings
        });
        if (data.success) {
            closeSaveTargetModal();
            setStation(data.station);
            showAucToast(`Saved to ${data.savedToProfile} & pushed to ${data.appliedToStation}`);
        } else {
            showAucToast(data.error || 'Save failed', true);
        }
    } catch (e) {
        showAucToast(e.message, true);
    }
}

async function logoutAuctioneer() {
    if (!aucState.activeStation) return;
    try {
        const data = await aucPost('/api/auctioneer/logout', {
            station_id: aucState.activeStation.id
        });
        if (data.success) {
            setStation(data.station);
            showAucToast('Logged out — Mic channel reset to lane defaults');
        } else {
            showAucToast(data.error || 'Logout error', true);
        }
    } catch (e) {
        showAucToast(e.message, true);
    }
}

function openStationModal() {
    const list = document.getElementById('aucStationList');
    list.innerHTML = '';
    if (aucState.lanes.length === 0) {
        list.innerHTML = '<div style="opacity:0.8;">No Auction Lanes configured yet. Open <strong>/admin/auctioneers</strong> and click <em>Quick-Create 16 Lanes</em>.</div>';
    } else {
        aucState.lanes.forEach(lane => {
            const box = document.createElement('div');
            box.style.padding = '8px';
            box.style.border = '1px solid rgba(148,163,184,0.25)';
            box.style.borderRadius = '10px';
            const title = document.createElement('div');
            title.style.fontWeight = '700';
            title.style.marginBottom = '6px';
            title.textContent = `${lane.title} (${lane.dtxTitle})`;
            box.appendChild(title);

            const row = document.createElement('div');
            row.style.display = 'flex';
            row.style.flexWrap = 'wrap';
            row.style.gap = '6px';
            lane.stations.forEach(st => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'auc-btn' + (aucState.activeStation && aucState.activeStation.id === st.id ? ' primary' : '');
                b.textContent = `${st.title} (Ch ${st.dtxChannel})`;
                b.onclick = () => { setStation(st); closeStationModal(); };
                row.appendChild(b);
            });
            box.appendChild(row);
            list.appendChild(box);
        });
    }
    document.getElementById('aucStationModal').classList.add('open');
}

function closeStationModal() { document.getElementById('aucStationModal').classList.remove('open'); }
function openAddAuctioneerModal() { document.getElementById('aucNewUserModal').classList.add('open'); }
function closeAddAuctioneerModal() { document.getElementById('aucNewUserModal').classList.remove('open'); }

async function createAuctioneerProfile() {
    const name = document.getElementById('newAucName').value.trim();
    const pin = document.getElementById('newAucPin').value.trim();
    const canEdit = document.getElementById('newAucCanEdit').checked;
    try {
        const data = await aucPost('/api/auctioneer/createprofile', { name, pin, can_edit_settings: canEdit });
        if (!data.success) {
            showAucToast(data.error || 'Could not create auctioneer', true);
            return;
        }
        document.getElementById('newAucName').value = '';
        document.getElementById('newAucPin').value = '';
        document.getElementById('newAucCanEdit').checked = false;
        closeAddAuctioneerModal();
        aucState.auctioneers.push(data.auctioneer);
        aucState.auctioneers.sort((a, b) => a.title.localeCompare(b.title));
        aucFilterNames();
        showAucToast('Added Auctioneer: ' + data.auctioneer.title);
    } catch (e) {
        showAucToast(e.message, true);
    }
}

initAuctioneerPanel();
</script>
