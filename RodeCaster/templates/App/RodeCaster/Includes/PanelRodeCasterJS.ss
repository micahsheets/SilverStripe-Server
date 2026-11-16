<script>
const rcState = {
    devices: [],
    activeDeviceId: 0,
    renderedDeviceId: 0,
    onlineMap: {},
    modeMap: {},
    padStateMap: {},
    switchingMode: 'instant',
    sceneStates: {},
    inputStates: {},
    overlayStates: {},
    mediaStates: {},
};

let rcPingInFlight = false;
let rcVolDragging = false;
let rcVolTimer = null;

async function parseJsonSafe(res) {
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        return { success: false, error: `HTTP ${res.status}: ${text.slice(0, 120)}` };
    }
}

function isStudioMode() {
    const mode = String(rcState.switchingMode || '').toLowerCase();
    return mode.startsWith('studio');
}

function showRcToast(message, isError = false) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = message;
    toast.className = 'toast show' + (isError ? ' error' : '');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => {
        toast.className = 'toast';
    }, 2400);
}

function applyHardwarePadState(deviceKey) {
    const hw = rcState.padStateMap[deviceKey];
    if (!hw) return;

    if (hw.scenes) rcState.sceneStates = { ...hw.scenes };
    if (hw.inputs) rcState.inputStates = { ...hw.inputs };
    if (hw.overlays) rcState.overlayStates = { ...hw.overlays };
    if (hw.media) rcState.mediaStates = { ...hw.media };
}

function updateVolumeUI(percent) {
    const slider = document.getElementById('rc-master-vol');
    const readout = document.getElementById('rc-master-vol-val');
    if (slider && !rcVolDragging) slider.value = percent;
    if (readout) readout.textContent = `${percent}%`;
}

function initMasterVolumeSlider() {
    const slider = document.getElementById('rc-master-vol');
    if (!slider || slider.dataset.bound === '1') return;
    slider.dataset.bound = '1';

    slider.addEventListener('pointerdown', () => { rcVolDragging = true; });
    window.addEventListener('pointerup', () => { rcVolDragging = false; });

    slider.addEventListener('input', (e) => {
        const val = parseInt(e.target.value, 10) || 0;
        const readout = document.getElementById('rc-master-vol-val');
        if (readout) readout.textContent = `${val}%`;

        clearTimeout(rcVolTimer);
        rcVolTimer = setTimeout(() => {
            window.triggerRodeCaster('volume', val, `HDMI / USB Mix ${val}%`);
        }, 60);
    });
}

window.selectRodeCasterDevice = function (deviceId) {
    rcState.activeDeviceId = deviceId;
    rcState.renderedDeviceId = 0; // Force full grid rebuild for newly selected device
    const key = String(deviceId);
    if (rcState.modeMap[key]) {
        rcState.switchingMode = rcState.modeMap[key];
    }
    applyHardwarePadState(key);
    renderRodeCaster();
};

async function loadRodeCasterStatus() {
    try {
        const res = await fetch('/api/rodecaster/status', { credentials: 'same-origin' });
        if (!res.ok) {
            showRcToast(`Status error: HTTP ${res.status}`, true);
            return;
        }
        const data = await parseJsonSafe(res);
        if (data.success && Array.isArray(data.devices)) {
            rcState.devices = data.devices;
            if (rcState.activeDeviceId === 0 && rcState.devices.length > 0) {
                rcState.activeDeviceId = rcState.devices[0].id;
            }
            rcState.renderedDeviceId = 0;
            renderRodeCaster();
        }
    } catch (e) {
        showRcToast('Failed to load RØDECaster layout', true);
    }
}

async function loadRodeCasterPing() {
    if (rcPingInFlight || document.hidden) return;
    rcPingInFlight = true;
    try {
        const res = await fetch('/api/rodecaster/ping', { credentials: 'same-origin' });
        if (!res.ok) return;
        const data = await parseJsonSafe(res);
        if (data.success) {
            const activeKey = String(rcState.activeDeviceId);
            if (data.online) rcState.onlineMap = data.online;
            if (data.modes) {
                rcState.modeMap = data.modes;
                if (activeKey !== '0' && data.modes[activeKey]) {
                    rcState.switchingMode = data.modes[activeKey];
                }
            }
            if (data.states) {
                rcState.padStateMap = data.states;
                if (activeKey !== '0') {
                    applyHardwarePadState(activeKey);
                }
            }
            if (data.volumes && activeKey !== '0' && typeof data.volumes[activeKey] === 'number') {
                updateVolumeUI(data.volumes[activeKey]);
            }
            renderRodeCaster();
        }
    } catch (e) {
        // Ignore transient ping errors
    } finally {
        rcPingInFlight = false;
    }
}

