<script>
(function () {
    const csrfToken = document.body.dataset.csrf || '';
    const toastEl = document.getElementById('status-toast');
    const connectionDot = document.getElementById('connectionDot');
    const deviceCards = Array.from(document.querySelectorAll('.audio-device-card'));
    const tabsContainer = document.getElementById('audio-device-tabs');
    const refreshBtn = document.getElementById('btn-refresh-dtx');

    let activeDeviceId = null;

    function showToast(msg, isError = false) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.style.borderColor = isError ? '#ef4444' : '#38bdf8';
        toastEl.style.display = 'block';
        clearTimeout(toastEl._timer);
        toastEl._timer = setTimeout(() => { toastEl.style.display = 'none'; }, 3200);
    }

    async function apiPost(payload) {
        const res = await fetch('/panel/audio/api', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(payload),
        });

        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            throw new Error(`Server returned invalid response (HTTP ${res.status}): ${rawText.slice(0, 140)}`);
        }

        if (!res.ok || data.success === false) {
            throw new Error(data.error || data.message || `HTTP ${res.status}`);
        }
        return data;
    }

    function escapeHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderDeviceChannels(card, channels) {
        const container = card.querySelector('[data-channels-container]');
        if (!container) return;

        if (!Array.isArray(channels) || channels.length === 0) {
            container.innerHTML = '<div style="opacity:0.75; padding:12px 0;">No channels reported by device.</div>';
            return;
        }

        container.innerHTML = channels.map(ch => {
            const level = Math.max(0, Math.min(100, parseInt(ch.level ?? 50, 10)));
            const dbText = (typeof ch.db === 'number') ? `${ch.db > 0 ? '+' : ''}${ch.db.toFixed(1)} dB` : `${level}%`;
            const isMuted = Boolean(ch.muted);
            const badge = ch.group ? `<span style="opacity:0.65; font-size:0.78rem; margin-left:6px;">(${escapeHtml(ch.group)})</span>` : '';

            return `
                <div class="control-group" data-channel-row="${escapeHtml(ch.id)}">
                    <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                        <label style="width:auto; margin:0;">${escapeHtml(ch.label)}${badge}</label>
                        <span class="dtx-db-readout" style="font-family:monospace; font-weight:700; font-size:0.9rem; opacity:0.85;">${escapeHtml(dbText)}</span>
                    </div>
                    <div class="slider-mute-row">
                        <input type="range" class="glass-slider vol-slider" min="0" max="100" value="${level}" data-channel="${escapeHtml(ch.id)}" style="--slider-pct:${level}%;">
                        <button type="button" class="btn-mute ${isMuted ? 'muted' : ''}" data-action="mute" data-channel="${escapeHtml(ch.id)}">${isMuted ? 'Muted' : 'Mute'}</button>
                    </div>
                </div>
            `;
        }).join('');

        // Bind slider change & live readout handlers
        container.querySelectorAll('.vol-slider').forEach(slider => {
            slider.addEventListener('input', (e) => {
                const val = parseInt(e.target.value, 10);
                e.target.style.setProperty('--slider-pct', `${val}%`);
                const row = e.target.closest('.control-group');
                const readout = row ? row.querySelector('.dtx-db-readout') : null;
                if (readout) {
                    const db = -72.0 + 84.0 * Math.pow(val / 100.0, 0.5);
                    readout.textContent = `${db > 0 ? '+' : ''}${db.toFixed(1)} dB`;
                }
            });

            slider.addEventListener('change', async (e) => {
                const deviceId = card.dataset.deviceId;
                const channel = e.target.dataset.channel;
                const volume = parseInt(e.target.value, 10);

                try {
                    const resp = await apiPost({
                        action: 'volume',
                        device_id: deviceId,
                        channel: channel,
                        value: volume,
                    });
                    if (typeof resp.db === 'number') {
                        const row = e.target.closest('.control-group');
                        const readout = row ? row.querySelector('.dtx-db-readout') : null;
                        if (readout) {
                            readout.textContent = `${resp.db > 0 ? '+' : ''}${resp.db.toFixed(1)} dB`;
                        }
                    }
                    showToast(`Level set to ${volume}%`);
                } catch (err) {
                    showToast(err.message, true);
                }
            });
        });

        // Bind mute toggle handlers
        container.querySelectorAll('.btn-mute').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                const targetBtn = e.currentTarget;
                const deviceId = card.dataset.deviceId;
                const channel = targetBtn.dataset.channel;
                const willMute = !targetBtn.classList.contains('muted');

                try {
                    await apiPost({
                        action: 'mute',
                        device_id: deviceId,
                        channel: channel,
                        value: willMute,
                    });

                    targetBtn.classList.toggle('muted', willMute);
                    targetBtn.textContent = willMute ? 'Muted' : 'Mute';
                    showToast(willMute ? 'Channel muted' : 'Channel unmuted');
                } catch (err) {
                    showToast(err.message, true);
                }
            });
        });

        // Trigger scroll cue recalculation after channels render
        window.dispatchEvent(new Event('resize'));
    }

    async function loadDeviceChannels(card, silent = false) {
        if (!card) return;
        const deviceId = card.dataset.deviceId;
        const metaEl = card.querySelector('[data-status-meta]');
        const baseMeta = `${card.dataset.deviceModel || 'DTX'} | ${metaEl ? metaEl.textContent.split('|')[1]?.split('—')[0]?.trim() || '' : ''}`;

        try {
            const resp = await apiPost({
                action: 'state',
                device_id: deviceId,
            });
            if (connectionDot) {
                connectionDot.style.background = resp.online ? '#22c55e' : '#f59e0b';
            }
            if (metaEl) {
                metaEl.textContent = `${baseMeta} — ${resp.online ? 'Connected' : 'Offline (Model Defaults)'}`;
            }
            renderDeviceChannels(card, resp.channels || []);
            if (!silent) {
                showToast(resp.online ? 'Channels synced from DTX' : 'DTX offline — loaded model channels');
            }
        } catch (err) {
            if (connectionDot) connectionDot.style.background = '#ef4444';
            if (metaEl) metaEl.textContent = `${baseMeta} — Error`;
            showToast(err.message, true);
        }
    }

    function selectDevice(deviceId) {
        activeDeviceId = deviceId;

        Array.from(tabsContainer ? tabsContainer.children : []).forEach((btn, index) => {
            btn.classList.toggle('active', deviceCards[index] && deviceCards[index].dataset.deviceId === deviceId);
        });

        deviceCards.forEach(card => {
            const isSelected = card.dataset.deviceId === deviceId;
            card.style.display = isSelected ? 'flex' : 'none';
            if (isSelected) {
                loadDeviceChannels(card, true);
            }
        });
    }

    function initTabs() {
        if (!tabsContainer || deviceCards.length === 0) return;

        deviceCards.forEach((card, index) => {
            const devId = card.dataset.deviceId;
            const title = card.querySelector('.dsp-title').textContent;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'device-tab';
            btn.textContent = title;

            btn.addEventListener('click', () => selectDevice(devId));
            tabsContainer.appendChild(btn);

            if (index === 0) {
                selectDevice(devId);
            }
        });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            const activeCard = deviceCards.find(c => c.dataset.deviceId === activeDeviceId);
            if (activeCard) loadDeviceChannels(activeCard, false);
        });
    }

    const roomBtns = Array.from(document.querySelectorAll('.unified-room-tabs .room-tab'));
    roomBtns.forEach(rBtn => {
        rBtn.addEventListener('click', () => {
            const locId = rBtn.dataset.locationId || '0';
            roomBtns.forEach(b => b.classList.toggle('active', b === rBtn));
            let firstVisibleId = null;
            Array.from(tabsContainer ? tabsContainer.children : []).forEach((dBtn, idx) => {
                const card = deviceCards[idx];
                const matches = locId === '0' || (card && String(card.dataset.locationId || '0') === locId);
                dBtn.style.display = matches ? '' : 'none';
                if (matches && firstVisibleId === null && card) firstVisibleId = card.dataset.deviceId;
            });
            if (firstVisibleId !== null) selectDevice(firstVisibleId);
            else deviceCards.forEach(c => { c.style.display = 'none'; });
        });
    });

    initTabs();
})();
</script>
