<script>
const ibtPreviewFrameCache = new Map();
const ibtState = {
    csrfToken: '',
    locations: [],
    encoders: [],
    decoders: [],
    presets: [],
    onlineMap: {},
    activeLocationId: 0,
    activeStreamType: 'all',
    selectedDecoderIds: new Set(),
    discovered: []
};

function showIbtToast(message, isError = false) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = isError ? 'show error' : 'show';
    toast.style.pointerEvents = 'auto';
    toast.onclick = () => { toast.className = ''; };
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.className = ''; }, isError ? 12000 : 3000);
}

async function parseIbtJson(res) {
    const text = await res.text();
    try { return JSON.parse(text); } catch (e) { throw new Error('HTTP ' + res.status + ': ' + text.slice(0, 120)); }
}

async function ibtPost(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': ibtState.csrfToken },
        body: JSON.stringify(payload)
    });
}

async function loadIbtStatus() {
    try {
        const res = await fetch('/api/ibt/status');
        if (res.status === 401) { window.location.href = '/panel'; return; }
        const data = await parseIbtJson(res);
        if (!data.success) throw new Error('Failed to load IBT status');
        ibtState.csrfToken = data.csrfToken || '';
        ibtState.locations = data.locations || [];
        ibtState.encoders = data.encoders || [];
        ibtState.decoders = data.decoders || [];
        ibtState.presets = data.presets || [];
        if (ibtState.selectedDecoderIds.size === 0 && ibtState.decoders.length > 0) {
            ibtState.selectedDecoderIds.add(ibtState.decoders[0].id);
        }
        renderIbt();
    } catch (err) {
        document.getElementById('ibtConnectionDot').style.backgroundColor = '#ef4444';
        showIbtToast(err.message || 'Unable to reach IBT API server', true);
    }
}

async function loadIbtPing() {
    try {
        const res = await fetch('/api/ibt/ping');
        if (!res.ok) return;
        const data = await parseIbtJson(res);
        if (data.success && data.online) {
            ibtState.onlineMap = data.online;
            renderIbtDecoders();
            renderIbtEncoders();
        }
    } catch (e) {}
}

function getIbtLocationName(id) {
    const loc = ibtState.locations.find(l => l.id === id);
    return loc ? loc.title : 'Unassigned';
}

function getIbtEncoderName(id) {
    if (!id) return 'Blank / No Source';
    const enc = ibtState.encoders.find(e => e.id === id);
    return enc ? enc.title : 'TX #' + id;
}

function createIbtPingBadge(deviceId) {
    const st = ibtState.onlineMap[deviceId];
    const badge = document.createElement('span');
    badge.className = 'ping-badge';
    const dot = document.createElement('span');
    dot.className = 'ping-dot' + (st === true ? ' online' : (st === false ? ' offline' : ''));
    const lbl = document.createElement('span');
    lbl.textContent = st === true ? 'ONLINE' : (st === false ? 'OFFLINE' : '...');
    badge.append(dot, lbl);
    return badge;
}

function loadIbtPreviewFrame(img, deviceId) {
    if (!deviceId) return;
    const url = '/api/ibt/preview/' + deviceId + '?t=' + Date.now();
    const preloader = new Image();
    preloader.onload = () => {
        ibtPreviewFrameCache.set(deviceId, preloader.src);
        if (img.isConnected) {
            img.src = preloader.src;
            img.classList.add('is-loaded');
        }
    };
    preloader.onerror = () => {
        if (img.isConnected && !ibtPreviewFrameCache.has(deviceId)) {
            img.classList.remove('is-loaded');
        }
    };
    preloader.src = url;
}

