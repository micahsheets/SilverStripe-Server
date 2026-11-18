<style>
.sys-pwr-modal { position: fixed; inset: 0; background: rgba(2, 6, 23, 0.88); z-index: 10050; display: none; align-items: center; justify-content: center; padding: 14px; }
.sys-pwr-modal.open { display: flex; }
.sys-pwr-card { width: min(400px, 100%); background: #0f172a; color: #f8fafc; border: 1px solid rgba(148, 163, 184, 0.35); border-radius: 18px; padding: 18px; display: flex; flex-direction: column; gap: 10px; box-shadow: 0 20px 45px rgba(0,0,0,0.55); }
body[data-theme="light"] .sys-pwr-card { background: #ffffff; color: #0f172a; }
.sys-pwr-item { display: flex; flex-direction: column; align-items: flex-start; text-align: left; gap: 2px; width: 100%; padding: 12px 14px; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.35); background: rgba(30, 41, 59, 0.78); color: inherit; cursor: pointer; touch-action: manipulation; }
body[data-theme="light"] .sys-pwr-item { background: #f1f5f9; }
.sys-pwr-item strong { font-size: 0.98rem; }
.sys-pwr-item span { font-size: 0.78rem; opacity: 0.78; }
.sys-pwr-item.warn { border-color: rgba(245, 158, 11, 0.55); }
.sys-pwr-item.danger { background: rgba(185, 28, 28, 0.85); border-color: #ef4444; color: #ffffff; }
body[data-theme="light"] .sys-pwr-item.danger { background: #dc2626; color: #ffffff; }
.sys-pin-display { text-align: center; font-size: 1.5rem; letter-spacing: 0.35em; padding: 10px; border-radius: 10px; background: rgba(15, 23, 42, 0.55); border: 1px solid rgba(148, 163, 184, 0.3); min-height: 2.1rem; }
body[data-theme="light"] .sys-pin-display { background: #f8fafc; }
.sys-keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.sys-key { height: 50px; font-size: 1.2rem; font-weight: 700; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.3); background: rgba(30, 41, 59, 0.85); color: inherit; cursor: pointer; touch-action: manipulation; }
body[data-theme="light"] .sys-key { background: #e2e8f0; }
.sys-key:active { background: #2563eb; color: #fff; }
</style>

<!-- Admin PIN Prompt Modal (shown when opening System Power from locked/splash state) -->
<div class="sys-pwr-modal" id="sysPowerPinModal" onclick="event.stopPropagation()">
    <div class="sys-pwr-card" onclick="event.stopPropagation()">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:1.05rem;">Enter Admin PIN</strong>
            <button type="button" onclick="closeSystemPinModal()" style="border:1px solid rgba(148,163,184,0.4); background:transparent; color:inherit; border-radius:8px; padding:5px 10px; cursor:pointer; font-weight:600;">Cancel</button>
        </div>
        <div class="sys-pin-display" id="sysPinDots"></div>
        <div id="sysPinError" style="color:#ef4444; font-size:0.84rem; font-weight:600; min-height:1.1em; text-align:center;"></div>
        <div class="sys-keypad">
            <button type="button" class="sys-key" onclick="appendSysPin('1')">1</button>
            <button type="button" class="sys-key" onclick="appendSysPin('2')">2</button>
            <button type="button" class="sys-key" onclick="appendSysPin('3')">3</button>
            <button type="button" class="sys-key" onclick="appendSysPin('4')">4</button>
            <button type="button" class="sys-key" onclick="appendSysPin('5')">5</button>
            <button type="button" class="sys-key" onclick="appendSysPin('6')">6</button>
            <button type="button" class="sys-key" onclick="appendSysPin('7')">7</button>
            <button type="button" class="sys-key" onclick="appendSysPin('8')">8</button>
            <button type="button" class="sys-key" onclick="appendSysPin('9')">9</button>
            <button type="button" class="sys-key" onclick="clearSysPin()">Clear</button>
            <button type="button" class="sys-key" onclick="appendSysPin('0')">0</button>
            <button type="button" class="sys-key" style="background:#16a34a; color:#fff;" onclick="submitSysPin()">OK</button>
        </div>
    </div>
</div>

<!-- System & Pi Power Actions Modal -->
<div class="sys-pwr-modal" id="sysPowerModal" onclick="event.stopPropagation()">
    <div class="sys-pwr-card" onclick="event.stopPropagation()">
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
window._sysPowerState = { csrf: '', authorized: false, verifiedPin: '', pinBuffer: '' };

function handleSystemButtonTap(e) {
    if (e) e.stopPropagation();
    if (window._sysPowerState.authorized || window._sysPowerState.verifiedPin !== '') {
        openSystemPowerModal();
        return;
    }
    window._sysPowerState.pinBuffer = '';
    document.getElementById('sysPinDots').textContent = '';
    document.getElementById('sysPinError').textContent = '';
    document.getElementById('sysPowerPinModal').classList.add('open');
}

function closeSystemPinModal() {
    document.getElementById('sysPowerPinModal').classList.remove('open');
    window._sysPowerState.pinBuffer = '';
}

function appendSysPin(d) {
    if (window._sysPowerState.pinBuffer.length >= 8) return;
    window._sysPowerState.pinBuffer += d;
    document.getElementById('sysPinDots').textContent = '•'.repeat(window._sysPowerState.pinBuffer.length);
}

function clearSysPin() {
    window._sysPowerState.pinBuffer = '';
    document.getElementById('sysPinDots').textContent = '';
    document.getElementById('sysPinError').textContent = '';
}

async function submitSysPin() {
    const errEl = document.getElementById('sysPinError');
    errEl.textContent = 'Verifying...';
    try {
        const res = await fetch('/api/system-power/verifypin', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window._sysPowerState.csrf || ''
            },
            body: JSON.stringify({ pin: window._sysPowerState.pinBuffer })
        });
        const data = await res.json();
        if (!data.success) {
            clearSysPin();
            errEl.textContent = data.error || 'Invalid Admin PIN';
            return;
        }
        window._sysPowerState.verifiedPin = window._sysPowerState.pinBuffer;
        if (data.csrfToken) window._sysPowerState.csrf = data.csrfToken;
        closeSystemPinModal();
        openSystemPowerModal();
    } catch (e) {
        errEl.textContent = 'Error: ' + e.message;
    }
}

function openSystemPowerModal() {
    const st = document.getElementById('sysPowerStatus');
    if (st) st.textContent = '';
    document.getElementById('sysPowerModal').classList.add('open');
}

function closeSystemPowerModal() {
    document.getElementById('sysPowerModal').classList.remove('open');
    window._sysPowerState.verifiedPin = '';
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
        : 0;
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
            body: JSON.stringify({
                power_action: action,
                station_id: stationId,
                admin_pin: window._sysPowerState.verifiedPin || ''
            })
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
    // Auctioneer manages its own topbar button per logged-in user
    if (document.getElementById('aucTopbar')) return;

    const isSplashOrLock = !!document.getElementById('splashTrigger') || window.location.pathname.indexOf('/panel/lock') !== -1;

    try {
        const res = await fetch('/api/system-power/status', { credentials: 'same-origin' });
        const data = await res.json();
        window._sysPowerState.csrf = (data && data.csrfToken) ? data.csrfToken : '';
        window._sysPowerState.authorized = !!(data && data.authorized);

        // Show button on all control pages when Admin PIN is active, AND always show on Splash/Lock (prompts for Admin PIN)
        if (!window._sysPowerState.authorized && !isSplashOrLock) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = isSplashOrLock && !window._sysPowerState.authorized ? 'System Power' : 'System';
        btn.title = 'System & Pi Power Controls';
        btn.style.cssText = 'position:fixed; top:12px; right:12px; z-index:9990; padding:8px 14px; border-radius:10px; border:1px solid rgba(148,163,184,0.42); background:rgba(15,23,42,0.84); color:#f8fafc; font-weight:700; font-size:0.84rem; cursor:pointer; backdrop-filter:blur(6px);';
        btn.addEventListener('click', handleSystemButtonTap);
        document.body.appendChild(btn);
    } catch (e) {}
})();
</script>
