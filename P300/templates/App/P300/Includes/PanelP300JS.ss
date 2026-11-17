<script>
const p300State = {
    csrfToken: '',
    devices: [],
    activeDeviceId: 0
};

function showP300Toast(message, isError = false) {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = isError ? 'show error' : 'show';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.className = ''; }, isError ? 8000 : 2500);
}

async function p300Post(url, payload) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': p300State.csrfToken
        },
        body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
        throw new Error(data.error || ('HTTP ' + res.status));
    }
    return data;
}

async function loadP300Status() {
    try {
        const res = await fetch('/api/p300/status');
        if (res.status === 401) { window.location.href = '/panel'; return; }
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load P300 status');

        p300State.csrfToken = data.csrfToken || '';
        p300State.devices = data.devices || [];

        if (!p300State.devices.some(d => d.id === p300State.activeDeviceId) && p300State.devices.length > 0) {
            p300State.activeDeviceId = p300State.devices[0].id;
        }

        renderP300();
    } catch (err) {
        document.getElementById('p300ConnectionDot').style.backgroundColor = '#ef4444';
        showP300Toast(err.message || 'Unable to reach P300 API', true);
    }
}

function getActiveP300() {
    return p300State.devices.find(d => d.id === p300State.activeDeviceId) || null;
}

