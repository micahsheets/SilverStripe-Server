<script>
(function () {
    const csrfToken = document.body.dataset.csrf || '';
    const toastEl = document.getElementById('status-toast');

    function showToast(msg, isError = false) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.style.borderColor = isError ? '#ef4444' : '#38bdf8';
        toastEl.style.display = 'block';
        clearTimeout(toastEl._timer);
        toastEl._timer = setTimeout(() => { toastEl.style.display = 'none'; }, 3600);
    }

    async function postJson(url, payload) {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify(payload)
        });
        const raw = await res.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (e) {
            throw new Error(`Invalid response (HTTP ${res.status}): ${raw.slice(0, 120)}`);
        }
        if (!res.ok || data.success === false) {
            throw new Error(data.error || data.message || `HTTP ${res.status}`);
        }
        return data;
    }

    async function sendCamAPI(deviceId, command, param) {
        try {
            await postJson('/panel/camera/api', { device_id: deviceId, command: command, param: param });
        } catch (e) {
            showToast(e.message, true);
        }
    }

    function attachHoldEvents(elements, commandType, getParamValue) {
        elements.forEach(btn => {
            const sendStart = () => {
                const deviceId = btn.closest('.cam-card').dataset.deviceId;
                sendCamAPI(deviceId, commandType, getParamValue(btn));
            };
            const sendStop = () => {
                const deviceId = btn.closest('.cam-card').dataset.deviceId;
                sendCamAPI(deviceId, commandType, 'stop');
            };

            btn.addEventListener('mousedown', sendStart);
            btn.addEventListener('touchstart', (e) => { e.preventDefault(); sendStart(); });
            btn.addEventListener('mouseup', sendStop);
            btn.addEventListener('mouseleave', sendStop);
            btn.addEventListener('touchend', sendStop);
        });
    }

    attachHoldEvents(document.querySelectorAll('.ptz-btn:not(.home)'), 'move', btn => btn.dataset.dir);
    attachHoldEvents(document.querySelectorAll('.zoom-btn'), 'zoom', btn => btn.dataset.zoom);

    document.querySelectorAll('.ptz-btn.home').forEach(btn => {
        btn.addEventListener('click', () => {
            sendCamAPI(btn.closest('.cam-card').dataset.deviceId, 'move', 'stop');
        });
    });

    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const card = btn.closest('.cam-card');
            const deviceId = card.dataset.deviceId;
            const presetId = btn.dataset.preset;
            const isSaveMode = card.querySelector('.save-mode-toggle').checked;

            if (isSaveMode) {
                sendCamAPI(deviceId, 'save', presetId);
                card.querySelector('.save-mode-toggle').checked = false;
                btn.style.borderColor = '#f59e0b';
                setTimeout(() => btn.style.borderColor = 'rgba(255, 255, 255, 0.15)', 500);
            } else {
                sendCamAPI(deviceId, 'recall', presetId);
            }
        });
    });

    // Multi-camera Live Preview Stream Controller
    const previewSelect = document.getElementById('camPreviewSelect');
    const previewImg = document.getElementById('camPreviewImg');
    const previewMsg = document.getElementById('camPreviewMsg');
    const previewLabel = document.getElementById('camPreviewLabel');
    const previewDot = document.getElementById('camPreviewDot');
    const previewToggleBtn = document.getElementById('btnCamPreviewToggle');
    const previewRefreshBtn = document.getElementById('btnCamPreviewRefresh');

    let previewPaused = false;
    let previewTimer = null;
    let previewInFlight = false;
    let previewFailCount = 0;

    function setPreviewCamera(camId) {
        if (!previewSelect || !camId || camId === 'all') return;
        const opt = Array.from(previewSelect.options).find(o => o.value === String(camId));
        if (opt && previewSelect.value !== String(camId)) {
            previewSelect.value = String(camId);
            previewFailCount = 0;
            if (previewMsg) {
                previewMsg.textContent = 'Switching camera feed…';
                previewMsg.style.display = 'flex';
            }
            requestNextFrame(true);
        }
    }

    function requestNextFrame(immediate = false) {
        clearTimeout(previewTimer);
        if (!previewSelect || !previewImg || previewPaused || document.hidden) return;
        const delay = immediate ? 10 : (previewFailCount > 0 ? Math.min(2500, 500 * previewFailCount) : 220);
        previewTimer = setTimeout(() => {
            if (previewPaused || document.hidden || previewInFlight) return;
            const camId = previewSelect.value;
            if (!camId) return;
            const optText = previewSelect.options[previewSelect.selectedIndex]?.text || `Camera ${camId}`;
            previewInFlight = true;

            const loader = new Image();
            loader.onload = () => {
                previewInFlight = false;
                previewFailCount = 0;
                previewImg.src = loader.src;
                if (previewMsg) previewMsg.style.display = 'none';
                if (previewLabel) previewLabel.textContent = `LIVE • ${optText}`;
                if (previewDot) previewDot.style.background = '#22c55e';
                requestNextFrame(false);
            };
            loader.onerror = () => {
                previewInFlight = false;
                previewFailCount++;
                if (previewLabel) previewLabel.textContent = `OFFLINE • ${optText}`;
                if (previewDot) previewDot.style.background = '#f59e0b';
                if (previewMsg && previewFailCount >= 2) {
                    previewMsg.textContent = `No snapshot stream available from ${optText}. Check camera IP/network or enable HTTP/ONVIF snapshot on camera.`;
                    previewMsg.style.display = 'flex';
                }
                requestNextFrame(false);
            };
            loader.src = `/panel/camera/snapshot?id=${encodeURIComponent(camId)}&_t=${Date.now()}`;
        }, delay);
    }

    if (previewSelect) {
        previewSelect.addEventListener('change', () => {
            previewFailCount = 0;
            if (previewMsg) {
                previewMsg.textContent = 'Loading camera preview…';
                previewMsg.style.display = 'flex';
            }
            requestNextFrame(true);
        });
        if (previewToggleBtn) {
            previewToggleBtn.addEventListener('click', () => {
                previewPaused = !previewPaused;
                previewToggleBtn.textContent = previewPaused ? 'Resume' : 'Pause';
                if (previewPaused) {
                    clearTimeout(previewTimer);
                    if (previewDot) previewDot.style.background = '#94a3b8';
                    if (previewLabel) previewLabel.textContent = 'PAUSED';
                } else {
                    requestNextFrame(true);
                }
            });
        }
        if (previewRefreshBtn) {
            previewRefreshBtn.addEventListener('click', () => {
                previewFailCount = 0;
                requestNextFrame(true);
            });
        }
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && !previewPaused) {
                requestNextFrame(true);
            }
        });
        requestNextFrame(true);
    }

    const camTabsEl = document.getElementById('camera-device-tabs');
    const camCards = Array.from(document.querySelectorAll('.cam-card[data-device-id]'));
    let activeCamLocation = '0';
    let activeCamId = 'all';

    function applyCameraFilters() {
        camCards.forEach(card => {
            const locMatch = activeCamLocation === '0' || String(card.dataset.locationId || '0') === activeCamLocation;
            const devMatch = activeCamId === 'all' || String(card.dataset.deviceId) === activeCamId;
            card.style.display = (locMatch && devMatch) ? '' : 'none';
        });
        if (camTabsEl) {
            Array.from(camTabsEl.children).forEach(btn => {
                const devId = btn.dataset.deviceId;
                if (devId === 'all') {
                    btn.style.display = '';
                } else {
                    const locMatch = activeCamLocation === '0' || String(btn.dataset.locationId || '0') === activeCamLocation;
                    btn.style.display = locMatch ? '' : 'none';
                }
                btn.classList.toggle('active', devId === activeCamId);
            });
        }
        if (previewSelect) {
            let firstVisibleVal = '';
            Array.from(previewSelect.options).forEach(opt => {
                const locMatch = activeCamLocation === '0' || String(opt.dataset.locationId || '0') === activeCamLocation;
                opt.hidden = !locMatch;
                if (locMatch && !firstVisibleVal) firstVisibleVal = opt.value;
            });
            if (activeCamId !== 'all') {
                setPreviewCamera(activeCamId);
            } else if (firstVisibleVal && previewSelect.options[previewSelect.selectedIndex]?.hidden) {
                setPreviewCamera(firstVisibleVal);
            }
        }
    }

    if (camTabsEl && camCards.length > 0) {
        const allCamBtn = document.createElement('button');
        allCamBtn.type = 'button';
        allCamBtn.className = 'device-tab active';
        allCamBtn.dataset.deviceId = 'all';
        allCamBtn.textContent = 'All Cameras';
        allCamBtn.addEventListener('click', () => {
            activeCamId = 'all';
            applyCameraFilters();
        });
        camTabsEl.appendChild(allCamBtn);

        camCards.forEach(card => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'device-tab';
            btn.dataset.deviceId = card.dataset.deviceId;
            btn.dataset.locationId = card.dataset.locationId || '0';
            const titleEl = card.querySelector('h3');
            btn.textContent = titleEl ? titleEl.textContent.trim() : ('Camera ' + card.dataset.deviceId);
            btn.addEventListener('click', () => {
                activeCamId = card.dataset.deviceId;
                applyCameraFilters();
            });
            camTabsEl.appendChild(btn);
        });
    }

    document.querySelectorAll('.unified-room-tabs .room-tab').forEach(rBtn => {
        rBtn.addEventListener('click', () => {
            activeCamLocation = rBtn.dataset.locationId || '0';
            activeCamId = 'all';
            document.querySelectorAll('.unified-room-tabs .room-tab').forEach(b => b.classList.toggle('active', b === rBtn));
            applyCameraFilters();
        });
    });

    // Cross-subnet PTZ Camera Discovery & IP Configuration Modal
    const modal = document.getElementById('ptzDiscoverModal');
    const openModalBtn = document.getElementById('btn-discover-ptz');
    const closeModalBtn = document.getElementById('btn-close-ptz-modal');
    const runScanBtn = document.getElementById('btn-run-ptz-scan');
    const subnetInput = document.getElementById('ptzSubnetInput');
    const statusEl = document.getElementById('ptzDiscoverStatus');
    const resultsEl = document.getElementById('ptzDiscoverResults');

    function escapeHtml(s) {
        return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    async function runPtzScan() {
        if (!runScanBtn) return;
        runScanBtn.disabled = true;
        runScanBtn.textContent = 'Scanning…';
        statusEl.textContent = 'Sending ONVIF WS-Discovery, mDNS & VISCA probes across subnets…';
        resultsEl.innerHTML = '';

        try {
            const data = await postJson('/panel/camera/discover', { subnet: subnetInput.value.trim() });
            const cams = data.cameras || [];
            statusEl.textContent = `Found ${cams.length} camera(s).`;

            if (cams.length === 0) {
                resultsEl.innerHTML = '<div style="opacity:0.75; padding:10px 0;">No cameras replied yet. Try entering the camera IP or subnet (e.g. 192.168.100.120 or 192.168.100.0/24) above and click Scan Now.</div>';
                return;
            }

            resultsEl.innerHTML = cams.map((cam, idx) => `
                <div class="ptz-disc-row" data-idx="${idx}">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
                        <div>
                            <strong>${escapeHtml(cam.ip)}</strong>
                            <span style="opacity:0.75; font-size:0.82rem; margin-left:8px;">${escapeHtml(cam.model)} (port ${escapeHtml(cam.port || 1259)}) ${cam.mac ? '• ' + escapeHtml(cam.mac) : ''}</span>
                        </div>
                        <span style="font-size:0.75rem; padding:3px 8px; border-radius:6px; background:rgba(56,189,248,0.18); color:#7dd3fc;">
                            ${escapeHtml(cam.source)} ${cam.alreadyExists ? '• In DB' : '• New'}
                        </span>
                    </div>

                    <div class="ptz-disc-inputs">
                        <div>
                            <div style="font-size:0.72rem; opacity:0.75; margin-bottom:3px;">Camera Name</div>
                            <input type="text" id="ptz_title_${idx}" value="${escapeHtml(cam.title)}">
                        </div>
                        <div>
                            <div style="font-size:0.72rem; opacity:0.75; margin-bottom:3px;">New Static IP</div>
                            <input type="text" id="ptz_newip_${idx}" value="${escapeHtml(cam.ip)}" placeholder="e.g. 192.168.1.51">
                        </div>
                        <div>
                            <div style="font-size:0.72rem; opacity:0.75; margin-bottom:3px;">Subnet Mask</div>
                            <input type="text" id="ptz_mask_${idx}" value="255.255.255.0">
                        </div>
                        <div>
                            <div style="font-size:0.72rem; opacity:0.75; margin-bottom:3px;">Gateway</div>
                            <input type="text" id="ptz_gw_${idx}" value="" placeholder="Auto (.1)">
                        </div>
                    </div>

                    <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                        <button type="button" class="nav-pill" data-apply-ip="${idx}" data-cur-ip="${escapeHtml(cam.ip)}" style="border-color:#f59e0b; color:#fde68a;">
                            Apply IP to Camera
                        </button>
                        <button type="button" class="nav-pill" data-import-cam="${idx}" data-cur-ip="${escapeHtml(cam.ip)}" data-port="${escapeHtml(cam.port || 1259)}" style="border-color:#22c55e; color:#86efac;">
                            Add / Update in Panel DB
                        </button>
                    </div>
                </div>
            `).join('');

            resultsEl.querySelectorAll('[data-apply-ip]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const idx = btn.dataset.idx || btn.getAttribute('data-apply-ip');
                    const curIp = btn.dataset.curIp;
                    const newIp = document.getElementById(`ptz_newip_${idx}`).value.trim();
                    const netmask = document.getElementById(`ptz_mask_${idx}`).value.trim();
                    const gateway = document.getElementById(`ptz_gw_${idx}`).value.trim();
                    const username = document.getElementById('ptzAuthUser').value.trim();
                    const password = document.getElementById('ptzAuthPass').value;

                    btn.disabled = true;
                    btn.textContent = 'Applying…';
                    try {
                        const resp = await postJson('/panel/camera/setip', {
                            currentIp: curIp,
                            newIp: newIp,
                            netmask: netmask,
                            gateway: gateway,
                            username: username,
                            password: password
                        });
                        showToast(`Camera IP updated to ${resp.newIp} via ${resp.methods.join(', ')}`);
                        btn.dataset.curIp = newIp;
                    } catch (e) {
                        showToast(e.message, true);
                    } finally {
                        btn.disabled = false;
                        btn.textContent = 'Apply IP to Camera';
                    }
                });
            });

            resultsEl.querySelectorAll('[data-import-cam]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const idx = btn.getAttribute('data-import-cam');
                    const ip = document.getElementById(`ptz_newip_${idx}`).value.trim() || btn.dataset.curIp;
                    const title = document.getElementById(`ptz_title_${idx}`).value.trim();
                    const port = parseInt(btn.dataset.port || '1259', 10) || 1259;

                    btn.disabled = true;
                    try {
                        await postJson('/panel/camera/import', {
                            cameras: [{ ip: ip, title: title, port: port }]
                        });
                        showToast(`Saved "${title}" (${ip}) to database`);
                        setTimeout(() => window.location.reload(), 900);
                    } catch (e) {
                        showToast(e.message, true);
                        btn.disabled = false;
                    }
                });
            });
        } catch (e) {
            statusEl.textContent = '';
            showToast(e.message, true);
        } finally {
            runScanBtn.disabled = false;
            runScanBtn.textContent = 'Scan Now';
        }
    }

    if (openModalBtn && modal) {
        openModalBtn.addEventListener('click', () => {
            modal.style.display = 'block';
            runPtzScan();
        });
    }
    if (closeModalBtn && modal) {
        closeModalBtn.addEventListener('click', () => {
            modal.style.display = 'none';
        });
    }
    if (runScanBtn) {
        runScanBtn.addEventListener('click', runPtzScan);
    }
})();
</script>
