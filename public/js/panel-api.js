const state = {
    csrfToken: '',
    locations: [],
    encoders: [],
    decoders: [],
    presets: [],
    activeLocationId: 0,
    activeStreamType: 'all',
    selectedDecoderIds: new Set()
};

function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = isError ? 'show error' : 'show';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.className = ''; }, 2800);
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
        const data = await res.json();
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
        showToast('Unable to reach VPX API server', true);
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
            const data = await res.json();
            if (!data.success) errors.push(data.error || 'Routing failed');
        } catch (e) { errors.push('Network error'); }
    }

    await loadStatus();
    errors.length > 0
        ? showToast('Error: ' + errors.at(0), true)
        : showToast('Routed ' + encoderTitle + ' (' + state.activeStreamType.toUpperCase() + ') to ' + targets.length + ' display(s)');
}

async function sendCEC(deviceId, cecCommand, deviceTitle) {
    try {
        const res = await apiPost('/api/vpx/command', { device_id: deviceId, command: cecCommand });
        const data = await res.json();
        data.success ? showToast(deviceTitle + ': Sent "' + cecCommand + '"') : showToast('CEC Error: ' + (data.error || 'Failed'), true);
    } catch (e) { showToast('Failed to send CEC command', true); }
}

async function triggerPreset(presetId, presetTitle) {
    try {
        const res = await apiPost('/api/vpx/preset', { preset_id: presetId });
        const data = await res.json();
        await loadStatus();
        if (data.success) {
            showToast('Activated preset: ' + presetTitle);
        } else {
            const errList = data.result && data.result.errors ? data.result.errors : [];
            showToast('Preset partial error: ' + (errList.at(0) || data.error || 'Preset error'), true);
        }
    } catch (e) { showToast('Failed to trigger preset', true); }
}
