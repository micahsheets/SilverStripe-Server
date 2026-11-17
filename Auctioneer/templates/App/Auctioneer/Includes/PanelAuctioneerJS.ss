<script>
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

    const topbar = document.getElementById('aucTopbar');
    const loginView = document.getElementById('aucLoginView');
    const splashView = document.getElementById('aucSplashActiveView');
    const editView = document.getElementById('aucActiveView');

    if (station.auctioneer) {
        aucState.liveSettings = JSON.parse(JSON.stringify(station.auctioneer.settings));
        const canEdit = !!station.auctioneer.canEditSettings;

        if (canEdit) {
            topbar.style.display = 'flex';
            loginView.style.display = 'none';
            splashView.style.display = 'none';
            document.getElementById('aucActiveName').textContent = station.auctioneer.title;
            syncSlidersFromSettings(aucState.liveSettings);
            editView.style.display = 'flex';
        } else {
            // Full-screen splash view with only Logo, Title, Active User, and Log Out button
            topbar.style.display = 'none';
            loginView.style.display = 'none';
            editView.style.display = 'none';
            document.getElementById('aucSplashUserName').textContent = station.auctioneer.title;
            document.getElementById('aucSplashLaneInfo').textContent = `${station.laneTitle} · ${station.title} (Mic ${station.dtxChannel})`;
            splashView.style.display = 'flex';
        }
    } else {
        aucState.liveSettings = null;
        topbar.style.display = 'flex';
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

function syncSlidersFromSettings(s) {
    const bands = (s.peq && Array.isArray(s.peq.band)) ? s.peq.band : [];
    document.getElementById('rngSens').value = s.sensitivity ?? 10;
    document.getElementById('rngGain').value = s.gain ?? 0;
    document.getElementById('chkPeq').checked = !!(s.peq && s.peq.enabled);
    document.getElementById('rngEqLow').value = bands[0] ? bands[0].gain : 0;
    document.getElementById('rngEqMid').value = bands[2] ? bands[2].gain : 0;
    document.getElementById('rngEqHigh').value = bands[4] ? bands[4].gain : 0;
    document.getElementById('chkComp').checked = !!(s.comp && s.comp.enabled);
    document.getElementById('rngCompThresh').value = s.comp ? s.comp.threshold : -24;
    document.getElementById('rngCompRatio').value = s.comp ? s.comp.ratio : 3;
    updateDspLabels(s);
}

function updateDspLabels(s) {
    const fmt = v => (v >= 0 ? '+' : '') + Number(v).toFixed(1);
    const bands = (s.peq && Array.isArray(s.peq.band)) ? s.peq.band : [];
    document.getElementById('lblSens').textContent = '+' + ((s.sensitivity ?? 0) * 3) + ' dB';
    document.getElementById('lblGain').textContent = fmt(s.gain ?? 0) + ' dB';
    document.getElementById('lblEqLow').textContent = fmt(bands[0] ? bands[0].gain : 0);
    document.getElementById('lblEqMid').textContent = fmt(bands[2] ? bands[2].gain : 0);
    document.getElementById('lblEqHigh').textContent = fmt(bands[4] ? bands[4].gain : 0);
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
    if (aucState.liveSettings.peq.band[0]) aucState.liveSettings.peq.band[0].gain = parseFloat(document.getElementById('rngEqLow').value);
    if (aucState.liveSettings.peq.band[2]) aucState.liveSettings.peq.band[2].gain = parseFloat(document.getElementById('rngEqMid').value);
    if (aucState.liveSettings.peq.band[4]) aucState.liveSettings.peq.band[4].gain = parseFloat(document.getElementById('rngEqHigh').value);
    aucState.liveSettings.comp.enabled = document.getElementById('chkComp').checked ? 1 : 0;
    aucState.liveSettings.comp.threshold = parseFloat(document.getElementById('rngCompThresh').value);
    aucState.liveSettings.comp.ratio = parseFloat(document.getElementById('rngCompRatio').value);
    updateDspLabels(aucState.liveSettings);

    clearTimeout(aucState.applyTimer);
    aucState.applyTimer = setTimeout(pushLiveDspSettings, 140);
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
