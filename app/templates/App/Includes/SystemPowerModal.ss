<style>
.sys-pwr-modal {
    position: fixed;
    inset: 0;
    background: rgba(2, 6, 23, 0.92);
    z-index: 10050;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    box-sizing: border-box;
    user-select: none;
    -webkit-user-select: none;
    -webkit-tap-highlight-color: transparent;
}
.sys-pwr-modal.open { display: flex; }

/* Unified PIN Pad Card matching TouchPanelLock.ss */
.sys-pin-box {
    background: var(--card-bg, #0f172a);
    border: 1px solid var(--card-border, rgba(148, 163, 184, 0.25));
    color: var(--text-main, #f8fafc);
    border-radius: 28px;
    padding: 28px 32px;
    width: 100%;
    max-width: 520px;
    text-align: center;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
    box-sizing: border-box;
}
.sys-pin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
}
.sys-pin-title {
    margin: 0;
    font-size: 1.55rem;
    font-weight: 800;
    letter-spacing: 0.03em;
}
.sys-pin-cancel {
    background: var(--control-bg, rgba(30, 41, 59, 0.85));
    border: 2px solid var(--card-border, rgba(148, 163, 184, 0.3));
    color: var(--text-main, inherit);
    border-radius: 14px;
    padding: 15px 22px;
    min-height: 54px;
    font-size: 1.05rem;
    font-weight: 700;
    cursor: pointer;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}
.sys-pin-display {
    background: var(--control-bg, rgba(15, 23, 42, 0.75));
    border: 2px solid var(--card-border, rgba(148, 163, 184, 0.3));
    color: var(--accent, #38bdf8);
    font-size: 2.5rem;
    letter-spacing: 14px;
    padding: 12px 20px;
    border-radius: 16px;
    margin: 12px 0 18px 0;
    min-height: 72px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    contain: layout paint;
}
.sys-pin-err {
    color: #fca5a5;
    font-size: 1.02rem;
    font-weight: 600;
    min-height: 1.3em;
    margin-bottom: 10px;
}
body[data-theme="light"] .sys-pin-err {
    color: #b91c1c;
}
.sys-keypad {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
}
.sys-key {
    background: var(--control-bg, rgba(30, 41, 59, 0.85));
    border: 2px solid var(--card-border, rgba(148, 163, 184, 0.3));
    color: var(--text-main, inherit);
    font-size: 2.05rem;
    font-weight: 700;
    min-height: 80px;
    padding: 14px;
    border-radius: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    touch-action: manipulation;
    user-select: none;
    -webkit-user-select: none;
    -webkit-tap-highlight-color: transparent;
}
.sys-key:active,
.sys-key.pressed {
    background: var(--accent, #38bdf8);
    color: #0f172a;
}
.sys-key.submit {
    background: var(--success, #22c55e);
    border-color: var(--success, #22c55e);
    color: #0f172a;
    font-size: 1.65rem;
    font-weight: 800;
    letter-spacing: 0.05em;
}
.sys-key.clear {
    background: rgba(239, 68, 68, 0.22);
    border-color: rgba(239, 68, 68, 0.55);
    color: #fca5a5;
    font-size: 1.35rem;
    font-weight: 800;
    letter-spacing: 0.04em;
}
body[data-theme="light"] .sys-key.clear {
    background: rgba(254, 226, 226, 0.9);
    border-color: rgba(220, 38, 38, 0.5);
    color: #b91c1c;
}

/* System Power Actions Card */
.sys-pwr-card {
    width: min(420px, 100%);
    background: var(--card-bg, #0f172a);
    color: var(--text-main, #f8fafc);
    border: 1px solid var(--card-border, rgba(148, 163, 184, 0.35));
    border-radius: 22px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.55);
}
.sys-pwr-item {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    text-align: left;
    gap: 2px;
    width: 100%;
    padding: 12px 14px;
    border-radius: 12px;
    border: 1px solid var(--card-border, rgba(148, 163, 184, 0.35));
    background: var(--control-bg, rgba(30, 41, 59, 0.78));
    color: inherit;
    cursor: pointer;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}
.sys-pwr-item strong { font-size: 0.98rem; }
.sys-pwr-item span { font-size: 0.78rem; opacity: 0.78; }
.sys-pwr-item.warn { border-color: rgba(245, 158, 11, 0.55); }
.sys-pwr-item.danger { background: rgba(185, 28, 28, 0.85); border-color: #ef4444; color: #ffffff; }
body[data-theme="light"] .sys-pwr-item.danger { background: #dc2626; color: #ffffff; }

.sys-pwr-trigger-inline {
    padding: 13px 18px;
    min-height: 46px;
    border-radius: 12px;
    border: 1px solid rgba(148, 163, 184, 0.42);
    background: rgba(15, 23, 42, 0.84);
    color: #f8fafc;
    font-weight: 700;
    font-size: 0.94rem;
    cursor: pointer;
    white-space: nowrap;
    flex-shrink: 0;
    touch-action: manipulation;
    -webkit-tap-highlight-color: transparent;
}
body[data-theme="light"] .sys-pwr-trigger-inline {
    background: #e2e8f0;
    color: #0f172a;
    border-color: rgba(15, 23, 42, 0.22);
}
.sys-pwr-trigger-fixed {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 9990;
}
</style>

<!-- Admin PIN Prompt Modal (matched to TouchPanelLock.ss PIN pad) -->
<div class="sys-pwr-modal" id="sysPowerPinModal">
    <div class="sys-pin-box">
        <div class="sys-pin-header">
            <h2 class="sys-pin-title">ADMIN PIN</h2>
            <button type="button" class="sys-pin-cancel" id="sysPinCancelBtn">Cancel</button>
        </div>
        <div class="sys-pin-display" id="sysPinDots"></div>
        <div class="sys-pin-err" id="sysPinError"></div>
        <div class="sys-keypad" id="sysKeypadGrid">
            <button type="button" class="sys-key" data-digit="1">1</button>
            <button type="button" class="sys-key" data-digit="2">2</button>
            <button type="button" class="sys-key" data-digit="3">3</button>
            <button type="button" class="sys-key" data-digit="4">4</button>
            <button type="button" class="sys-key" data-digit="5">5</button>
            <button type="button" class="sys-key" data-digit="6">6</button>
            <button type="button" class="sys-key" data-digit="7">7</button>
            <button type="button" class="sys-key" data-digit="8">8</button>
            <button type="button" class="sys-key" data-digit="9">9</button>
            <button type="button" class="sys-key clear" data-action="clear">CLEAR</button>
            <button type="button" class="sys-key" data-digit="0">0</button>
            <button type="button" class="sys-key submit" data-action="submit">GO</button>
        </div>
    </div>
</div>

<!-- System & Pi Power Actions Modal -->
<div class="sys-pwr-modal" id="sysPowerModal">
    <div class="sys-pwr-card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <strong style="font-size:1.05rem;">System &amp; Pi Power Controls</strong>
            <button type="button" id="sysPowerCloseBtn" style="border:1px solid rgba(148,163,184,0.4); background:transparent; color:inherit; border-radius:8px; padding:5px 10px; cursor:pointer; font-weight:600; touch-action:manipulation;">Close</button>
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
window._sysPowerState = { csrf: '', authorized: false, verifiedPin: '', pinBuffer: '', pendingAction: '', submitting: false };

function renderSysPinDots() {
    const el = document.getElementById('sysPinDots');
    if (el) el.textContent = '•'.repeat(window._sysPowerState.pinBuffer.length);
}

function promptSystemPinModal(reasonMsg) {
    window._sysPowerState.pinBuffer = '';
    window._sysPowerState.submitting = false;
    renderSysPinDots();
    document.getElementById('sysPinError').textContent = reasonMsg || '';
    document.getElementById('sysPowerPinModal').classList.add('open');
}

function handleSystemButtonTap(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    window._sysPowerState.pendingAction = '';
    const aucCanEdit = !!(window.aucState && window.aucState.activeStation && window.aucState.activeStation.auctioneer && window.aucState.activeStation.auctioneer.canEditSettings);
    if (window._sysPowerState.authorized || window._sysPowerState.verifiedPin !== '' || aucCanEdit) {
        openSystemPowerModal();
        return;
    }
    promptSystemPinModal('');
}

function closeSystemPinModal() {
    document.getElementById('sysPowerPinModal').classList.remove('open');
    window._sysPowerState.pinBuffer = '';
    window._sysPowerState.pendingAction = '';
    window._sysPowerState.submitting = false;
}

function appendSysPin(d) {
    if (window._sysPowerState.submitting || window._sysPowerState.pinBuffer.length >= 8) return;
    window._sysPowerState.pinBuffer += d;
    renderSysPinDots();
}

function clearSysPin() {
    if (window._sysPowerState.submitting) return;
    window._sysPowerState.pinBuffer = '';
    renderSysPinDots();
    document.getElementById('sysPinError').textContent = '';
}

async function submitSysPin() {
    if (window._sysPowerState.submitting) return;
    if (window._sysPowerState.pinBuffer.length === 0) {
        document.getElementById('sysPinError').textContent = 'Enter PIN first';
        return;
    }
    window._sysPowerState.submitting = true;
    const errEl = document.getElementById('sysPinError');
    errEl.textContent = 'Verifying...';
    const csrf = (window.aucState && window.aucState.csrfToken)
        ? window.aucState.csrfToken
        : (window._sysPowerState.csrf || '');
    try {
        const res = await fetch('/api/system-power/verifypin', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf
            },
            body: JSON.stringify({ pin: window._sysPowerState.pinBuffer })
        });
        const raw = await res.text();
        let data;
        try { data = JSON.parse(raw); } catch (e) { throw new Error('HTTP ' + res.status + ': ' + raw.slice(0, 100)); }
        window._sysPowerState.submitting = false;
        if (!data.success) {
            clearSysPin();
            errEl.textContent = data.error || 'Invalid Admin PIN';
            return;
        }
        const pending = window._sysPowerState.pendingAction;
        window._sysPowerState.verifiedPin = window._sysPowerState.pinBuffer;
        window._sysPowerState.pendingAction = '';
        if (data.csrfToken) window._sysPowerState.csrf = data.csrfToken;
        document.getElementById('sysPowerPinModal').classList.remove('open');
        window._sysPowerState.pinBuffer = '';
        openSystemPowerModal();
        if (pending) {
            await triggerSystemPower(pending, true);
        }
    } catch (e) {
        window._sysPowerState.submitting = false;
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
    window._sysPowerState.pendingAction = '';
}

async function triggerSystemPower(action, skipConfirm) {
    const labels = {
        reload: 'Reload the touch panel screen now?',
        restart_app: 'Restart the touch panel web service?',
        reboot_pi: 'Reboot the Raspberry Pi controller now?',
        shutdown_pi: 'Shut down the Raspberry Pi safely now? You will need to cycle power to turn it back on.'
    };
    if (!skipConfirm && !confirm(labels[action] || 'Proceed?')) return;

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
            if (res.status === 401 || res.status === 403 || data.pinRequired) {
                window._sysPowerState.authorized = false;
                window._sysPowerState.verifiedPin = '';
                window._sysPowerState.pendingAction = action;
                document.getElementById('sysPowerModal').classList.remove('open');
                promptSystemPinModal('Session expired — enter Admin PIN to continue');
                return;
            }
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

(function bindFastSystemPinKeypad() {
    const grid = document.getElementById('sysKeypadGrid');
    if (grid) {
        grid.addEventListener('pointerdown', function (e) {
            const btn = e.target.closest('.sys-key');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            btn.classList.add('pressed');
            setTimeout(() => btn.classList.remove('pressed'), 90);
            const digit = btn.getAttribute('data-digit');
            const act = btn.getAttribute('data-action');
            if (digit !== null) appendSysPin(digit);
            else if (act === 'clear') clearSysPin();
            else if (act === 'submit') submitSysPin();
        });
    }

    const cancelBtn = document.getElementById('sysPinCancelBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            e.stopPropagation();
            closeSystemPinModal();
        });
    }

    const closeBtn = document.getElementById('sysPowerCloseBtn');
    if (closeBtn) {
        closeBtn.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            e.stopPropagation();
            closeSystemPowerModal();
        });
    }

    const pinModal = document.getElementById('sysPowerPinModal');
    if (pinModal) {
        pinModal.addEventListener('pointerdown', function (e) {
            if (e.target === pinModal) {
                e.preventDefault();
                closeSystemPinModal();
            }
        });
    }

    const pwrModal = document.getElementById('sysPowerModal');
    if (pwrModal) {
        pwrModal.addEventListener('pointerdown', function (e) {
            if (e.target === pwrModal) {
                e.preventDefault();
                closeSystemPowerModal();
            }
        });
    }
})();

(async function initMainPanelPowerButton() {
    let initialReloadToken = null;

    async function pollSystemStatus(isInitial) {
        try {
            const res = await fetch('/api/system-power/status', { credentials: 'same-origin', cache: 'no-store' });
            const data = await res.json();
            if (!data || !data.success) return null;
            if (typeof data.reloadToken === 'string' && data.reloadToken !== '') {
                if (initialReloadToken === null) {
                    initialReloadToken = data.reloadToken;
                } else if (data.reloadToken !== initialReloadToken) {
                    window.location.reload();
                    return data;
                }
            }
            if (data.csrfToken) window._sysPowerState.csrf = data.csrfToken;
            if (isInitial) window._sysPowerState.authorized = !!data.authorized;
            return data;
        } catch (e) {
            return null;
        }
    }

    // Poll every 15s on all touch panel screens (including Splash & Auctioneer) so Pi kiosk auto-reloads after an admin update
    setInterval(() => { pollSystemStatus(false); }, 15000);

    const data = await pollSystemStatus(true);

    // Auctioneer manages its own topbar button per logged-in user
    if (document.getElementById('aucTopbar')) return;

    const isSplashOrLock = !!document.getElementById('splashTrigger') || window.location.pathname.indexOf('/panel/lock') !== -1;

    try {

        // Show button on all control pages when Admin PIN is active, AND always show on Splash/Lock (prompts for Admin PIN)
        if (!window._sysPowerState.authorized && !isSplashOrLock) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'sysPowerHeaderBtn';
        btn.textContent = isSplashOrLock && !window._sysPowerState.authorized ? 'System Power' : 'System';
        btn.title = 'System & Pi Power Controls';
        btn.className = 'sys-pwr-trigger-inline';
        btn.addEventListener('pointerdown', handleSystemButtonTap);

        // Place inline before the Lock Panel button/link inside the header bar so they never overlap on a 10" screen
        const lockEl = document.querySelector('a[href*="/panel/lock"], button[onclick*="/panel/lock"], .lock-btn, .panel-lock-btn');
        if (lockEl && lockEl.parentElement) {
            lockEl.parentElement.style.display = 'flex';
            lockEl.parentElement.style.alignItems = 'center';
            lockEl.parentElement.style.gap = '10px';
            lockEl.parentElement.insertBefore(btn, lockEl);
        } else {
            const headerActions = document.querySelector('.panel-header-actions, .panel-header, header');
            if (headerActions) {
                headerActions.appendChild(btn);
            } else {
                btn.classList.add('sys-pwr-trigger-fixed');
                document.body.appendChild(btn);
            }
        }
    } catch (e) {}
})();
</script>
