const rcState = {
    csrfToken: document.body.dataset.csrf || '',
    devices: [],
    activeDeviceId: 0,
    onlineMap: {},
    switchingMode: null, // 'studioLeft' | 'instant' | null
    activeScene: null,
    activeInput: null,
    activeMedia: null,
    activeOverlays: new Set()
};

function showToast(message, isError = false) {
    if (isError) console.error('RØDECaster Panel Error:', message);
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = isError ? 'show error' : 'show';
    toast.style.pointerEvents = 'auto';
    toast.onclick = () => { toast.className = ''; };
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.className = ''; }, isError ? 15000 : 2500);
}

async function parseJsonSafe(res) {
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        throw new Error('HTTP ' + res.status + ': ' + text.slice(0, 120));
    }
}

async function loadRodeCasterStatus() {
    try {
        const res = await fetch('/api/rodecaster/status');
        if (res.status === 401) { window.location.href = '/panel'; return; }
        const data = await parseJsonSafe(res);
        if (!data.success) throw new Error(data.error || 'Failed to load RØDECaster config');

        rcState.csrfToken = data.csrfToken || rcState.csrfToken;
        rcState.devices = data.devices || [];
        if (!rcState.activeDeviceId && rcState.devices.length > 0) {
            rcState.activeDeviceId = rcState.devices[0].id;
        }
        renderRodeCaster();
    } catch (err) {
        document.getElementById('connectionDot').style.backgroundColor = '#ef4444';
        showToast(err.message || 'Unable to reach RØDECaster API', true);
    }
}

async function loadRodeCasterPing() {
    try {
        const res = await fetch('/api/rodecaster/ping');
        if (!res.ok) return;
        const data = await parseJsonSafe(res);
        if (data.success && data.online) {
            rcState.onlineMap = data.online;
            renderDeviceHeader();
        }
    } catch (e) {
        // Ignore transient ping errors
    }
}

async function triggerRodeCaster(action, index, label) {
    if (!rcState.activeDeviceId) {
        showToast('No RØDECaster Video configured in CMS', true);
        return;
    }

    try {
        const res = await fetch('/api/rodecaster/trigger', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': rcState.csrfToken
            },
            body: JSON.stringify({
                device_id: rcState.activeDeviceId,
                action: action,
                index: index
            })
        });
        const data = await parseJsonSafe(res);
        if (!data.success) {
            showToast(data.error || 'OSC command failed', true);
            return;
        }

        if (data.result && data.result.mode) {
            rcState.switchingMode = data.result.mode;
        } else if (action === 'instant') {
            rcState.switchingMode = 'instant';
        } else if (action === 'studio') {
            rcState.switchingMode = 'studioLeft';
        } else if (action === 'scene') {
            rcState.activeScene = index;
            rcState.activeInput = null;
        } else if (action === 'input') {
            rcState.activeInput = index;
            rcState.activeScene = null;
        } else if (action === 'media') {
            rcState.activeMedia = index;
        } else if (action === 'overlay') {
            rcState.activeOverlays.has(index)
                ? rcState.activeOverlays.delete(index)
                : rcState.activeOverlays.add(index);
        }

        renderRodeCaster();
        showToast('Triggered ' + label);
    } catch (err) {
        showToast(err.message || 'Network error sending OSC command', true);
    }
}

function getActiveDevice() {
    return rcState.devices.find(d => d.id === rcState.activeDeviceId) || null;
}

function renderModeControls() {
    const studioBtn = document.getElementById('btnModeStudio');
    const instantBtn = document.getElementById('btnModeInstant');
    if (!studioBtn || !instantBtn) return;

    const isInstant = rcState.switchingMode === 'instant';
    const isStudio = rcState.switchingMode && rcState.switchingMode !== 'instant';

    studioBtn.classList.toggle('active', Boolean(isStudio));
    instantBtn.classList.toggle('active', Boolean(isInstant));
}

function renderDeviceHeader() {
    const tabs = document.getElementById('rcDeviceTabs');
    const statusWrap = document.getElementById('rcDeviceStatus');
    tabs.innerHTML = '';
    statusWrap.innerHTML = '';

    rcState.devices.forEach(dev => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'room-tab' + (dev.id === rcState.activeDeviceId ? ' active' : '');
        btn.textContent = dev.title;
        btn.onclick = () => {
            rcState.activeDeviceId = dev.id;
            rcState.switchingMode = null;
            rcState.activeScene = null;
            rcState.activeInput = null;
            rcState.activeMedia = null;
            rcState.activeOverlays.clear();
            renderRodeCaster();
        };
        tabs.appendChild(btn);
    });

    const activeDev = getActiveDevice();
    if (activeDev) {
        const isOnline = rcState.onlineMap[activeDev.id];
        const badge = document.createElement('span');
        badge.className = 'ping-badge';

        const dot = document.createElement('span');
        dot.className = 'ping-dot' + (isOnline === true ? ' online' : (isOnline === false ? ' offline' : ''));

        const text = document.createElement('span');
        text.textContent = activeDev.ip + ':' + activeDev.port + (isOnline === true ? ' • ONLINE' : (isOnline === false ? ' • OFFLINE' : ''));

        badge.append(dot, text);
        statusWrap.appendChild(badge);
    }
}

function renderPadGrid(containerId, items, action, isActiveFn, extraClass = '') {
    const container = document.getElementById(containerId);
    container.innerHTML = '';

    if (!items || items.length === 0) {
        container.innerHTML = '<div class="hint">No RØDECaster Video configured in /admin</div>';
        return;
    }

    items.forEach(item => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rc-pad-btn ' + extraClass + (isActiveFn(item.index) ? ' active' : '');

        const idxEl = document.createElement('div');
        idxEl.className = 'rc-pad-index';
        idxEl.textContent = action.toUpperCase() + ' ' + item.index;

        const labelEl = document.createElement('div');
        labelEl.className = 'rc-pad-label';
        labelEl.textContent = item.label;

        btn.append(idxEl, labelEl);
        btn.onclick = () => triggerRodeCaster(action, item.index, item.label);
        container.appendChild(btn);
    });
}

function renderRodeCaster() {
    renderDeviceHeader();
    renderModeControls();
    const dev = getActiveDevice();

    renderPadGrid('rcScenesGrid', dev ? dev.scenes : [], 'scene', idx => rcState.activeScene === idx);
    renderPadGrid('rcInputsGrid', dev ? dev.inputs : [], 'input', idx => rcState.activeInput === idx);
    renderPadGrid('rcOverlaysGrid', dev ? dev.overlays : [], 'overlay', idx => rcState.activeOverlays.has(idx), 'overlay-btn');
    renderPadGrid('rcMediaGrid', dev ? dev.media : [], 'media', idx => rcState.activeMedia === idx);
}

loadRodeCasterStatus().then(() => loadRodeCasterPing());
setInterval(loadRodeCasterPing, 15000);
