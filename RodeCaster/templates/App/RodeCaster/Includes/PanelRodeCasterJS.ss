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
    optimisticLocks: {
        scene: 0,
        input: 0,
        overlay: 0,
        media: 0,
        mode: 0,
    },
};

let rcPingInFlight = false;
let rcTriggerInFlight = false;
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

function hasActiveSelection(map) {
    if (!map || typeof map !== 'object') return false;
    return Object.values(map).some((v) => v === 'state-red' || v === 'state-green');
}

function applyHardwarePadState(deviceKey) {
    const hw = rcState.padStateMap[deviceKey];
    if (!hw) return;

    const now = Date.now();

    if (hw.scenes && now > rcState.optimisticLocks.scene) {
        if (hasActiveSelection(hw.scenes) || !hasActiveSelection(rcState.sceneStates)) {
            rcState.sceneStates = { ...hw.scenes };
        }
    }
    if (hw.inputs && now > rcState.optimisticLocks.input) {
        if (hasActiveSelection(hw.inputs) || !hasActiveSelection(rcState.inputStates)) {
            rcState.inputStates = { ...hw.inputs };
        }
    }
    if (hw.overlays && now > rcState.optimisticLocks.overlay) {
        if (hasActiveSelection(hw.overlays) || !hasActiveSelection(rcState.overlayStates)) {
            rcState.overlayStates = { ...hw.overlays };
        }
    }
    if (hw.media && now > rcState.optimisticLocks.media) {
        if (hasActiveSelection(hw.media) || !hasActiveSelection(rcState.mediaStates)) {
            rcState.mediaStates = { ...hw.media };
        }
    }
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
    rcState.renderedDeviceId = 0;
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
    if (rcPingInFlight || rcTriggerInFlight || document.hidden) return;
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
                if (activeKey !== '0' && data.modes[activeKey] && Date.now() > rcState.optimisticLocks.mode) {
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

function promotePreviewToProgram() {
    Object.keys(rcState.sceneStates).forEach((k) => {
        if (rcState.sceneStates[k] === 'state-red') rcState.sceneStates[k] = 'state-white';
        if (rcState.sceneStates[k] === 'state-green') rcState.sceneStates[k] = 'state-red';
    });
    Object.keys(rcState.inputStates).forEach((k) => {
        if (rcState.inputStates[k] === 'state-red') rcState.inputStates[k] = 'state-white';
        if (rcState.inputStates[k] === 'state-green') rcState.inputStates[k] = 'state-red';
    });
    Object.keys(rcState.overlayStates).forEach((k) => {
        if (rcState.overlayStates[k] === 'state-green') rcState.overlayStates[k] = 'state-red';
    });
    Object.keys(rcState.mediaStates).forEach((k) => {
        if (rcState.mediaStates[k] === 'state-green') rcState.mediaStates[k] = 'state-red';
    });
}

window.triggerRodeCaster = async function (action, index, label = '') {
    const idxKey = String(index);
    const holdUntil = Date.now() + 2500;

    if (action === 'studio') {
        rcState.switchingMode = 'studio';
        rcState.optimisticLocks.mode = holdUntil;
        renderRodeCaster();
    } else if (action === 'instant') {
        rcState.switchingMode = 'instant';
        rcState.optimisticLocks.mode = holdUntil;
        renderRodeCaster();
    } else if (action === 'cut' || action === 'auto') {
        promotePreviewToProgram();
        rcState.optimisticLocks.scene = holdUntil;
        rcState.optimisticLocks.input = holdUntil;
        rcState.optimisticLocks.overlay = holdUntil;
        rcState.optimisticLocks.media = holdUntil;
        renderRodeCaster();
    } else if (action === 'scene') {
        const activeClass = isStudioMode() ? 'state-green' : 'state-red';
        Object.keys(rcState.sceneStates).forEach((k) => {
            if (rcState.sceneStates[k] === activeClass) {
                rcState.sceneStates[k] = 'state-white';
            }
        });
        if (!isStudioMode()) {
            Object.keys(rcState.inputStates).forEach((k) => {
                if (rcState.inputStates[k] === 'state-red') {
                    rcState.inputStates[k] = 'state-white';
                }
            });
        }
        if (rcState.sceneStates[idxKey] !== 'state-dim') {
            rcState.sceneStates[idxKey] = activeClass;
        }
        rcState.optimisticLocks.scene = holdUntil;
        renderRodeCaster();
    } else if (action === 'input') {
        const activeClass = isStudioMode() ? 'state-green' : 'state-red';
        Object.keys(rcState.inputStates).forEach((k) => {
            if (rcState.inputStates[k] === activeClass) {
                rcState.inputStates[k] = 'state-white';
            }
        });
        if (!isStudioMode()) {
            Object.keys(rcState.sceneStates).forEach((k) => {
                if (rcState.sceneStates[k] === 'state-red') {
                    rcState.sceneStates[k] = 'state-white';
                }
            });
        }
        if (rcState.inputStates[idxKey] !== 'state-dim') {
            rcState.inputStates[idxKey] = activeClass;
        }
        rcState.optimisticLocks.input = holdUntil;
        renderRodeCaster();
    } else if (action === 'overlay') {
        if (rcState.overlayStates[idxKey] !== 'state-dim') {
            rcState.overlayStates[idxKey] = rcState.overlayStates[idxKey] === 'state-red' ? 'state-blue' : 'state-red';
        }
        rcState.optimisticLocks.overlay = holdUntil;
        renderRodeCaster();
    } else if (action === 'media') {
        if (rcState.mediaStates[idxKey] !== 'state-dim') {
            const wasActive = rcState.mediaStates[idxKey] === 'state-red';
            Object.keys(rcState.mediaStates).forEach((k) => {
                if (rcState.mediaStates[k] === 'state-red') {
                    rcState.mediaStates[k] = 'state-pink';
                }
            });
            rcState.mediaStates[idxKey] = wasActive ? 'state-pink' : 'state-red';
        }
        rcState.optimisticLocks.media = holdUntil;
        renderRodeCaster();
    }

    rcTriggerInFlight = true;
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
    } catch (e) {
        showRcToast('Network error sending command', true);
    } finally {
        rcTriggerInFlight = false;
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
                tab.className = `device-tab ${d.id === dev.id ? 'active' : ''}`;
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
    setInterval(loadRodeCasterPing, 2000);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRodeCaster);
} else {
    initRodeCaster();
}

document.addEventListener('visibilitychange', () => {
    if (!document.hidden) loadRodeCasterPing();
});

let activeRcLocationId = 0;

function applyRodeCasterRoomFilter(locId) {
    activeRcLocationId = Number(locId) || 0;
    document.querySelectorAll('.unified-room-tabs .room-tab').forEach(b => {
        b.classList.toggle('active', Number(b.dataset.locationId || 0) === activeRcLocationId);
    });

    const filtered = activeRcLocationId === 0
        ? rcState.devices
        : rcState.devices.filter(d => Number(d.locationId || 0) === activeRcLocationId);

    const devTabs = document.getElementById('rcDeviceTabs');
    if (devTabs) {
        Array.from(devTabs.children).forEach((tabBtn, idx) => {
            const dev = rcState.devices[idx];
            tabBtn.style.display = (!dev || activeRcLocationId === 0 || Number(dev.locationId || 0) === activeRcLocationId) ? '' : 'none';
        });
    }

    const transportBar = document.querySelector('.rc-transport-bar');
    const routingCols = document.querySelector('.routing-columns');
    const mainEl = document.querySelector('.panel-main');
    let emptyBanner = document.getElementById('rcEmptyRoomBanner');
    if (!emptyBanner && mainEl) {
        emptyBanner = document.createElement('div');
        emptyBanner.id = 'rcEmptyRoomBanner';
        emptyBanner.className = 'dsp-card';
        emptyBanner.style.cssText = 'display:none; color:var(--text-muted); margin-top:12px; padding:20px;';
        emptyBanner.textContent = 'No RØDECaster units assigned to this room.';
        mainEl.appendChild(emptyBanner);
    }

    if (filtered.length === 0) {
        rcState.activeDeviceId = 0;
        rcState.renderedDeviceId = 0;
        if (transportBar) transportBar.style.display = 'none';
        if (routingCols) routingCols.style.display = 'none';
        if (emptyBanner) emptyBanner.style.display = '';
        return;
    }

    if (transportBar) transportBar.style.display = 'flex';
    if (routingCols) routingCols.style.display = '';
    if (emptyBanner) emptyBanner.style.display = 'none';

    if (!rcState.activeDeviceId || !filtered.some(d => d.id === rcState.activeDeviceId)) {
        if (typeof window.selectRodeCasterDevice === 'function') {
            window.selectRodeCasterDevice(filtered[0].id);
        } else {
            rcState.activeDeviceId = filtered[0].id;
            rcState.renderedDeviceId = 0;
            if (typeof renderRodeCaster === 'function') renderRodeCaster();
        }
    } else if (typeof renderRodeCaster === 'function') {
        renderRodeCaster();
    }
}

document.addEventListener('click', (e) => {
    const rBtn = e.target.closest('.unified-room-tabs .room-tab');
    if (!rBtn) return;
    applyRodeCasterRoomFilter(parseInt(rBtn.dataset.locationId || '0', 10));
});
</script>