function createIbtPreviewBox(deviceId, deviceTitle) {
    const wrap = document.createElement('div');
    wrap.className = 'vpx-preview-wrap';
    const img = document.createElement('img');
    img.className = 'vpx-preview-img';
    img.dataset.deviceId = String(deviceId);
    img.alt = (deviceTitle || 'Device') + ' live preview';
    if (ibtPreviewFrameCache.has(deviceId)) {
        img.src = ibtPreviewFrameCache.get(deviceId);
        img.classList.add('is-loaded');
    } else {
        loadIbtPreviewFrame(img, deviceId);
    }
    const placeholder = document.createElement('span');
    placeholder.className = 'vpx-preview-placeholder';
    placeholder.textContent = ibtState.onlineMap[deviceId] === false ? 'Offline' : 'No Signal';
    wrap.append(img, placeholder);
    return wrap;
}

function createIbtSerialRow(device) {
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
        btn.onclick = (e) => { e.stopPropagation(); sendIbtCommand(device.id, item.command, device.title); };
        row.appendChild(btn);
    });
    return row;
}

async function routeIbtToSelected(encoderId, encoderTitle) {
    if (ibtState.selectedDecoderIds.size === 0) {
        showIbtToast('Select at least one Receiver on the left first', true);
        return;
    }
    const targets = Array.from(ibtState.selectedDecoderIds);
    const errors = [];
    for (const decoderId of targets) {
        try {
            const res = await ibtPost('/api/ibt/route', { decoder_id: decoderId, encoder_id: encoderId, stream_type: ibtState.activeStreamType });
            const data = await parseIbtJson(res);
            if (!data.success) errors.push(data.error || 'Routing failed');
        } catch (e) { errors.push(e.message || 'Network error'); }
    }
    await loadIbtStatus();
    errors.length > 0
        ? showIbtToast('Error: ' + errors[0], true)
        : showIbtToast('Routed ' + encoderTitle + ' (' + ibtState.activeStreamType.toUpperCase() + ') to ' + targets.length + ' display(s)');
}

async function disconnectIbtDecoder(decoderId, decoderTitle) {
    try {
        const res = await ibtPost('/api/ibt/route', { decoder_id: decoderId, encoder_id: 0, stream_type: 'all' });
        const data = await parseIbtJson(res);
        await loadIbtStatus();
        data.success ? showIbtToast(decoderTitle + ': Disconnected all streams') : showIbtToast('Disconnect Error: ' + (data.error || 'Failed'), true);
    } catch (e) { showIbtToast(e.message || 'Failed to disconnect', true); }
}

async function sendIbtCommand(deviceId, cmd, title) {
    try {
        const res = await ibtPost('/api/ibt/command', { device_id: deviceId, command: cmd });
        const data = await parseIbtJson(res);
        data.success ? showIbtToast(title + ': Sent "' + cmd + '"') : showIbtToast('Command Error: ' + (data.error || 'Failed'), true);
    } catch (e) { showIbtToast(e.message || 'Command failed', true); }
}

async function triggerIbtPreset(presetId, presetTitle) {
    try {
        const res = await ibtPost('/api/ibt/preset', { preset_id: presetId });
        const data = await parseIbtJson(res);
        await loadIbtStatus();
        data.success ? showIbtToast('Activated preset: ' + presetTitle) : showIbtToast('Preset error: ' + ((data.result?.errors?.[0]) || data.error || 'Failed'), true);
    } catch (e) { showIbtToast(e.message || 'Preset failed', true); }
}

function selectAllIbtDisplays() {
    ibtState.decoders.filter(d => ibtState.activeLocationId === 0 || d.locationId === ibtState.activeLocationId)
        .forEach(d => ibtState.selectedDecoderIds.add(d.id));
    renderIbtDecoders();
    renderIbtEncoders();
}

function clearIbtDisplays() {
    ibtState.selectedDecoderIds.clear();
    renderIbtDecoders();
    renderIbtEncoders();
}

async function powerAllIbtDisplays(turnOn) {
    const visible = ibtState.decoders.filter(d => ibtState.activeLocationId === 0 || d.locationId === ibtState.activeLocationId);
    if (visible.length === 0) { showIbtToast('No displays in this room', true); return; }
    for (const dec of visible) {
        await sendIbtCommand(dec.id, turnOn ? dec.powerOnCommand : dec.powerOffCommand, dec.title);
    }
}