async function toggleAutomixerMute() {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        const data = await p300Post('/api/p300/mute', {
            device_id: dev.id,
            target: 'automixer',
            state: 'TOGGLE'
        });
        dev.automixerMuted = !!data.muted;
        renderP300();
        showP300Toast(dev.title + (dev.automixerMuted ? ': System Muted' : ': System Unmuted'));
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

async function toggleChannelMute(channel) {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        const data = await p300Post('/api/p300/mute', {
            device_id: dev.id,
            target: channel,
            state: 'TOGGLE'
        });
        const chObj = dev.channels.find(c => c.channel === channel);
        if (chObj) chObj.muted = !!data.muted;
        renderP300();
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

async function setChannelGain(channel, dbVal) {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        const data = await p300Post('/api/p300/gain', {
            device_id: dev.id,
            channel: channel,
            gain_db: parseFloat(dbVal)
        });
        const chObj = dev.channels.find(c => c.channel === channel);
        if (chObj) chObj.gainDb = data.gainDb;
        renderP300();
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

async function recallP300Preset(presetNum, presetName) {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        await p300Post('/api/p300/preset', {
            device_id: dev.id,
            preset: presetNum
        });
        showP300Toast(dev.title + ': Recalled ' + presetName);
        await 
function applyP300ViewMode(mode) {
    const m = (mode === 'list') ? 'list' : 'grid';
    localStorage.setItem('p300_view_mode', m);
    document.getElementById('p300Workspace')?.classList.toggle('is-list-view', m === 'list');
    document.querySelectorAll('[data-view-mode]').forEach(b => b.classList.toggle('active', b.dataset.viewMode === m));
}
document.querySelectorAll('[data-view-mode]').forEach(b => b.addEventListener('click', () => applyP300ViewMode(b.dataset.viewMode)));
applyP300ViewMode(localStorage.getItem('p300_view_mode') || 'grid');

loadP300Status();
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

async function flashActiveP300() {
    const dev = getActiveP300();
    if (!dev) return;
    try {
        await p300Post('/api/p300/flash', { device_id: dev.id });
        showP300Toast(dev.title + ': Flashing front-panel LEDs for 30s');
    } catch (err) {
        showP300Toast(err.message, true);
    }
}

function renderP300() {
    const tabs = document.getElementById('p300DeviceTabs');
    const workspace = document.getElementById('p300Workspace');
    tabs.innerHTML = '';
    workspace.innerHTML = '';

    if (p300State.devices.length === 0) {
        workspace.innerHTML = '<div class="p300-hero-card">No Shure P300 DSP configured yet. Add one in CMS &rarr; Shure P300 DSP.</div>';
        return;
    }

    p300State.devices.forEach(dev => {
        const btn = document.createElement('button');
        btn.className = 'device-tab' + (dev.id === p300State.activeDeviceId ? ' active' : '');
        btn.textContent = dev.title + (dev.online ? '' : ' (Offline)');
        btn.onclick = () => { p300State.activeDeviceId = dev.id; renderP300(); };
        tabs.appendChild(btn);
    });

    const dev = getActiveP300();
    if (!dev) return;

    document.getElementById('p300ConnectionDot').style.backgroundColor = dev.online ? '#22c55e' : '#ef4444';

    // Hero card: Device info + Master Automixer Mute (Ch 21)
    const hero = document.createElement('div');
    hero.className = 'p300-hero-card';

    const info = document.createElement('div');
    const titleEl = document.createElement('h2');
    titleEl.style.margin = '0 0 4px 0';
    titleEl.textContent = dev.title + (dev.deviceId ? ' (' + dev.deviceId + ')' : '');

    const subEl = document.createElement('div');
    subEl.style.opacity = '0.75';
    subEl.style.fontSize = '0.9rem';
    subEl.textContent = dev.online
        ? 'Connected via TCP ' + dev.ip + ':' + dev.port
        : 'Offline: ' + (dev.error || 'Unable to reach ' + dev.ip + ':' + dev.port);

    info.append(titleEl, subEl);

    const muteBtn = document.createElement('button');
    muteBtn.type = 'button';
    muteBtn.className = 'p300-master-mute-btn' + (dev.automixerMuted ? ' is-muted' : '');
    muteBtn.textContent = dev.automixerMuted ? 'SYSTEM MUTED (Click to Unmute)' : 'SYSTEM LIVE (Mute All Mics)';
    muteBtn.onclick = toggleAutomixerMute;

    hero.append(info, muteBtn);
    workspace.appendChild(hero);

    // Hardware Presets (01-10)
    if (Array.isArray(dev.presets) && dev.presets.length > 0) {
        const pHeader = document.createElement('h3');
        pHeader.textContent = 'Hardware Presets';
        workspace.appendChild(pHeader);

        const pGrid = document.createElement('div');
        pGrid.className = 'p300-presets-grid';

        dev.presets.forEach(p => {
            const pBtn = document.createElement('button');
            pBtn.type = 'button';
            pBtn.className = 'p300-preset-btn' + (p.active ? ' active' : '');
            pBtn.textContent = String(p.number).padStart(2, '0') + ' · ' + p.name;
            pBtn.onclick = () => recallP300Preset(p.number, p.name);
            pGrid.appendChild(pBtn);
        });

        workspace.appendChild(pGrid);
    }

    // Channel Strips
    const cHeader = document.createElement('h3');
    cHeader.textContent = 'Channels & Gain';
    workspace.appendChild(cHeader);

    const cGrid = document.createElement('div');
    cGrid.className = 'p300-channels-grid';

    (dev.channels || []).forEach(ch => {
        const card = document.createElement('div');
        card.className = 'p300-chan-card';

        const top = document.createElement('div');
        top.className = 'p300-chan-top';

        const left = document.createElement('div');
        const num = document.createElement('div');
        num.className = 'p300-chan-num';
        num.textContent = 'CH ' + ch.channel + ' · ' + ch.group;

        const name = document.createElement('div');
        name.className = 'p300-chan-name';
        name.textContent = ch.name;
        left.append(num, name);

        const dbReadout = document.createElement('div');
        dbReadout.className = 'p300-db-readout';
        dbReadout.textContent = (ch.gainDb > 0 ? '+' : '') + Number(ch.gainDb).toFixed(1) + ' dB';

        top.append(left, dbReadout);

        const slider = document.createElement('input');
        slider.type = 'range';
        slider.className = 'p300-slider';
        slider.min = '-80';
        slider.max = '20';
        slider.step = '0.5';
        slider.value = String(Math.max(-80, Math.min(20, ch.gainDb)));
        slider.oninput = () => {
            const v = parseFloat(slider.value);
            dbReadout.textContent = (v > 0 ? '+' : '') + v.toFixed(1) + ' dB';
        };
        slider.onchange = () => setChannelGain(ch.channel, slider.value);

        const cMute = document.createElement('button');
        cMute.type = 'button';
        cMute.className = 'p300-chan-mute' + (ch.muted ? ' is-muted' : '');
        cMute.textContent = 'MUTE';
        cMute.title = ch.muted ? 'Muted (Click to Unmute)' : 'Mute Channel';
        cMute.onclick = () => toggleChannelMute(ch.channel);

        const controlRow = document.createElement('div');
        controlRow.className = 'slider-mute-row';
        controlRow.append(slider, cMute);
        card.append(top, controlRow);
        cGrid.appendChild(card);
    });

    workspace.appendChild(cGrid);
}


function applyP300ViewMode(mode) {
    const m = (mode === 'list') ? 'list' : 'grid';
    localStorage.setItem('p300_view_mode', m);
    document.getElementById('p300Workspace')?.classList.toggle('is-list-view', m === 'list');
    document.querySelectorAll('[data-view-mode]').forEach(b => b.classList.toggle('active', b.dataset.viewMode === m));
}
document.querySelectorAll('[data-view-mode]').forEach(b => b.addEventListener('click', () => applyP300ViewMode(b.dataset.viewMode)));
applyP300ViewMode(localStorage.getItem('p300_view_mode') || 'grid');

loadP300Status();
setInterval(loadP300Status, 10000);
</script>
