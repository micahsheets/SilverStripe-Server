<script>
const previewFrameCache = new Map();

function createSerialButtonsRow(device) {
    const cmds = Array.isArray(device.serialCommands) ? device.serialCommands : [];
    if (cmds.length === 0) return null;

    const row = document.createElement('div');
    row.className = 'cec-controls serial-controls';
    row.style.marginTop = '6px';
    row.style.flexWrap = 'wrap';

    cmds.forEach(item => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cec-btn serial-btn';
        btn.textContent = item.title;
        btn.title = item.command;
        btn.onclick = (e) => {
            e.stopPropagation();
            sendCEC(device.id, item.command, device.title);
        };
        row.appendChild(btn);
    });

    return row;
}

function getLocationName(locationId) {
    const loc = state.locations.find(l => l.id === locationId);
    return loc ? loc.title : 'Unassigned';
}

function getEncoderName(encoderId) {
    if (!encoderId) return 'Blank / No Source';
    const enc = state.encoders.find(e => e.id === encoderId);
    return enc ? enc.title : 'Encoder #' + encoderId;
}

function createPingBadge(deviceId) {
    const status = state.onlineMap[deviceId];
    const badge = document.createElement('span');
    badge.className = 'ping-badge';

    const dot = document.createElement('span');
    dot.className = 'ping-dot' + (status === true ? ' online' : (status === false ? ' offline' : ''));

    const label = document.createElement('span');
    label.textContent = status === true ? 'ONLINE' : (status === false ? 'OFFLINE' : '...');

    badge.append(dot, label);
    return badge;
}

function loadPreviewFrame(img, deviceId) {
    if (!deviceId) return;
    const url = '/api/vpx/preview/' + deviceId + '?t=' + Date.now();
    const preloader = new Image();

    preloader.onload = () => {
        previewFrameCache.set(deviceId, preloader.src);
        if (img.isConnected) {
            img.src = preloader.src;
            img.classList.add('is-loaded');
        }
    };

    preloader.onerror = () => {
        if (img.isConnected && !previewFrameCache.has(deviceId)) {
            img.classList.remove('is-loaded');
        }
    };

    preloader.src = url;
}

function createPreviewBox(deviceId, deviceTitle) {
    const wrap = document.createElement('div');
    wrap.className = 'vpx-preview-wrap';

    const img = document.createElement('img');
    img.className = 'vpx-preview-img';
    img.dataset.deviceId = String(deviceId);
    img.alt = (deviceTitle || 'Device') + ' live preview';

    if (previewFrameCache.has(deviceId)) {
        img.src = previewFrameCache.get(deviceId);
        img.classList.add('is-loaded');
    } else {
        loadPreviewFrame(img, deviceId);
    }

    const placeholder = document.createElement('span');
    placeholder.className = 'vpx-preview-placeholder';
    placeholder.textContent = state.onlineMap[deviceId] === false ? 'Offline' : 'No Signal';

    wrap.append(img, placeholder);
    return wrap;
}


function renderRooms() {
    const container = document.getElementById('roomTabs');
    container.innerHTML = '';

    const allBtn = document.createElement('button');
    allBtn.className = 'room-tab' + (state.activeLocationId === 0 ? ' active' : '');
    allBtn.textContent = 'All Rooms';
    allBtn.onclick = () => { state.activeLocationId = 0; state.selectedDecoderIds.clear(); render(); };
    container.appendChild(allBtn);

    state.locations.forEach(loc => {
        const btn = document.createElement('button');
        btn.className = 'room-tab' + (state.activeLocationId === loc.id ? ' active' : '');
        btn.textContent = loc.title;
        btn.onclick = () => { state.activeLocationId = loc.id; state.selectedDecoderIds.clear(); render(); };
        container.appendChild(btn);
    });
}

function renderStreamToggles() {
    const container = document.getElementById('streamToggles');
    container.innerHTML = '';

    const modes = [
        { key: 'all', label: 'AV (All)' },
        { key: 'video', label: 'Video Only' },
        { key: 'audio', label: 'Audio Breakaway' }
    ];

    modes.forEach(mode => {
        const btn = document.createElement('button');
        const isActive = state.activeStreamType === mode.key;
        btn.className = 'stream-pill' + (isActive ? (mode.key === 'audio' ? ' active audio-mode' : ' active') : '');
        btn.textContent = mode.label;
        btn.onclick = () => { state.activeStreamType = mode.key; render(); };
        container.appendChild(btn);
    });
}