function renderIbtRooms() {
    const c = document.getElementById('ibtRoomTabs');
    c.innerHTML = '';
    const allBtn = document.createElement('button');
    allBtn.className = 'room-tab' + (ibtState.activeLocationId === 0 ? ' active' : '');
    allBtn.textContent = 'All Rooms';
    allBtn.onclick = () => { ibtState.activeLocationId = 0; renderIbt(); };
    c.appendChild(allBtn);
    ibtState.locations.forEach(loc => {
        const btn = document.createElement('button');
        btn.className = 'room-tab' + (ibtState.activeLocationId === loc.id ? ' active' : '');
        btn.textContent = loc.title;
        btn.onclick = () => { ibtState.activeLocationId = loc.id; renderIbt(); };
        c.appendChild(btn);
    });
}

function renderIbtStreamToggles() {
    const c = document.getElementById('ibtStreamToggles');
    c.innerHTML = '';
    [{ key: 'all', label: 'AV (All)' }, { key: 'video', label: 'Video Only' }, { key: 'audio', label: 'Audio Breakaway' }, { key: 'usb', label: 'USB / KVM' }].forEach(m => {
        const btn = document.createElement('button');
        const active = ibtState.activeStreamType === m.key;
        btn.className = 'stream-pill' + (active ? (m.key === 'audio' ? ' active audio-mode' : ' active') : '');
        btn.textContent = m.label;
        btn.onclick = () => { ibtState.activeStreamType = m.key; renderIbt(); };
        c.appendChild(btn);
    });
}

function renderIbtPresets() {
    const sec = document.getElementById('ibtPresetsSection');
    const c = document.getElementById('ibtPresetsGrid');
    c.innerHTML = '';
    const list = ibtState.presets.filter(p => ibtState.activeLocationId === 0 || p.locationId === 0 || p.locationId === ibtState.activeLocationId);
    sec.style.display = list.length ? 'block' : 'none';
    list.forEach(p => {
        const btn = document.createElement('button');
        btn.className = 'preset-btn';
        btn.innerHTML = '<div class="preset-title"></div><div class="preset-desc"></div>';
        btn.querySelector('.preset-title').textContent = p.title;
        btn.querySelector('.preset-desc').textContent = p.description || 'Tap to activate scene';
        btn.onclick = () => triggerIbtPreset(p.id, p.title);
        c.appendChild(btn);
    });
}

