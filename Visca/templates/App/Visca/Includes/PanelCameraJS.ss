<script>
(function () {
    const csrfToken = document.body.dataset.csrf || '';

    async function sendCamAPI(deviceId, command, param) {
        await fetch('/api/visca', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ device_id: deviceId, command: command, param: param })
        });
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

            // Touch & Mouse Down = Move
            btn.addEventListener('mousedown', sendStart);
            btn.addEventListener('touchstart', (e) => { e.preventDefault(); sendStart(); });

            // Touch & Mouse Up = Stop
            btn.addEventListener('mouseup', sendStop);
            btn.addEventListener('mouseleave', sendStop);
            btn.addEventListener('touchend', sendStop);
        });
    }

    // Attach PTZ D-Pad
    attachHoldEvents(document.querySelectorAll('.ptz-btn:not(.home)'), 'move', btn => btn.dataset.dir);

    // Attach Zoom
    attachHoldEvents(document.querySelectorAll('.zoom-btn'), 'zoom', btn => btn.dataset.zoom);

    // Stop button (explicit tap)
    document.querySelectorAll('.ptz-btn.home').forEach(btn => {
        btn.addEventListener('click', () => {
            sendCamAPI(btn.closest('.cam-card').dataset.deviceId, 'move', 'stop');
        });
    });

    // Preset Grid Click Handler
    document.querySelectorAll('.preset-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const card = btn.closest('.cam-card');
            const deviceId = card.dataset.deviceId;
            const presetId = btn.dataset.preset;

            const isSaveMode = card.querySelector('.save-mode-toggle').checked;

            if (isSaveMode) {
                sendCamAPI(deviceId, 'save', presetId);
                card.querySelector('.save-mode-toggle').checked = false; // reset toggle after save
                btn.style.borderColor = '#f59e0b';
                setTimeout(() => btn.style.borderColor = 'rgba(255, 255, 255, 0.15)', 500);
            } else {
                sendCamAPI(deviceId, 'recall', presetId);
            }
        });
    });

    const camRoomBtns = Array.from(document.querySelectorAll('.unified-room-tabs .room-tab'));
    camRoomBtns.forEach(rBtn => {
        rBtn.addEventListener('click', () => {
            const locId = rBtn.dataset.locationId || '0';
            camRoomBtns.forEach(b => b.classList.toggle('active', b === rBtn));
            document.querySelectorAll('.cam-card').forEach(card => {
                const matches = locId === '0' || String(card.dataset.locationId || '0') === locId;
                card.style.display = matches ? '' : 'none';
            });
        });
    });

    const camTabsEl = document.getElementById('camera-device-tabs');
    const camCards = Array.from(document.querySelectorAll('.cam-card'));
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
})();
</script>