function renderPresets() {
    const section = document.getElementById('presetsSection');
    const container = document.getElementById('presetsGrid');
    container.innerHTML = '';

    const hasRoomDevices = state.activeLocationId === 0
        || state.decoders.some(d => d.locationId === state.activeLocationId)
        || state.encoders.some(e => e.locationId === state.activeLocationId);
    const visiblePresets = !hasRoomDevices ? [] : state.presets.filter(p =>
        state.activeLocationId === 0 || p.locationId === 0 || p.locationId === state.activeLocationId
    );
    section.style.display = visiblePresets.length ? 'block' : 'none';

    const count = visiblePresets.length;
    if (document.body.classList.contains('presets-only-mode')) {
        const cols = count <= 1 ? 1 : (count <= 4 ? 2 : (count <= 9 ? 3 : 4));
        const rows = Math.max(1, Math.ceil(count / cols));
        container.style.gridTemplateColumns = `repeat(${cols}, minmax(0, 1fr))`;
        container.style.gridTemplateRows = `repeat(${rows}, minmax(0, 1fr))`;
    } else {
        container.style.gridTemplateColumns = '';
        container.style.gridTemplateRows = '';
    }

    visiblePresets.forEach(preset => {
        const btn = document.createElement('button');
        btn.className = 'preset-btn';
        btn.innerHTML = '<div class="preset-title"></div><div class="preset-desc"></div>';
        btn.querySelector('.preset-title').textContent = preset.title;
        btn.querySelector('.preset-desc').textContent = preset.description || 'Tap to activate scene';
        btn.onclick = () => triggerPreset(preset.id, preset.title);
        container.appendChild(btn);
    });
}

function renderDecoders() {
    const container = document.getElementById('decodersGrid');
    container.innerHTML = '';

    const visibleDecoders = state.decoders.filter(d =>
        state.activeLocationId === 0 || d.locationId === state.activeLocationId
    );

    if (visibleDecoders.length === 0) {
        container.innerHTML = '<div style="opacity:0.75; padding:12px;">No VPX displays assigned to this room.</div>';
    }

    visibleDecoders.forEach(dec => {
        const card = document.createElement('div');
        card.className = 'decoder-card' + (state.selectedDecoderIds.has(dec.id) ? ' selected' : '');

        const previewBox = createPreviewBox(dec.id, dec.title);

        const topWrap = document.createElement('div');
        const headerRow = document.createElement('div');
        headerRow.className = 'device-header-row';

        const roomEl = document.createElement('div');
        roomEl.className = 'device-room';
        roomEl.textContent = getLocationName(dec.locationId);

        headerRow.append(roomEl, createPingBadge(dec.id));

        const nameEl = document.createElement('div');
        nameEl.className = 'device-name';
        nameEl.textContent = dec.title;
        topWrap.append(headerRow, nameEl);

        const badgesRow = document.createElement('div');
        badgesRow.className = 'badges-row';
        const videoBadge = document.createElement('span');
        videoBadge.className = dec.videoEncoderId ? 'active-source-badge' : 'active-source-badge unsubscribed';
        videoBadge.textContent = 'Video: ' + getEncoderName(dec.videoEncoderId);
        badgesRow.appendChild(videoBadge);

        if (dec.audioEncoderId !== dec.videoEncoderId) {
            const audioBadge = document.createElement('span');
            audioBadge.className = 'active-source-badge audio-badge';
            audioBadge.textContent = 'Audio: ' + getEncoderName(dec.audioEncoderId);
            badgesRow.appendChild(audioBadge);
        }

        const cecRow = document.createElement('div');
        cecRow.className = 'cec-controls';

        const onBtn = document.createElement('button');
        onBtn.type = 'button';
        onBtn.className = 'cec-btn on';
        onBtn.textContent = 'Display ON';
        onBtn.onclick = (e) => {
            e.stopPropagation();
            sendCEC(dec.id, dec.powerOnCommand || 'cec power on', dec.title);
        };

        const offBtn = document.createElement('button');
        offBtn.type = 'button';
        offBtn.className = 'cec-btn off';
        offBtn.textContent = 'Display OFF';
        offBtn.onclick = (e) => {
            e.stopPropagation();
            sendCEC(dec.id, dec.powerOffCommand || 'cec power off', dec.title);
        };

        const disconnectBtn = document.createElement('button');
        disconnectBtn.type = 'button';
        disconnectBtn.className = 'cec-btn disconnect';
        disconnectBtn.textContent = 'Disconnect';
        disconnectBtn.title = 'Disconnect all video and audio streams from this display';
        disconnectBtn.disabled = !dec.videoEncoderId && !dec.audioEncoderId && !dec.localAudioActive;
        disconnectBtn.onclick = (e) => {
            e.stopPropagation();
            disconnectDecoder(dec.id, dec.title);
        };

        cecRow.append(onBtn, offBtn, disconnectBtn);

        const serialRow = createSerialButtonsRow(dec);
        if (serialRow) {
            card.append(previewBox, topWrap, badgesRow, cecRow, serialRow);
        } else {
            card.append(previewBox, topWrap, badgesRow, cecRow);
        }
        card.onclick = () => {
            state.selectedDecoderIds.has(dec.id) ? state.selectedDecoderIds.delete(dec.id) : state.selectedDecoderIds.add(dec.id);
            render();
        };
        container.appendChild(card);
    });

    const count = state.selectedDecoderIds.size;
    document.getElementById('selectionHint').textContent = count > 0 ? count + ' display(s) selected' : 'Tap display(s) first';
}