window.triggerRodeCaster = async function (action, index, label = '') {
    const idxKey = String(index);

    if (action === 'studio') {
        rcState.switchingMode = 'studio';
        renderRodeCaster();
    } else if (action === 'instant') {
        rcState.switchingMode = 'instant';
        renderRodeCaster();
    } else if (action === 'scene') {
        const activeClass = isStudioMode() ? 'state-green' : 'state-red';
        Object.keys(rcState.sceneStates).forEach((k) => {
            if (rcState.sceneStates[k] === activeClass) {
                rcState.sceneStates[k] = 'state-white';
            }
        });
        if (rcState.sceneStates[idxKey] !== 'state-dim') {
            rcState.sceneStates[idxKey] = activeClass;
        }
        renderRodeCaster();
    } else if (action === 'input') {
        const activeClass = isStudioMode() ? 'state-green' : 'state-red';
        Object.keys(rcState.inputStates).forEach((k) => {
            if (rcState.inputStates[k] === activeClass) {
                rcState.inputStates[k] = 'state-white';
            }
        });
        if (rcState.inputStates[idxKey] !== 'state-dim') {
            rcState.inputStates[idxKey] = activeClass;
        }
        renderRodeCaster();
    } else if (action === 'overlay') {
        if (rcState.overlayStates[idxKey] !== 'state-dim') {
            rcState.overlayStates[idxKey] = rcState.overlayStates[idxKey] === 'state-red' ? 'state-blue' : 'state-red';
        }
        renderRodeCaster();
    } else if (action === 'media') {
        if (rcState.mediaStates[idxKey] !== 'state-dim') {
            rcState.mediaStates[idxKey] = rcState.mediaStates[idxKey] === 'state-red' ? 'state-pink' : 'state-red';
        }
        renderRodeCaster();
    }

    try {
        const csrf = document.body?.dataset?.csrf || '';
        const res = await fetch('/api/rodecaster/trigger', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({
                deviceId: rcState.activeDeviceId || 1,
                action,
                index,
            }),
        });
        const data = await parseJsonSafe(res);
        if (!res.ok || !data.success) {
            showRcToast(data.error || `Trigger failed (HTTP ${res.status})`, true);
        } else if (label && action !== 'volume') {
            showRcToast(label);
        }
        setTimeout(loadRodeCasterPing, 200);
    } catch (e) {
        showRcToast('Network error sending command', true);
    }
};

function buildPadGrid(containerId, itemsMap, stateMap, defaultState, action) {
    const el = document.getElementById(containerId);
    if (!el) return;

    const letters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
    const entries = Object.entries(itemsMap || {});
    el.innerHTML = '';

    entries.forEach(([slot, label]) => {
        const idx = parseInt(slot, 10) || 1;
        const badge = action === 'input' ? `IN ${idx}` : (letters[idx - 1] || `PAD ${idx}`);
        const cls = stateMap[String(idx)] || defaultState;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = `rc-pad-btn ${cls}`;
        btn.dataset.slot = String(idx);
        btn.addEventListener('click', () => window.triggerRodeCaster(action, idx, String(label)));

        const idxSpan = document.createElement('span');
        idxSpan.className = 'rc-pad-index';
        idxSpan.textContent = badge;

        const lblSpan = document.createElement('span');
        lblSpan.className = 'rc-pad-label';
        lblSpan.textContent = String(label);

        btn.appendChild(idxSpan);
        btn.appendChild(lblSpan);
        el.appendChild(btn);
    });
}

function updatePadGridStates(containerId, stateMap, defaultState) {
    const el = document.getElementById(containerId);
    if (!el) return;

    el.querySelectorAll('.rc-pad-btn').forEach((btn) => {
        const slot = btn.dataset.slot;
        const cls = stateMap[slot] || defaultState;
        btn.className = `rc-pad-btn ${cls}`;
    });
}

