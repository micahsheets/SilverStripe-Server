<script>
(function () {
    const csrfToken = document.body.dataset.csrf || '';
    const dot = document.getElementById('connectionDot');
    const toast = document.getElementById('status-toast');
    let toastTimer = null;

    if (dot) {
        dot.classList.add('online');
    }

    function showToast(message, isError = false) {
        if (!toast) return;
        toast.textContent = message;
        toast.style.borderColor = isError ? 'rgba(239, 68, 68, 0.7)' : 'var(--accent)';
        toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toast.classList.remove('show'), 2600);
    }

    async function sendProjectorCommand(deviceId, command, label) {
        try {
            const res = await fetch('/api/projector/command', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    device_id: Number(deviceId),
                    command: command
                })
            });

            const data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.error || `HTTP ${res.status}`);
            }

            showToast(`${label} sent`);
        } catch (err) {
            showToast(`Projector error: ${err.message}`, true);
        }
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-cmd]');
        if (!btn) return;

        const card = btn.closest('[data-device-id]');
        if (!card) return;

        const deviceId = card.dataset.deviceId;
        const command = btn.dataset.cmd;
        const label = btn.textContent.trim();

        sendProjectorCommand(deviceId, command, label);
    });
})();
</script>
