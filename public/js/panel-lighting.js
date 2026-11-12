(function () {
    let csrfToken = document.body.dataset.csrf || '';
    let devices = [];
    let activeDevice = null;
    let playlists = [];
    let recordingState = null;

    const toastEl = document.getElementById('status-toast');
    const deviceTabsEl = document.getElementById('enttec-device-tabs');
    const deviceMetaEl = document.getElementById('enttec-device-meta');
    const scenesGridEl = document.getElementById('enttec-scenes-grid');
    const drawerEl = document.getElementById('enttec-recorder-drawer');
    const slotSelectEl = document.getElementById('slot-select');
    const nameInputEl = document.getElementById('scene-name-input');
    const cueTypeSelectEl = document.getElementById('cue-type-select');
    const sourceTypeSelectEl = document.getElementById('source-type-select');
    const universeBoxEl = document.getElementById('universe-checkboxes');
    const modelHintEl = document.getElementById('enttec-model-hint');

    const recordBtn = document.getElementById('btn-record-scene');
    const stopBtn = document.getElementById('btn-stop-recording');
    const deleteBtn = document.getElementById('btn-delete-scene');
    const timerEl = document.getElementById('record-timer');

    function showToast(msg, isError = false) {
        toastEl.textContent = msg;
        toastEl.style.borderColor = isError ? '#ef4444' : '#38bdf8';
        toastEl.style.display = 'block';
        clearTimeout(toastEl._timer);
        toastEl._timer = setTimeout(() => { toastEl.style.display = 'none'; }, 3200);
    }

    async function apiGet(url) {
        const res = await fetch(url, { credentials: 'same-origin' });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }
        return data;
    }

    async function apiPost(url, payload) {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }
        return data;
    }

    async function init() {
        try {
            const status = await apiGet('/api/enttec/status');
            if (status.csrfToken) csrfToken = status.csrfToken;
            devices = status.devices || [];

            if (devices.length === 0) {
                scenesGridEl.innerHTML = '<div style="color:#fca5a5;">No Enttec S-Play devices configured. Add one in Admin &rarr; AV System.</div>';
                return;
            }

            renderDeviceTabs();
            await selectDevice(devices[0]);
        } catch (err) {
            scenesGridEl.innerHTML = `<div style="color:#fca5a5;">${err.message}</div>`;
        }
    }

    function renderDeviceTabs() {
        deviceTabsEl.innerHTML = '';
        devices.forEach((dev) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'device-tab' + (activeDevice && activeDevice.id === dev.id ? ' active' : '');
            btn.textContent = dev.title;
            btn.addEventListener('click', () => selectDevice(dev));
            deviceTabsEl.appendChild(btn);
        });
    }

    async function selectDevice(dev) {
        activeDevice = dev;
        renderDeviceTabs();

        deviceMetaEl.textContent = `Controller: ${dev.title} (${dev.ip}) • Model: S-Play ${dev.model} • Universes: ${dev.universes}`;
        modelHintEl.textContent = dev.isMini
            ? 'S-Play Mini: Fixed 16 Slots (1–15 Editable, Slot 0 Blackout Locked, Delete Disabled)'
            : 'S-Play SP1: Full Slot Creation & Deletion Enabled';

        renderUniverseCheckboxes(dev.universes);
        await loadPlaylists();
    }

    function renderUniverseCheckboxes(count) {
        universeBoxEl.innerHTML = '';
        for (let i = 0; i < count; i++) {
            const label = document.createElement('label');
            label.className = 'universe-pill';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = String(i);
            cb.checked = (i === 0);
            label.appendChild(cb);
            label.appendChild(document.createTextNode(`Universe ${i + 1} (ID ${i})`));
            universeBoxEl.appendChild(label);
        }
    }

    function getSelectedUniverses() {
        const checked = Array.from(universeBoxEl.querySelectorAll('input:checked')).map((el) => parseInt(el.value, 10));
        return checked.length > 0 ? checked : [0];
    }

    async function loadPlaylists() {
        scenesGridEl.innerHTML = '<div style="color:var(--text-muted);">Loading scenes from S-Play...</div>';
        try {
            const data = await apiGet(`/api/enttec/playlists?device_id=${activeDevice.id}`);
            playlists = data.playlists || [];
            renderSceneCards();
            renderSlotDropdown();
        } catch (err) {
            scenesGridEl.innerHTML = `<div style="color:#fca5a5;">Failed to load scenes: ${err.message}</div>`;
        }
    }

    function renderSceneCards() {
        scenesGridEl.innerHTML = '';
        const visibleScenes = playlists.filter((p) => !p.isBlackout && !p.isEmpty);

        if (visibleScenes.length === 0) {
            scenesGridEl.innerHTML = '<div style="color:var(--text-muted);">No lighting scenes recorded yet. Click "Manage / Record Scenes" to capture one.</div>';
            return;
        }

        visibleScenes.forEach((pl) => {
            const card = document.createElement('div');
            card.className = 'scene-card' + (pl.status === 1 ? ' active' : '');
            card.dataset.playlistId = pl.playlist_id;

            card.innerHTML = `
                <span class="scene-slot-badge">Slot ${pl.playlist_id}</span>
                <span class="scene-type-badge">${pl.cue_type === 1 ? 'Dynamic' : 'Static'}</span>
                <div class="scene-title">${escapeHtml(pl.name)}</div>
            `;

            card.addEventListener('click', () => triggerScene(pl, card));
            scenesGridEl.appendChild(card);
        });
    }

    async function triggerScene(pl, cardEl) {
        scenesGridEl.querySelectorAll('.scene-card').forEach((c) => c.classList.remove('active'));
        cardEl.classList.add('active');

        try {
            await apiPost('/api/enttec/play', {
                device_id: activeDevice.id,
                playlist_id: pl.playlist_id,
                playlist_uuid: pl.playlist_uuid,
                name: pl.name,
            });
            showToast(`Activated: ${pl.name}`);
        } catch (err) {
            cardEl.classList.remove('active');
            showToast(err.message, true);
        }
    }
    function renderSlotDropdown() {
        slotSelectEl.innerHTML = '';
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = '-- Select Slot --';
        placeholder.disabled = true;
        placeholder.selected = true;
        slotSelectEl.appendChild(placeholder);

        if (activeDevice.isMini) {
            for (let slotId = 1; slotId <= 15; slotId++) {
                const existing = playlists.find((p) => p.playlist_id === slotId);
                const opt = document.createElement('option');
                opt.value = String(slotId);
                opt.textContent = existing && !existing.isEmpty
                    ? `Slot ${slotId}: ${existing.name}`
                    : `Slot ${slotId}: (Empty Slot)`;
                slotSelectEl.appendChild(opt);
            }
        } else {
            let maxId = 0;
            playlists.forEach((pl) => {
                if (pl.playlist_id > maxId) maxId = pl.playlist_id;
                if (pl.isBlackout) return;
                const opt = document.createElement('option');
                opt.value = String(pl.playlist_id);
                opt.textContent = pl.isEmpty
                    ? `Slot ${pl.playlist_id}: (Empty Slot)`
                    : `Slot ${pl.playlist_id}: ${pl.name}`;
                slotSelectEl.appendChild(opt);
            });

            const newSlotId = maxId + 1;
            const createOpt = document.createElement('option');
            createOpt.value = String(newSlotId);
            createOpt.textContent = `+ Create New Scene (Slot ${newSlotId})`;
            slotSelectEl.appendChild(createOpt);
        }

        updateRecorderControls();
    }

    function updateRecorderControls() {
        const slotVal = slotSelectEl.value;
        const hasSlot = slotVal !== '';
        const slotId = parseInt(slotVal, 10);
        const existing = playlists.find((p) => p.playlist_id === slotId);

        const isDynamic = cueTypeSelectEl.value === '1';
        recordBtn.textContent = isDynamic ? 'Start Dynamic Recording' : 'Record Static Preset';

        if (hasSlot && !activeDevice.isMini && existing && !existing.isEmpty) {
            deleteBtn.style.display = 'inline-block';
        } else {
            deleteBtn.style.display = 'none';
        }
    }

    slotSelectEl.addEventListener('change', () => {
        const slotId = parseInt(slotSelectEl.value, 10);
        const existing = playlists.find((p) => p.playlist_id === slotId);

        if (existing && !existing.isEmpty) {
            nameInputEl.value = existing.name;
            cueTypeSelectEl.value = String(existing.cue_type ?? 0);
            sourceTypeSelectEl.value = String(existing.source ?? 0);
        } else {
            nameInputEl.value = '';
            cueTypeSelectEl.value = '0';
        }
        updateRecorderControls();
    });

    cueTypeSelectEl.addEventListener('change', updateRecorderControls);

    document.getElementById('btn-toggle-recorder').addEventListener('click', () => {
        drawerEl.classList.toggle('open');
    });

    document.getElementById('btn-blackout').addEventListener('click', async () => {
        if (!activeDevice) return;
        scenesGridEl.querySelectorAll('.scene-card').forEach((c) => c.classList.remove('active'));
        try {
            await apiPost('/api/enttec/blackout', { device_id: activeDevice.id });
            showToast('Blackout Activated (Slot 0)');
        } catch (err) {
            showToast(err.message, true);
        }
    });

    recordBtn.addEventListener('click', async () => {
        const slotVal = slotSelectEl.value;
        if (!slotVal) {
            showToast('Please select a playlist slot first.', true);
            return;
        }

        const playlistId = parseInt(slotVal, 10);
        const existing = playlists.find((p) => p.playlist_id === playlistId);
        const nowEpoch = Math.floor(Date.now() / 1000);

        const cueId = existing && !existing.isEmpty ? existing.cue_id : playlistId;
        const cueUuid = existing && !existing.isEmpty ? existing.cue_uuid : nowEpoch;
        const playlistUuid = existing && !existing.isEmpty ? existing.playlist_uuid : nowEpoch;
        const name = nameInputEl.value.trim() || `Scene ${playlistId}`;
        const cueType = parseInt(cueTypeSelectEl.value, 10);
        const sourceType = parseInt(sourceTypeSelectEl.value, 10);
        const universes = getSelectedUniverses();

        if (cueType === 0) {
            recordBtn.disabled = true;
            try {
                await apiPost('/api/enttec/recordStatic', {
                    device_id: activeDevice.id,
                    playlist_id: playlistId,
                    playlist_uuid: playlistUuid,
                    cue_id: cueId,
                    cue_uuid: cueUuid,
                    name,
                    source_type: sourceType,
                    universes,
                });
                showToast(`Saved static scene "${name}" to Slot ${playlistId}`);
                await loadPlaylists();
            } catch (err) {
                showToast(err.message, true);
            } finally {
                recordBtn.disabled = false;
            }
        } else {
            try {
                await apiPost('/api/enttec/armDynamic', {
                    device_id: activeDevice.id,
                    cue_id: cueId,
                    cue_uuid: cueUuid,
                    source_type: sourceType,
                    universes,
                });

                const startTime = Date.now();
                recordBtn.style.display = 'none';
                deleteBtn.style.display = 'none';
                stopBtn.style.display = 'inline-block';

                const timerInterval = setInterval(() => {
                    timerEl.textContent = formatDuration(Date.now() - startTime);
                }, 100);

                const timeoutHandle = setTimeout(() => {
                    stopDynamicCapture();
                }, 60000);

                recordingState = {
                    playlistId, playlistUuid, cueId, cueUuid, name, sourceType, universes,
                    startTime, timerInterval, timeoutHandle,
                };
            } catch (err) {
                showToast(err.message, true);
            }
        }
    });

    async function stopDynamicCapture() {
        if (!recordingState) return;
        const state = recordingState;
        recordingState = null;

        clearInterval(state.timerInterval);
        clearTimeout(state.timeoutHandle);

        stopBtn.disabled = true;
        const durationMs = Date.now() - state.startTime;

        try {
            await apiPost('/api/enttec/stopDynamic', {
                device_id: activeDevice.id,
                playlist_id: state.playlistId,
                playlist_uuid: state.playlistUuid,
                cue_id: state.cueId,
                cue_uuid: state.cueUuid,
                name: state.name,
                source_type: state.sourceType,
                universes: state.universes,
                duration_ms: durationMs,
            });
            showToast(`Saved dynamic scene "${state.name}" (${(durationMs / 1000).toFixed(1)}s)`);
            await loadPlaylists();
        } catch (err) {
            showToast(err.message, true);
        } finally {
            stopBtn.disabled = false;
            stopBtn.style.display = 'none';
            recordBtn.style.display = 'inline-block';
            timerEl.textContent = '00:00.0';
            updateRecorderControls();
        }
    }

    stopBtn.addEventListener('click', stopDynamicCapture);

    deleteBtn.addEventListener('click', async () => {
        if (activeDevice.isMini) return;
        const slotId = parseInt(slotSelectEl.value, 10);
        const existing = playlists.find((p) => p.playlist_id === slotId);
        if (!existing) return;

        if (!window.confirm(`Delete playlist "${existing.name}" (Slot ${slotId}) from ${activeDevice.title}?`)) {
            return;
        }

        deleteBtn.disabled = true;
        try {
            await apiPost('/api/enttec/deletePlaylist', {
                device_id: activeDevice.id,
                playlist_id: existing.playlist_id,
                cue_id: existing.cue_id,
            });
            showToast(`Deleted "${existing.name}"`);
            nameInputEl.value = '';
            await loadPlaylists();
        } catch (err) {
            showToast(err.message, true);
        } finally {
            deleteBtn.disabled = false;
        }
    });

    function formatDuration(ms) {
        const totalSec = Math.floor(ms / 1000);
        const min = String(Math.floor(totalSec / 60)).padStart(2, '0');
        const sec = String(totalSec % 60).padStart(2, '0');
        const tenths = Math.floor((ms % 1000) / 100);
        return `${min}:${sec}.${tenths}`;
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    init();
})();
