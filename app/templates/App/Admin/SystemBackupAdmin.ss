<div class="cms-content fill-height flexbox-area-grow" data-layout-type="border" data-pjax-fragment="Content">
    <div class="cms-content-header north" style="padding:14px 20px; border-bottom:1px solid #dbe0e9; background:#f8fafc;">
        <div class="cms-content-header-info flexbox-area-grow vertical-align-items" style="display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:960px;">
            <h2 class="cms-content-header-title" style="margin:0;">System Backup &amp; Restore</h2>
            <a href="/admin" onclick="if (window.history.length > 1 && document.referrer.indexOf('/admin') !== -1) { event.preventDefault(); window.history.back(); }" class="btn btn-outline-secondary font-icon-left-open" style="display:inline-flex; align-items:center; gap:6px; text-decoration:none; padding:7px 14px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#1e293b; font-weight:600; font-size:13px;">
                &larr; Back to Admin
            </a>
        </div>
    </div>

    <div class="cms-content-fields center ui-widget-content" style="padding:20px; overflow-y:auto;">
        <div style="max-width:960px; display:grid; grid-template-columns:repeat(auto-fit, minmax(400px, 1fr)); gap:20px;">

            <div style="background:#fff; border:1px solid #dbe0e9; border-radius:8px; padding:18px;">
                <h3 style="margin-top:0;">1. Create Backup Archive (.zip)</h3>
                <p style="color:#556070; font-size:13px;">
                    Modules currently enabled on this panel are pre-selected automatically. You can include all installed modules or only the specific modules in use.
                </p>

                <form method="POST" action="/admin/system-backup/export" target="_blank">
                    <input type="hidden" name="SecurityID" value="$CsrfToken">

                    <div style="display:flex; gap:8px; margin-bottom:10px;">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleBackupBoxes('export', 'all')">Select All Installed</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleBackupBoxes('export', 'inuse')">Select In-Use Only</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleBackupBoxes('export', 'none')">Clear</button>
                    </div>

                    <div style="border:1px solid #e2e8f0; border-radius:6px; padding:10px; max-height:320px; overflow-y:auto; margin-bottom:12px;">
                        <% loop $DiscoverableModulesList %>
                        <label style="display:flex; align-items:flex-start; gap:10px; padding:7px 4px; border-bottom:1px solid #f1f5f9; cursor:pointer;">
                            <input type="checkbox" class="bk-export-chk" data-inuse="<% if $InUse %>1<% else %>0<% end_if %>" name="modules[]" value="$Key" <% if $InUse %>checked<% end_if %> style="margin-top:3px;">
                            <div style="flex:1;">
                                <div style="font-weight:600; font-size:13px;">
                                    $Label
                                    <% if $InUse %>
                                        <span style="background:#dcfce7; color:#166534; font-size:11px; padding:1px 6px; border-radius:4px; margin-left:4px;">In Use</span>
                                    <% else %>
                                        <span style="background:#f1f5f9; color:#64748b; font-size:11px; padding:1px 6px; border-radius:4px; margin-left:4px;">Installed</span>
                                    <% end_if %>
                                </div>
                                <div style="font-size:11px; color:#64748b;">$Records records ($Models)</div>
                            </div>
                        </label>
                        <% end_loop %>
                    </div>

                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; margin-bottom:14px;">
                        <input type="checkbox" name="include_assets" value="1" checked>
                        Include uploaded branding logos &amp; background wallpapers
                    </label>

                    <button type="submit" class="btn btn-primary font-icon-down-circled">Download Backup (.zip)</button>
                </form>
            </div>

            <div style="background:#fff; border:1px solid #dbe0e9; border-radius:8px; padding:18px;">
                <h3 style="margin-top:0;">2. Restore from Backup (.zip)</h3>
                <p style="color:#556070; font-size:13px;">
                    Choose a backup archive to inspect its contents and restore all backed-up modules or only selected modules.
                </p>

                <div style="margin-bottom:12px;">
                    <input type="file" id="bkRestoreFile" accept=".zip" class="form-control" onchange="inspectBackupArchive()">
                </div>

                <div id="bkManifestBox" style="display:none; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:12px; background:#f8fafc;">
                    <div id="bkManifestHeader" style="font-size:12px; font-weight:600; margin-bottom:8px; color:#1e293b;"></div>
                    <div id="bkRestoreModulesList" style="display:flex; flex-direction:column; gap:6px; margin-bottom:10px;"></div>

                    <div style="margin-top:10px;">
                        <label style="font-size:12px; font-weight:600; display:block; margin-bottom:4px;">Restore Mode</label>
                        <select id="bkRestoreMode" class="form-control">
                            <option value="replace">Clean Replace (Replace existing data in selected modules)</option>
                            <option value="merge">Merge / Update (Keep existing records and update/add from backup)</option>
                        </select>
                    </div>
                </div>

                <button type="button" id="bkRestoreBtn" class="btn btn-warning font-icon-sync" disabled onclick="executeBackupRestore()">Restore Selected Modules</button>
                <div id="bkRestoreStatus" style="margin-top:12px; font-size:13px; font-weight:600;"></div>
            </div>

        </div>
    </div>
