<style>
.sys-pwr-modal { position: fixed; inset: 0; background: rgba(2, 6, 23, 0.86); z-index: 10050; display: none; align-items: center; justify-content: center; padding: 14px; }
.sys-pwr-modal.open { display: flex; }
.sys-pwr-card { width: min(400px, 100%); background: #0f172a; color: #f8fafc; border: 1px solid rgba(148, 163, 184, 0.35); border-radius: 18px; padding: 18px; display: flex; flex-direction: column; gap: 10px; box-shadow: 0 20px 45px rgba(0,0,0,0.5); }
body[data-theme="light"] .sys-pwr-card { background: #ffffff; color: #0f172a; }
.sys-pwr-item { display: flex; flex-direction: column; align-items: flex-start; text-align: left; gap: 2px; width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.35); background: rgba(30, 41, 59, 0.78); color: inherit; cursor: pointer; touch-action: manipulation; }
body[data-theme="light"] .sys-pwr-item { background: #f1f5f9; }
.sys-pwr-item strong { font-size: 0.98rem; }
.sys-pwr-item span { font-size: 0.78rem; opacity: 0.78; }
.sys-pwr-item.warn { border-color: rgba(245, 158, 11, 0.55); }
.sys-pwr-item.danger { background: rgba(185, 28, 28, 0.85); border-color: #ef4444; color: #ffffff; }
body[data-theme="light"] .sys-pwr-item.danger { background: #dc2626; color: #ffffff; }
</style>

<div class="sys-pwr-modal" id="sysPowerModal">
    <div class="sys-pwr-card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:1.05rem;">System &amp; Pi Power Controls</strong>
            <button type="button" onclick="closeSystemPowerModal()" style="border:1px solid rgba(148,163,184,0.4); background:transparent; color:inherit; border-radius:8px; padding:5px 10px; cursor:pointer; font-weight:600;">Close</button>
        </div>

        <button type="button" class="sys-pwr-item" onclick="triggerSystemPower('reload')">
            <strong>Reload Touch Panel UI</strong>
            <span>Refresh screen and reload latest layout</span>
        </button>

        <button type="button" class="sys-pwr-item warn" onclick="triggerSystemPower('restart_app')">
            <strong>Restart Panel Service</strong>
            <span>Clear cache and restart web server container</span>
        </button>

        <button type="button" class="sys-pwr-item warn" onclick="triggerSystemPower('reboot_pi')">
            <strong>Reboot Raspberry Pi</strong>
            <span>Restart the entire touchscreen controller (~30s)</span>
        </button>

        <button type="button" class="sys-pwr-item danger" onclick="triggerSystemPower('shutdown_pi')">
            <strong>Shut Down Raspberry Pi</strong>
            <span>Safely power off OS before unplugging</span>
        </button>

        <div id="sysPowerStatus" style="font-size:0.84rem; font-weight:600; min-height:1.2em;"></div>
    </div>
</div>

<script>
window._sysPowerState = { csrf: '', stationId: 0 };

function openSystemPowerModal() {
    const st = document.getElementById('sysPowerStatus');
    if (st) st.textContent = '';
    document.getElementById('sysPowerModal').classList.add('open');
}

function closeSystemPowerModal() {
    document.getElementById('sysPowerModal').classList.remove('open');
}

async function triggerSystemPower(action) {
    const labels = {
        reload: 'Reload the touch panel screen now?',
        restart_app: 'Restart the touch panel web service?',
        reboot_pi: 'Reboot the Raspberry Pi controller now?',
        shutdown_pi: 'Shut down the Raspberry Pi safely now? You will need to cycle power to turn it back on.'
    };
    if (!confirm(labels[action] || 'Proceed?')) return;

    const st = document.getElementById('sysPowerStatus');
    st.style.color = '#38bdf8';
    st.textContent = 'Sending command...';

    const stationId = (window.aucState && window.aucState.activeStation)
        ? window.aucState.activeStation.id
        : (window._sysPowerState.stationId || 0);
    const csrf = (window.aucState && window.aucState.csrfToken)
        ? window.aucState.csrfToken
        : (window._sysPowerState.csrf || '');

    try {
        const res = await fetch('/api/system-power/execute', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf
            },
            body: JSON.stringify({ power_action: action, station_id: stationId })
        });
        const data = await res.json();
        if (!data.success) {
            st.style.color = '#ef4444';
            st.textContent = data.error || 'Command failed';
            return;
        }
        st.style.color = '#22c55e';
        st.textContent = data.message || 'Done.';
        if (action === 'reload') {
            setTimeout(() => window.location.reload(), 400);
        } else if (action === 'restart_app') {
            setTimeout(() => window.location.reload(), 2500);
        }
    } catch (e) {
        st.style.color = '#ef4444';
        st.textContent = 'Error: ' + e.message;
    }
}

(async function initMainPanelPowerButton() {
    // Only auto-inject button on non-Auctioneer pages (Auctioneer manages its own topbar button per logged-in user)
    if (document.getElementById('aucTopbar')) return;
    try {
        const res = await fetch('/api/system-power/status', { credentials: 'same-origin' });
        const data = await res.json();
        if (!data || !data.authorized) return;
        window._sysPowerState.csrf = data.csrfToken || '';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = 'System';
        btn.title = 'System & Pi Power Controls';
        btn.style.cssText = 'position:fixed; top:10px; right:10px; z-index:9990; padding:7px 13px; border-radius:10px; border:1px solid rgba(148,163,184,0.4); background:rgba(15,23,42,0.82); color:#f8fafc; font-weight:700; font-size:0.84rem; cursor:pointer; backdrop-filter:blur(6px);';
        btn.onclick = openSystemPowerModal;
        document.body.appendChild(btn);
    } catch (e) {}
})();
</script>