function renderIbtDecoders() {
    const c = document.getElementById('ibtDecodersGrid');
    c.innerHTML = '';
    const list = ibtState.decoders.filter(d => ibtState.activeLocationId === 0 || d.locationId === ibtState.activeLocationId);
    if (list.length === 0) {
        c.innerHTML = '<div style="opacity:0.75; padding:12px;">No IBT-1-BX Receivers configured yet. Add them in Admin &rarr; IBT-1-BX Matrix or click Scan Network for IBT.</div>';
        return;
    }
    list.forEach(dec => {
        const card = document.createElement('div');
        card.className = 'decoder-card' + (ibtState.selectedDecoderIds.has(dec.id) ? ' selected' : '');
        card.onclick = () => {
            ibtState.selectedDecoderIds.has(dec.id) ? ibtState.selectedDecoderIds.delete(dec.id) : ibtState.selectedDecoderIds.add(dec.id);
            renderIbtDecoders();
            renderIbtEncoders();
        };

        const previewBox = createIbtPreviewBox(dec.id, dec.title);

        const header = document.createElement('div');
        header.className = 'device-header-row';
        const room = document.createElement('div');
        room.className = 'device-room';
        room.textContent = getIbtLocationName(dec.locationId);
        header.append(room, createIbtPingBadge(dec.id));

        const name = document.createElement('div');
        name.className = 'device-name';
        name.textContent = dec.title;

        const badges = document.createElement('div');
        badges.className = 'badges-row';
        const vBadge = document.createElement('span');
        vBadge.className = dec.videoEncoderId ? 'active-source-badge' : 'active-source-badge unsubscribed';
        vBadge.textContent = 'Video: ' + getIbtEncoderName(dec.videoEncoderId);
        badges.appendChild(vBadge);
        if (dec.audioEncoderId !== dec.videoEncoderId) {
            const aBadge = document.createElement('span');
            aBadge.className = 'active-source-badge audio-badge';
            aBadge.textContent = 'Audio: ' + getIbtEncoderName(dec.audioEncoderId);
            badges.appendChild(aBadge);
        }

        const cecRow = document.createElement('div');
        cecRow.className = 'cec-controls';
        const onBtn = document.createElement('button');
        onBtn.type = 'button'; onBtn.className = 'cec-btn on'; onBtn.textContent = 'Display ON';
        onBtn.onclick = (e) => { e.stopPropagation(); sendIbtCommand(dec.id, dec.powerOnCommand, dec.title); };
        const offBtn = document.createElement('button');
        offBtn.type = 'button'; offBtn.className = 'cec-btn off'; offBtn.textContent = 'Display OFF';
        offBtn.onclick = (e) => { e.stopPropagation(); sendIbtCommand(dec.id, dec.powerOffCommand, dec.title); };
        const discBtn = document.createElement('button');
        discBtn.type = 'button'; discBtn.className = 'cec-btn disconnect'; discBtn.textContent = 'Disconnect';
        discBtn.disabled = !dec.videoEncoderId && !dec.audioEncoderId;
        discBtn.onclick = (e) => { e.stopPropagation(); disconnectIbtDecoder(dec.id, dec.title); };
        cecRow.append(onBtn, offBtn, discBtn);

        card.append(previewBox, header, name, badges, cecRow);
        const sRow = createIbtSerialRow(dec);
        if (sRow) card.appendChild(sRow);
        c.appendChild(card);
    });
}

function renderIbtEncoders() {
    const c = document.getElementById('ibtEncodersGrid');
    c.innerHTML = '';
    const list = ibtState.encoders.filter(e => ibtState.activeLocationId === 0 || e.locationId === ibtState.activeLocationId);
    if (list.length === 0) {
        c.innerHTML = '<div style="opacity:0.75; padding:12px;">No IBT-1-BX Transmitters configured yet.</div>';
        return;
    }
    let activeEncoderId = 0;
    if (ibtState.selectedDecoderIds.size === 1) {
        const selId = Array.from(ibtState.selectedDecoderIds)[0];
        const selDec = ibtState.decoders.find(d => d.id === selId);
        if (selDec) activeEncoderId = selDec.videoEncoderId || 0;
    }
    list.forEach(enc => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'encoder-btn' + (activeEncoderId === enc.id ? ' active-for-selected' : '');
        btn.onclick = () => routeIbtToSelected(enc.id, enc.title);

        const previewBox = createIbtPreviewBox(enc.id, enc.title);

        const header = document.createElement('div');
        header.className = 'device-header-row';
        const room = document.createElement('div');
        room.className = 'device-room';
        room.textContent = getIbtLocationName(enc.locationId) + (enc.channelNumber ? ' · CH ' + enc.channelNumber : '');
        header.append(room, createIbtPingBadge(enc.id));

        const name = document.createElement('div');
        name.className = 'device-name';
        name.style.margin = '4px 0 0 0';
        name.textContent = enc.title;

        btn.append(previewBox, header, name);
        const sRow = createIbtSerialRow(enc);
        if (sRow) btn.appendChild(sRow);
        c.appendChild(btn);
    });
}

function renderIbt() {
    renderIbtRooms();
    renderIbtStreamToggles();
    renderIbtPresets();
    renderIbtDecoders();
    renderIbtEncoders();
}

