<script>
const state = {
    csrfToken: '',
    locations: [],
    encoders: [],
    decoders: [],
    presets: [],
    onlineMap: {},
    activeLocationId: 0,
    activeStreamType: 'all',
    selectedDecoderIds: new Set()
};


function showToast(message, isError = false) {
    if (isError) console.error('VPX Panel Error:', message);
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = isError ? 'show error' : 'show';
    toast.style.pointerEvents = 'auto';
    toast.onclick = () => { toast.className = ''; };
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.className = ''; }, isError ? 30000 : 3000);
}


async function parseJsonSafe(res) {
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        throw new Error('HTTP ' + res.status + ': ' + text.slice(0, 120));
    }
}

async function apiPost(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': state.csrfToken },
        body: JSON.stringify(payload)
    });
}

async function loadStatus() {
    try {
        const res = await fetch('/api/vpx/status');
        if (res.status === 401) { window.location.href = '/panel'; return; }
        const data = await parseJsonSafe(res);
        if (!data.success) throw new Error('Failed to load status');

        state.csrfToken = data.csrfToken || '';
        state.locations = data.locations || [];
        state.encoders = data.encoders || [];
        state.decoders = data.decoders || [];
        state.presets = data.presets || [];

        if (state.selectedDecoderIds.size === 0 && state.decoders.length > 0) {
            state.selectedDecoderIds.add(state.decoders.at(0).id);
        }
        render();
    } catch (err) {
        document.getElementById('connectionDot').style.backgroundColor = '#ef4444';
        showToast(err.message || 'Unable to reach VPX API server', true);
    }
}

async function loadPingStatus() {
    try {
        const res = await fetch('/api/vpx/ping');
        if (!res.ok) return;
        const data = await parseJsonSafe(res);
        if (data.success && data.online) {
            state.onlineMap = data.online;
            renderDecoders();
            renderEncoders();
        }
    } catch (e) {
        // Ignore transient ping errors
    }
}


async function routeToSelected(encoderId, encoderTitle) {
    if (state.selectedDecoderIds.size === 0) {
        showToast('Select at least one Display on the left first', true);
        return;
    }
    const targets = Array.from(state.selectedDecoderIds);
    const errors = [];

    for (const decoderId of targets) {
        try {
            const res = await apiPost('/api/vpx/route', {
                decoder_id: decoderId, encoder_id: encoderId, stream_type: state.activeStreamType
            });
            const data = await parseJsonSafe(res);
            if (!data.success) errors.push(data.error || 'Routing failed');
        } catch (e) {
            errors.push(e.message || 'Network error');
        }
    }

    await loadStatus();
    errors.length > 0
        ? showToast('Error: ' + errors.at(0), true)
        : showToast('Routed ' + encoderTitle + ' (' + state.activeStreamType.toUpperCase() + ') to ' + targets.length + ' display(s)');
}

async function disconnectDecoder(decoderId, decoderTitle) {
    try {
        const res = await apiPost('/api/vpx/route', {
            decoder_id: decoderId,
            encoder_id: 0,
            stream_type: 'all'
        });
        const data = await parseJsonSafe(res);
        await loadStatus();
        if (data.success) {
            showToast(decoderTitle + ': Disconnected all streams');
        } else {
            showToast('Disconnect Error: ' + (data.error || 'Failed'), true);
        }
    } catch (e) {
        showToast(e.message || 'Failed to disconnect display', true);
    }
}

async function sendCEC(deviceId, cecCommand, deviceTitle) {
    try {
        const res = await apiPost('/api/vpx/command', { device_id: deviceId, command: cecCommand });
        const data = await parseJsonSafe(res);
        data.success ? showToast(deviceTitle + ': Sent "' + cecCommand + '"') : showToast('CEC Error: ' + (data.error || 'Failed'), true);
    } catch (e) {
        showToast(e.message || 'Failed to send CEC command', true);
    }
}

async function triggerPreset(presetId, presetTitle) {
    try {
        const res = await apiPost('/api/vpx/preset', { preset_id: presetId });
        const data = await parseJsonSafe(res);
        await loadStatus();
        if (data.success) {
            showToast('Activated preset: ' + presetTitle);
        } else {
            const errList = data.result && data.result.errors ? data.result.errors : [];
            showToast('Preset partial error: ' + (errList.at(0) || data.error || 'Preset error'), true);
        }
    } catch (e) {
        showToast(e.message || 'Failed to trigger preset', true);
    }
}

async function powerAllVisibleDisplays(turnOn) {
    const visibleDecoders = state.decoders.filter(d =>
        state.activeLocationId === 0 || d.locationId === state.activeLocationId
    );

    if (visibleDecoders.length === 0) {
        showToast('No displays in this room to control', true);
        return;
    }

    const errors = [];
    for (const dec of visibleDecoders) {
        const cmd = turnOn
            ? (dec.powerOnCommand || 'cec power on')
            : (dec.powerOffCommand || 'cec power off');
        try {
            const res = await apiPost('/api/vpx/command', { device_id: dec.id, command: cmd });
            const data = await parseJsonSafe(res);
            if (!data.success) errors.push(dec.title + ': ' + (data.error || 'Failed'));
        } catch (e) {
            errors.push(dec.title + ': ' + (e.message || 'Network error'));
        }
    }

    const roomLabel = state.activeLocationId === 0 ? 'All Rooms' : getLocationName(state.activeLocationId);
    const actionLabel = turnOn ? 'ON' : 'OFF';

    if (errors.length > 0) {
        showToast('Error: ' + errors.at(0), true);
    } else {
        showToast('Sent Power ' + actionLabel + ' to ' + visibleDecoders.length + ' display(s) (' + roomLabel + ')');
    }
}
</script>
