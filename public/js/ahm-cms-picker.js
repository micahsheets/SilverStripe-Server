(function () {
    function initAhmPicker() {
        const root = document.getElementById('ahm-cms-picker');
        if (!root || root.dataset.bound === '1') return;
        root.dataset.bound = '1';

        let cfg = {};
        try { cfg = JSON.parse(root.dataset.config || '{}'); } catch (e) {}

        const sectionsEl = document.getElementById('ahm-picker-sections');
        const metaEl = document.getElementById('ahm-sync-meta');
        const syncBtn = document.getElementById('btn-ahm-sync-catalog');
        const allXpointsCb = document.getElementById('ahm-include-all-xpoints');

        if (cfg.fetchedAt && metaEl) {
            metaEl.textContent = 'Last synced from AHM hardware: ' + cfg.fetchedAt + ' — check the channels you want on the touch panel and click Save.';
        }

        const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        const formatDb = (db) => {
            if (db === null || db === undefined) return '';
            const n = Number(db);
            return n <= -85 ? ' (-∞ dB)' : ' (' + (n > 0 ? '+' : '') + n.toFixed(1) + ' dB)';
        };

        function syncToHiddenFields() {
            const writeList = (selector, fieldName) => {
                const lines = [];
                root.querySelectorAll(selector).forEach(row => {
                    const cb = row.querySelector('input[type="checkbox"]');
                    const txt = row.querySelector('input[type="text"]');
                    if (cb && cb.checked) {
                        const label = (txt && txt.value.trim()) ? txt.value.trim() : row.dataset.defaultLabel;
                        lines.push(row.dataset.key + ':' + label);
                    }
                });
                const hidden = document.querySelector('input[name="' + fieldName + '"], textarea[name="' + fieldName + '"]');
                if (hidden) {
                    hidden.value = lines.join('\n');
                    hidden.dispatchEvent(new Event('change', { bubbles: true }));
                }
            };
            writeList('.ahm-row-zone', 'ZonesConfig');
            writeList('.ahm-row-input', 'InputsConfig');
            writeList('.ahm-row-group', 'GroupsConfig');
            writeList('.ahm-row-send', 'SendsConfig');
        }

        function renderGroup(title, items, selectedMap, rowClass, keyField) {
            if (!items || items.length === 0) {
                return `<div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:10px;">
                    <strong style="font-size:13px; color:#0f172a;">${esc(title)}</strong>
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">No active items returned yet. Click "Sync All Channels & Levels from AHM".</div>
                </div>`;
            }
            const rows = items.map(item => {
                const key = String(item[keyField]);
                const sel = selectedMap[key];
                const labelVal = sel ? sel.label : item.label;
                const badge = item.muted ? '<span style="color:#dc2626; font-weight:700;"> [MUTED]</span>' : '';
                return `<div class="${rowClass}" data-key="${esc(key)}" data-default-label="${esc(item.label)}" style="display:flex; align-items:center; gap:8px; padding:4px 6px; border-bottom:1px solid #f1f5f9;">
                    <input type="checkbox" ${sel ? 'checked' : ''} style="width:16px; height:16px; cursor:pointer;">
                    <span style="font-family:monospace; font-size:12px; color:#475569; min-width:54px;">#${esc(key)}</span>
                    <input type="text" value="${esc(labelVal)}" style="flex:1; padding:3px 7px; border:1px solid #cbd5e1; border-radius:4px; font-size:12px;">
                    <span style="font-size:11px; color:#64748b; white-space:nowrap;">${esc(formatDb(item.db))}${badge}</span>
                </div>`;
            }).join('');

            return `<div style="background:#fff; border:1px solid #e2e8f0; border-radius:6px; padding:10px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <strong style="font-size:13px; color:#0f172a;">${esc(title)} (${items.length})</strong>
                    <div style="display:flex; gap:6px;">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle-all="${rowClass}" data-state="1" style="padding:2px 8px; font-size:11px;">Check All</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle-all="${rowClass}" data-state="0" style="padding:2px 8px; font-size:11px;">Uncheck All</button>
                    </div>
                </div>
                <div style="max-height:240px; overflow-y:auto; border:1px solid #f1f5f9; border-radius:4px;">${rows}</div>
            </div>`;
        }

        function renderAll() {
            sectionsEl.innerHTML =
                renderGroup('Output Zones (Zone Volumes & Mutes)', cfg.zones || [], cfg.selectedZones || {}, 'ahm-row-zone', 'num') +
                renderGroup('Input Channels (Input Volumes & Mutes)', cfg.inputs || [], cfg.selectedInputs || {}, 'ahm-row-input', 'num') +
                renderGroup('Control Groups / DCAs', cfg.groups || [], cfg.selectedGroups || {}, 'ahm-row-group', 'num') +
                renderGroup('Crosspoint Sends (Input → Zone Volumes & Mutes)', cfg.sends || [], cfg.selectedSends || {}, 'ahm-row-send', 'key');

            sectionsEl.querySelectorAll('input[type="checkbox"], input[type="text"]').forEach(el => {
                el.addEventListener('change', syncToHiddenFields);
                el.addEventListener('input', syncToHiddenFields);
            });
            sectionsEl.querySelectorAll('[data-toggle-all]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const state = btn.dataset.state === '1';
                    root.querySelectorAll('.' + btn.dataset.toggleAll + ' input[type="checkbox"]').forEach(cb => { cb.checked = state; });
                    syncToHiddenFields();
                });
            });
        }

        renderAll();

        if (syncBtn) {
            syncBtn.addEventListener('click', async () => {
                if (!cfg.deviceId) {
                    alert('Please click Save / Create on this AHM processor first so it has a database ID, then click Sync.');
                    return;
                }
                syncBtn.disabled = true;
                syncBtn.textContent = 'Querying AHM…';
                try {
                    const res = await fetch('/api/ahm/sync-catalog', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ device_id: cfg.deviceId, include_all_xpoints: !!(allXpointsCb && allXpointsCb.checked) })
                    });
                    const data = await res.json();
                    if (!res.ok || !data.success) throw new Error(data.error || ('HTTP ' + res.status));
                    cfg.zones = data.catalog.zones || [];
                    cfg.inputs = data.catalog.inputs || [];
                    cfg.groups = data.catalog.groups || [];
                    cfg.sends = data.catalog.sends || [];
                    cfg.fetchedAt = data.catalog.fetchedAt || '';
                    if (metaEl) metaEl.textContent = 'Synced live from AHM at ' + cfg.fetchedAt + ' — check the items you want and click Save.';
                    renderAll();
                } catch (e) {
                    alert('AHM Sync failed: ' + e.message);
                } finally {
                    syncBtn.disabled = false;
                    syncBtn.textContent = 'Sync All Channels & Levels from AHM';
                }
            });
        }
    }

    initAhmPicker();
    new MutationObserver(initAhmPicker).observe(document.documentElement, { childList: true, subtree: true });
})();