function refreshAllIbtPreviews() {
    if (document.hidden) return;
    document.querySelectorAll('img.vpx-preview-img').forEach((img, idx) => {
        const deviceId = Number(img.dataset.deviceId);
        if (!deviceId) return;
        setTimeout(() => loadIbtPreviewFrame(img, deviceId), idx * 150);
    });
}

function openIbtDiscoverModal() { document.getElementById('ibtDiscoverModal').classList.add('open'); }
function closeIbtDiscoverModal() { document.getElementById('ibtDiscoverModal').classList.remove('open'); }

async function runIbtScan() {
    const btn = document.getElementById('ibtScanBtn');
    const subnet = document.getElementById('ibtSubnetInput').value.trim();
    btn.disabled = true; btn.textContent = 'Scanning...';
    try {
        const res = await ibtPost('/api/ibt/discover', { subnet });
        const data = await parseIbtJson(res);
        if (!data.success) throw new Error(data.error || 'Scan failed');
        ibtState.discovered = data.devices || [];
        const tbl = document.getElementById('ibtResultsTable');
        const body = document.getElementById('ibtResultsBody');
        const impBtn = document.getElementById('ibtImportBtn');
        body.innerHTML = '';
        tbl.style.display = ibtState.discovered.length ? 'table' : 'none';
        impBtn.style.display = ibtState.discovered.length ? 'inline-block' : 'none';
        const locOpts = '<option value="0">-- Unassigned --</option>' + ibtState.locations.map(l => `<option value="${l.id}">${l.title}</option>`).join('');
        ibtState.discovered.forEach((dev, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><input type="checkbox" class="ibt-row-chk" data-idx="${idx}" ${!dev.alreadyExists ? 'checked' : ''}></td>
                <td><strong>${dev.ip}</strong><br><small>${dev.mac || ''}</small></td>
                <td>${dev.alreadyExists ? 'In DB' : 'New'}</td>
                <td><input type="text" class="ibt-disc-input" id="ibt_title_${idx}" value="${dev.title}"></td>
                <td><input type="text" class="ibt-disc-input" id="ibt_host_${idx}" value="${dev.hostname}"></td>
                <td><select class="ibt-disc-select" id="ibt_mode_${idx}"><option value="Decoder" ${dev.mode === 'Decoder' ? 'selected' : ''}>Decoder (RX)</option><option value="Encoder" ${dev.mode === 'Encoder' ? 'selected' : ''}>Encoder (TX)</option></select></td>
                <td><select class="ibt-disc-select" id="ibt_loc_${idx}">${locOpts}</select></td>`;
            body.appendChild(tr);
        });
        showIbtToast('Found ' + ibtState.discovered.length + ' device(s)');
    } catch (e) { showIbtToast(e.message, true); }
    finally { btn.disabled = false; btn.textContent = 'Scan Network'; }
}

async function importIbtSelected() {
    const selected = [];
    document.querySelectorAll('.ibt-row-chk:checked').forEach(cb => {
        const idx = Number(cb.dataset.idx);
        const base = ibtState.discovered[idx];
        selected.push({
            ip: base.ip, controlPort: base.controlPort, mac: base.mac,
            title: document.getElementById('ibt_title_' + idx).value.trim(),
            hostname: document.getElementById('ibt_host_' + idx).value.trim(),
            mode: document.getElementById('ibt_mode_' + idx).value,
            locationId: Number(document.getElementById('ibt_loc_' + idx).value)
        });
    });
    if (selected.length === 0) { showIbtToast('Select at least one device', true); return; }
    const res = await ibtPost('/api/ibt/import', { devices: selected });
    const data = await parseIbtJson(res);
    if (data.success) {
        showIbtToast(`Imported: ${data.created} added, ${data.updated} updated`);
        closeIbtDiscoverModal();
        await loadIbtStatus();
    } else { showIbtToast(data.error || 'Import failed', true); }
}

loadIbtStatus().then(() => loadIbtPing());
setInterval(loadIbtStatus, 10000);
setInterval(loadIbtPing, 15000);
setInterval(refreshAllIbtPreviews, 3000);
</script>