</div>

<script>
function toggleBackupBoxes(scope, mode) {
    document.querySelectorAll('.bk-' + scope + '-chk').forEach(function(cb) {
        if (mode === 'all') cb.checked = true;
        else if (mode === 'none') cb.checked = false;
        else if (mode === 'inuse') cb.checked = cb.dataset.inuse === '1';
    });
}

async function inspectBackupArchive() {
    const fileInput = document.getElementById('bkRestoreFile');
    const box = document.getElementById('bkManifestBox');
    const list = document.getElementById('bkRestoreModulesList');
    const header = document.getElementById('bkManifestHeader');
    const btn = document.getElementById('bkRestoreBtn');
    const status = document.getElementById('bkRestoreStatus');

    status.textContent = '';
    if (!fileInput.files || !fileInput.files[0]) {
        box.style.display = 'none';
        btn.disabled = true;
        return;
    }

    const fd = new FormData();
    fd.append('SecurityID', '$CsrfToken');
    fd.append('backup_zip', fileInput.files[0]);

    header.textContent = 'Inspecting backup archive...';
    box.style.display = 'block';
    list.innerHTML = '';

    try {
        const res = await fetch('/admin/system-backup/inspect', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) {
            header.textContent = 'Error: ' + (data.error || 'Invalid archive');
            btn.disabled = true;
            return;
        }

        const m = data.manifest;
        header.textContent = 'Backup created ' + (m.createdAt || '') + ' from ' + (m.host || 'unknown host');
        list.innerHTML = '';

        Object.keys(m.modules || {}).forEach(function(modKey) {
            const mod = m.modules[modKey];
            const total = (mod.classes || []).reduce((acc, c) => acc + (c.count || 0), 0);
            const lbl = document.createElement('label');
            lbl.style.cssText = 'display:flex; align-items:center; gap:8px; font-size:13px; cursor:pointer;';
            lbl.innerHTML = `<input type="checkbox" class="bk-restore-chk" value="${modKey}" checked> <span><strong>${mod.label || modKey}</strong> (${total} records)</span>`;
            list.appendChild(lbl);
        });

        btn.disabled = false;
    } catch (e) {
        header.textContent = 'Inspection error: ' + e.message;
        btn.disabled = true;
    }
}

async function executeBackupRestore() {
    const fileInput = document.getElementById('bkRestoreFile');
    const status = document.getElementById('bkRestoreStatus');
    const btn = document.getElementById('bkRestoreBtn');
    if (!fileInput.files || !fileInput.files[0]) return;

    const selected = Array.from(document.querySelectorAll('.bk-restore-chk:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        status.style.color = '#dc2626';
        status.textContent = 'Please select at least one module to restore.';
        return;
    }

    if (!confirm('Restore ' + selected.join(', ') + '? This will update the database with the selected backup data.')) {
        returnclear    }

    const fd = new FormData();
    fd.append('SecurityID', '$CsrfToken');
    fd.append('backup_zip', fileInput.files[0]);
    fd.append('restore_mode', document.getElementById('bkRestoreMode').value);
    selected.forEach(k => fd.append('modules[]', k));

    btn.disabled = true;
    status.style.color = '#1e40af';
    status.textContent = 'Restoring modules...';

    try {
        const res = await fetch('/admin/system-backup/restore', { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) {
            status.style.color = '#dc2626';
            status.textContent = 'Restore failed: ' + (data.error || 'Unknown error');
            btn.disabled = false;
            return;
        }
        status.style.color = '#16a34a';
        status.textContent = `Restored ${data.stats.recordsRestored} records and ${data.stats.assetsRestored} assets across: ${data.stats.modulesRestored.join(', ')}.`;
        btn.disabled = false;
    } catch (e) {
        status.style.color = '#dc2626';
        status.textContent = 'Restore error: ' + e.message;
        btn.disabled = false;
    }
}
</script>