function renderEncoders() {
    const container = document.getElementById('encodersGrid');
    container.innerHTML = '';

    let activeEncoderId = null;
    if (state.selectedDecoderIds.size === 1) {
        const singleId = Array.from(state.selectedDecoderIds).at(0);
        const dec = state.decoders.find(d => d.id === singleId);
        if (dec) activeEncoderId = state.activeStreamType === 'audio' ? dec.audioEncoderId : dec.videoEncoderId;
    }

    const visibleEncoders = state.encoders.filter(e =>
        state.activeLocationId === 0 || e.locationId === state.activeLocationId
    );

    if (visibleEncoders.length === 0) {
        container.innerHTML = '<div style="opacity:0.75; padding:12px;">No VPX sources assigned to this room.</div>';
        return;
    }

    visibleEncoders.forEach(enc => {
        const btn = document.createElement('button');
        btn.className = 'encoder-btn' + (activeEncoderId === enc.id ? ' active-for-selected' : '');

        const previewBox = createPreviewBox(enc.id, enc.title);

        const headerRow = document.createElement('div');
        headerRow.className = 'device-header-row';
        const roomEl = document.createElement('div');
        roomEl.className = 'device-room';
        roomEl.textContent = getLocationName(enc.locationId);
        headerRow.append(roomEl, createPingBadge(enc.id));

        const nameEl = document.createElement('div');
        nameEl.className = 'device-name';
        nameEl.style.margin = '4px 0 0 0';
        nameEl.textContent = enc.title;
        const encSerialRow = createSerialButtonsRow(enc);
        if (encSerialRow) {
            btn.append(previewBox, headerRow, nameEl, encSerialRow);
        } else {
            btn.append(previewBox, headerRow, nameEl);
        }

        btn.onclick = () => routeToSelected(enc.id, enc.title);
        container.appendChild(btn);
    });
}

function selectAllVisibleDisplays() {
    const visibleDecoders = state.decoders.filter(d =>
        state.activeLocationId === 0 || d.locationId === state.activeLocationId
    );
    visibleDecoders.forEach(d => state.selectedDecoderIds.add(d.id));
    render();
}

function clearSelectedDisplays() {
    state.selectedDecoderIds.clear();
    render();
}

function render() {
    renderRooms();
    renderStreamToggles();
    renderPresets();
    renderDecoders();
    renderEncoders();
}

function refreshAllPreviews() {
    if (document.hidden) return;
    document.querySelectorAll('img.vpx-preview-img').forEach((img, idx) => {
        const deviceId = Number(img.dataset.deviceId);
        if (!deviceId) return;
        setTimeout(() => loadPreviewFrame(img, deviceId), idx * 150);
    });
}

loadStatus().then(() => loadPingStatus());
setInterval(loadStatus, 10000);
setInterval(loadPingStatus, 15000);
setInterval(refreshAllPreviews, 3000);
</script>
