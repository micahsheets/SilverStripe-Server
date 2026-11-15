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
})();