function renderRodeCaster() {
    const dev = rcState.devices.find((d) => d.id === rcState.activeDeviceId) || rcState.devices[0];
    if (!dev) return;

    const needsBuild = rcState.renderedDeviceId !== dev.id;

    if (needsBuild) {
        const tabsEl = document.getElementById('rcDeviceTabs');
        if (tabsEl) {
            tabsEl.innerHTML = '';
            rcState.devices.forEach((d) => {
                const tab = document.createElement('button');
                tab.type = 'button';
                tab.className = `room-pill ${d.id === dev.id ? 'active' : ''}`;
                tab.textContent = d.name;
                tab.addEventListener('click', () => window.selectRodeCasterDevice(d.id));
                tabsEl.appendChild(tab);
            });
        }

        buildPadGrid('rcScenesGrid', dev.scenes, rcState.sceneStates, 'state-white', 'scene');
        buildPadGrid('rcInputsGrid', dev.inputs, rcState.inputStates, 'state-dim', 'input');
        buildPadGrid('rcOverlaysGrid', dev.overlays, rcState.overlayStates, 'state-blue', 'overlay');
        buildPadGrid('rcMediaGrid', dev.media, rcState.mediaStates, 'state-pink', 'media');
        rcState.renderedDeviceId = dev.id;
    } else {
        updatePadGridStates('rcScenesGrid', rcState.sceneStates, 'state-white');
        updatePadGridStates('rcInputsGrid', rcState.inputStates, 'state-dim');
        updatePadGridStates('rcOverlaysGrid', rcState.overlayStates, 'state-blue');
        updatePadGridStates('rcMediaGrid', rcState.mediaStates, 'state-pink');
    }

    const isOnline = Boolean(rcState.onlineMap[String(dev.id)]);
    const dot = document.getElementById('connectionDot');
    if (dot) {
        dot.classList.toggle('online', isOnline);
        dot.classList.toggle('offline', !isOnline);
    }

    const statusEl = document.getElementById('rcDeviceStatus');
    if (statusEl) {
        statusEl.innerHTML = `<span class="status-pill ${isOnline ? 'online' : 'offline'}">${isOnline ? 'ONLINE' : 'OFFLINE'}</span>`;
    }

    const btnStudio = document.getElementById('btnModeStudio');
    const btnInstant = document.getElementById('btnModeInstant');
    if (btnStudio) btnStudio.classList.toggle('active', isStudioMode());
    if (btnInstant) btnInstant.classList.toggle('active', !isStudioMode());

    const transEl = document.getElementById('rcTransitionControls');
    if (transEl) {
        transEl.style.opacity = isStudioMode() ? '1' : '0.65';
    }
}

function initRodeCaster() {
    initMasterVolumeSlider();
    loadRodeCasterStatus().then(() => loadRodeCasterPing());
    setInterval(loadRodeCasterPing, 1000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRodeCaster);
} else {
    initRodeCaster();
}

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) loadRodeCasterPing();
});

document.addEventListener('DOMContentLoaded', () => {
    const btns = Array.from(document.querySelectorAll('.unified-room-tabs .room-tab'));
    btns.forEach(rBtn => {
        rBtn.addEventListener('click', () => {
            const locId = parseInt(rBtn.dataset.locationId || '0', 10);
            btns.forEach(b => b.classList.toggle('active', b === rBtn));
            const filtered = locId === 0 ? rcState.devices : rcState.devices.filter(d => Number(d.locationId || 0) === locId);
            const devTabs = document.getElementById('rcDeviceTabs');
            if (devTabs) {
                Array.from(devTabs.children).forEach((tabBtn, idx) => {
                    const dev = rcState.devices[idx];
                    tabBtn.style.display = (!dev || locId === 0 || Number(dev.locationId || 0) === locId) ? '' : 'none';
                });
            }
            if (filtered.length > 0 && (!rcState.activeDeviceId || (locId !== 0 && !filtered.some(d => d.id === rcState.activeDeviceId)))) {
                rcState.activeDeviceId = filtered[0].id;
                if (typeof renderRodeCaster === 'function') renderRodeCaster();
            }
        });
    });
});
</script>
