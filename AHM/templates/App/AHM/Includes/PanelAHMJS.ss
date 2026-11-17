<script>
(function () {
    const csrfToken = document.body.dataset.csrf || '';
    const toastEl = document.getElementById('status-toast');
    const workspaceEl = document.getElementById('ahmWorkspace');
    const deviceTabsEl = document.getElementById('ahmDeviceTabs');
    const connDot = document.getElementById('ahmConnectionDot');
    const modelBadge = document.getElementById('ahmModelBadge');

    const state = { devices: [], activeLocationId: '0', activeDeviceId: 0, activeSection: 'all', activePresetNum: 0, isDragging: false, pollCount: 0 };

    const showToast = (msg, isError = false) => {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.style.borderColor = isError ? '#ef4444' : '#38bdf8';
        toastEl.style.display = 'block';
        clearTimeout(toastEl._timer);
        toastEl._timer = setTimeout(() => { toastEl.style.display = 'none'; }, isError ? 5000 : 2400);
    };
    const esc = (s) => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    const formatDb = (db) => { const n = Number(db); return n <= -85 ? '-∞ dB' : (n > 0 ? '+' : '') + n.toFixed(1) + ' dB'; };

    async function ahmPost(payload) {
        const res = await fetch('/api/ahm/api', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.error || `HTTP ${res.status}`);
        return data;
    }

    const getVisibleDevices = () => state.devices.filter(d => state.activeLocationId === '0' || String(d.locationId || '0') === state.activeLocationId);
    const getActiveDevice = () => { const v = getVisibleDevices(); return v.find(d => d.id === state.activeDeviceId) || v[0] || null; };

    function renderStripCard(item, targetType, badgePrefix) {
        const isSend = targetType === 'send';
        const idAttr = isSend ? `data-send-key="${esc(item.key)}" data-input="${item.input}" data-zone="${item.zone}"` : `data-target-type="${targetType}" data-num="${item.num}"`;
        const tagText = isSend ? `In ${item.input} → Zone ${item.zone}` : `${badgePrefix} ${item.num}`;
        return `<div class="ahm-strip-card" ${idAttr}>
            <div class="ahm-strip-top">
                <div><div class="ahm-strip-tag">${esc(tagText)}</div><div class="ahm-strip-name">${esc(item.label)}</div></div>
                <div class="ahm-db-readout">${formatDb(item.db)}</div>
            </div>
            <div class="ahm-fader-row">
                <input type="range" class="ahm-slider" min="0" max="100" step="1" value="${item.percent}">
                <button type="button" class="ahm-mute-btn ${item.muted ? 'is-muted' : ''}">${item.muted ? 'MUTED' : 'MUTE'}</button>
            </div>
        </div>`;
    }

    function renderWorkspace() {
        const visible = getVisibleDevices();
        deviceTabsEl.innerHTML = '';
        visible.forEach(dev => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'device-tab' + (dev.id === getActiveDevice()?.id ? ' active' : '');
            btn.textContent = dev.title;
            btn.addEventListener('click', () => { state.activeDeviceId = dev.id; renderWorkspace(); });
            deviceTabsEl.appendChild(btn);
        });

        const dev = getActiveDevice();
        if (!dev) {
            connDot.style.backgroundColor = '#94a3b8';
            modelBadge.textContent = '';
            workspaceEl.innerHTML = `<div class="ahm-strip-card" style="text-align:center; padding:26px;"><p style="margin:0;">No active Allen &amp; Heath AHM processors configured for this room. Add one in <strong>/admin/ahm</strong>.</p></div>`;
            return;
        }

        state.activeDeviceId = dev.id;
        connDot.style.backgroundColor = dev.online ? '#22c55e' : '#ef4444';
        modelBadge.textContent = `${dev.model} • ${dev.ip}:${dev.port} (${dev.online ? 'Live' : 'Offline'}${dev.syncedAt ? ' • Synced ' + dev.syncedAt : ''})`;

        const sections = [
            { id: 'all', label: 'All Controls' },
            { id: 'zones', label: `Zones (${dev.zones.length})` },
            { id: 'inputs', label: `Inputs (${dev.inputs.length})` },
            { id: 'groups', label: `Groups (${dev.groups.length})` },
            { id: 'sends', label: `Zone Sends (${dev.sends.length})` }
        ];

        let html = `<div class="ahm-subtabs">${sections.map(s => `<button type="button" class="nav-pill ${state.activeSection === s.id ? 'active' : ''}" data-sec="${s.id}">${esc(s.label)}</button>`).join('')}</div>`;

        if (dev.presets?.length) {
            html += `<div class="ahm-section-title">Presets / Scenes</div><div class="ahm-presets-grid">` +
                dev.presets.map(p => `<button type="button" class="ahm-preset-btn ${state.activePresetNum === p.num ? 'active' : ''}" data-preset="${p.num}" data-label="${esc(p.label)}"><div style="font-size:0.72rem; opacity:0.65;">PRESET ${p.num}</div><div>${esc(p.label)}</div></button>`).join('') + `</div>`;
        }
        if ((state.activeSection === 'all' || state.activeSection === 'zones') && dev.zones.length) {
            html += `<div class="ahm-section-title">Zone Outputs</div><div class="ahm-strips-grid">${dev.zones.map(z => renderStripCard(z, 'zone', 'Zone')).join('')}</div>`;
        }
        if ((state.activeSection === 'all' || state.activeSection === 'groups') && dev.groups.length) {
            html += `<div class="ahm-section-title">Control Groups / DCAs</div><div class="ahm-strips-grid">${dev.groups.map(g => renderStripCard(g, 'group', 'Group')).join('')}</div>`;
        }
        if ((state.activeSection === 'all' || state.activeSection === 'inputs') && dev.inputs.length) {
            html += `<div class="ahm-section-title">Input Channels</div><div class="ahm-strips-grid">${dev.inputs.map(i => renderStripCard(i, 'input', 'Input')).join('')}</div>`;
        }
        if ((state.activeSection === 'all' || state.activeSection === 'sends') && dev.sends.length) {
            html += `<div class="ahm-section-title">Zone Source Sends</div><div class="ahm-strips-grid">${dev.sends.map(s => renderStripCard(s, 'send', 'Send')).join('')}</div>`;
        }

        workspaceEl.innerHTML = html;

        workspaceEl.querySelectorAll('[data-sec]').forEach(btn => btn.addEventListener('click', () => { state.activeSection = btn.dataset.sec; renderWorkspace(); }));
        workspaceEl.querySelectorAll('[data-preset]').forEach(btn => btn.addEventListener('click', async () => {
            const presetNum = parseInt(btn.dataset.preset, 10);
            try {
                await ahmPost({ device_id: dev.id, action: 'preset', value: presetNum });
                state.activePresetNum = presetNum;
                showToast(`${dev.title}: Recalled "${btn.dataset.label}"`);
                renderWorkspace();
                setTimeout(() => loadAhmStatus(true), 300);
            } catch (e) { showToast(e.message, true); }
        }));

        workspaceEl.querySelectorAll('.ahm-strip-card').forEach(card => {
            const slider = card.querySelector('.ahm-slider');
            const muteBtn = card.querySelector('.ahm-mute-btn');
            const dbEl = card.querySelector('.ahm-db-readout');
            const isSend = card.hasAttribute('data-send-key');
            let throttleTimer = null;

            const pushLevel = async (val) => {
                try {
                    const payload = isSend
                        ? { device_id: dev.id, action: 'send_level', input: parseInt(card.dataset.input, 10), zone: parseInt(card.dataset.zone, 10), value: val }
                        : { device_id: dev.id, action: 'level', target_type: card.dataset.targetType, number: parseInt(card.dataset.num, 10), value: val };
                    const resp = await ahmPost(payload);
                    if (dbEl && resp.db !== undefined) dbEl.textContent = formatDb(resp.db);
                } catch (e) { showToast(e.message, true); }
            };

            slider.addEventListener('pointerdown', () => { state.isDragging = true; });
            slider.addEventListener('input', () => { const v = parseInt(slider.value, 10); clearTimeout(throttleTimer); throttleTimer = setTimeout(() => pushLevel(v), 75); });
            slider.addEventListener('change', () => { state.isDragging = false; clearTimeout(throttleTimer); pushLevel(parseInt(slider.value, 10)); });

            muteBtn.addEventListener('click', async () => {
                const nextMuted = !muteBtn.classList.contains('is-muted');
                try {
                    const payload = isSend
                        ? { device_id: dev.id, action: 'send_mute', input: parseInt(card.dataset.input, 10), zone: parseInt(card.dataset.zone, 10), value: nextMuted }
                        : { device_id: dev.id, action: 'mute', target_type: card.dataset.targetType, number: parseInt(card.dataset.num, 10), value: nextMuted };
                    const resp = await ahmPost(payload);
                    muteBtn.classList.toggle('is-muted', !!resp.muted);
                    muteBtn.textContent = resp.muted ? 'MUTED' : 'MUTE';
                } catch (e) { showToast(e.message, true); }
            });
        });
    }

    async function loadAhmStatus(forceNames = false) {
        if (state.isDragging || document.hidden) return;
        const includeNames = forceNames || (state.pollCount % 5 === 0);
        state.pollCount++;
        try {
            const res = await fetch(includeNames ? '/api/ahm/status?names=1' : '/api/ahm/status', { credentials: 'same-origin' });
            if (res.status === 401 || res.status === 403) return;
            const data = await res.json();
            if (!data.success) throw new Error(data.error || 'Failed to load AHM status');
            state.devices = data.devices || [];
            renderWorkspace();
        } catch (e) { connDot.style.backgroundColor = '#ef4444'; }
    }

    document.querySelectorAll('.unified-room-tabs .room-tab').forEach(btn => btn.addEventListener('click', () => {
        state.activeLocationId = btn.dataset.locationId || '0';
        document.querySelectorAll('.unified-room-tabs .room-tab').forEach(b => b.classList.toggle('active', b === btn));
        renderWorkspace();
    }));
    document.getElementById('btnRefreshAhm')?.addEventListener('click', async () => { await 
    function applyAhmViewMode(mode) {
        const m = (mode === 'list') ? 'list' : 'grid';
        localStorage.setItem('ahm_view_mode', m);
        workspaceEl?.classList.toggle('is-list-view', m === 'list');
        document.querySelectorAll('[data-view-mode]').forEach(b => b.classList.toggle('active', b.dataset.viewMode === m));
    }
    document.querySelectorAll('[data-view-mode]').forEach(b => b.addEventListener('click', () => applyAhmViewMode(b.dataset.viewMode)));
    applyAhmViewMode(localStorage.getItem('ahm_view_mode') || 'grid');

    loadAhmStatus(true); showToast('Resynced AHM levels, mutes & channel names'); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) 
    function applyAhmViewMode(mode) {
        const m = (mode === 'list') ? 'list' : 'grid';
        localStorage.setItem('ahm_view_mode', m);
        workspaceEl?.classList.toggle('is-list-view', m === 'list');
        document.querySelectorAll('[data-view-mode]').forEach(b => b.classList.toggle('active', b.dataset.viewMode === m));
    }
    document.querySelectorAll('[data-view-mode]').forEach(b => b.addEventListener('click', () => applyAhmViewMode(b.dataset.viewMode)));
    applyAhmViewMode(localStorage.getItem('ahm_view_mode') || 'grid');

    loadAhmStatus(true); });

    
    function applyAhmViewMode(mode) {
        const m = (mode === 'list') ? 'list' : 'grid';
        localStorage.setItem('ahm_view_mode', m);
        workspaceEl?.classList.toggle('is-list-view', m === 'list');
        document.querySelectorAll('[data-view-mode]').forEach(b => b.classList.toggle('active', b.dataset.viewMode === m));
    }
    document.querySelectorAll('[data-view-mode]').forEach(b => b.addEventListener('click', () => applyAhmViewMode(b.dataset.viewMode)));
    applyAhmViewMode(localStorage.getItem('ahm_view_mode') || 'grid');

    loadAhmStatus(true);
    setInterval(() => loadAhmStatus(false), 3000);
})();
</script>
