(function () {
    const csrfToken = document.body.dataset.csrf || '';
    const toastEl = document.getElementById('status-toast');
    const deviceCards = Array.from(document.querySelectorAll('.audio-device-card'));
    const tabsContainer = document.getElementById('audio-device-tabs');

    let activeDeviceId = null;

    function showToast(msg, isError = false) {
        toastEl.textContent = msg;
        toastEl.style.borderColor = isError ? '#ef4444' : '#c084fc';
        toastEl.style.display = 'block';
        clearTimeout(toastEl._timer);
        toastEl._timer = setTimeout(() => { toastEl.style.display = 'none'; }, 3200);
    }

    async function apiPost(payload) {
        const res = await fetch('/api/dtx', {
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

        if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
        return data;
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

            // Auto-select first device
            if (index === 0) selectDevice(devId);
        });
    }

    function selectDevice(deviceId) {
        activeDeviceId = deviceId;

        // Update Tabs
        Array.from(tabsContainer.children).forEach((btn, index) => {
            if (deviceCards[index].dataset.deviceId === deviceId) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Update Cards
        deviceCards.forEach(card => {
            if (card.dataset.deviceId === deviceId) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }

    // Attach Event Listeners to Sliders
    document.querySelectorAll('.vol-slider').forEach(slider => {
        // Use 'change' instead of 'input' so it only fires when the user releases the slider
        slider.addEventListener('change', async (e) => {
            const card = e.target.closest('.audio-device-card');
            const deviceId = card.dataset.deviceId;
            const channel = e.target.dataset.channel;
            const volume = e.target.value;

            try {
                await apiPost({
                    action: 'SetVolume',
                    device_id: deviceId,
                    channel: channel,
                    level: volume
                });
                showToast(`Volume set to ${volume}%`);
            } catch (err) {
                showToast(err.message, true);
            }
        });
    });

    // Attach Event Listeners to Mute Buttons
    document.querySelectorAll('.btn-mute').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const card = e.target.closest('.audio-device-card');
            const deviceId = card.dataset.deviceId;
            const channel = e.target.dataset.channel;

            const isMuted = e.target.classList.contains('muted');
            const newAction = isMuted ? 'Unmute' : 'Mute';

            try {
                await apiPost({
                    action: newAction,
                    device_id: deviceId,
                    channel: channel
                });

                if (isMuted) {
                    e.target.classList.remove('muted');
                    showToast('Channel unmuted');
                } else {
                    e.target.classList.add('muted');
                    showToast('Channel muted');
                }
            } catch (err) {
                showToast(err.message, true);
            }
        });
    });

    initTabs();
})();
