<div class="cms-content fill-height flexbox-area-grow" data-layout-type="border" data-pjax-fragment="Content">
    <div class="cms-content-header north" style="padding:14px 20px; border-bottom:1px solid #dbe0e9; background:#f8fafc;">
        <div class="cms-content-header-info flexbox-area-grow vertical-align-items" style="display:flex; align-items:center; justify-content:space-between; gap:12px; max-width:1040px;">
            <h2 class="cms-content-header-title" style="margin:0;">System Backup, Snapshots &amp; Baseline Reset</h2>
            <a href="/admin" onclick="if (window.history.length > 1 && document.referrer.indexOf('/admin') !== -1) { event.preventDefault(); window.history.back(); }" class="btn btn-outline-secondary font-icon-left-open" style="display:inline-flex; align-items:center; gap:6px; text-decoration:none; padding:7px 14px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; color:#1e293b; font-weight:600; font-size:13px;">
                &larr; Back to Admin
            </a>
        </div>
    </div>

    <div class="cms-content-fields center ui-widget-content" style="padding:20px; overflow-y:auto;">
        <div style="max-width:1040px; display:flex; flex-direction:column; gap:20px;">

            <!-- SECTION 1: NAMED SNAPSHOTS & TECH BASELINE RESET -->
            <div style="background:#fff; border:1px solid #dbe0e9; border-radius:8px; padding:18px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:14px;">
                    <div>
                        <h3 style="margin:0 0 4px 0;">1. Server Snapshots &amp; Tech Baseline Reset</h3>
                        <p style="margin:0; color:#556070; font-size:13px;">
                            Snapshots are stored locally on this server (<code>var/system-snapshots/</code>). Select any snapshot below to load it and work on it, save your updates back into it, or reset to your Tech Baseline.
                        </p>
                    </div>
                    <div id="bkBaselineBanner">
                        <% if $DefaultBaselineSnapshot %>
                        <div style="display:flex; align-items:center; gap:10px; background:#fefce8; border:1px solid #fde047; padding:8px 12px; border-radius:6px;">
                            <div style="font-size:12px; color:#854d0e;">
                                <strong>Tech Baseline:</strong> $DefaultBaselineSnapshot.Name
                            </div>
                            <button type="button" class="btn btn-warning btn-sm font-icon-sync" onclick="loadNamedSnapshot('$DefaultBaselineSnapshot.ID', '$DefaultBaselineSnapshot.Name.JS', true)">
                                Reset to Baseline
                            </button>
                        </div>
                        <% end_if %>
                    </div>
                </div>

                <!-- QUICK SELECT & LOAD BAR -->
                <div id="bkQuickSelectBar" style="display:<% if $SavedSnapshotsList %>flex<% else %>none<% end_if %>; gap:10px; flex-wrap:wrap; align-items:center; background:#eff6ff; border:1px solid #bfdbfe; border-radius:6px; padding:12px; margin-bottom:12px;">
                    <label for="bkQuickSelect" style="font-size:12px; font-weight:700; color:#1e3a8a; margin:0; white-space:nowrap;">Select Snapshot to Work On:</label>
                    <select id="bkQuickSelect" class="form-control" style="flex:1; min-width:240px;">
                        <% loop $SavedSnapshotsList %>
                        <option value="$ID" data-name="$Name" <% if $IsActive %>selected<% end_if %>>
                            $Name<% if $IsBaseline %> [Default Tech Baseline]<% end_if %><% if $IsActive %> [Currently Loaded]<% end_if %> — $TotalRecords records ($CreatedAt)
                        </option>
                        <% end_loop %>
                    </select>
                    <button type="button" class="btn btn-primary font-icon-sync" onclick="loadSelectedQuickSnapshot()">Load Selected Snapshot</button>
                    <button type="button" class="btn btn-outline-primary font-icon-save" onclick="overwriteSelectedQuickSnapshot()">Save Current Changes to Selected</button>
                </div>

                <!-- SAVE NEW NAMED SNAPSHOT FORM -->
                <form id="bkSaveSnapshotForm" onsubmit="saveNamedSnapshot(event)" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:12px; margin-bottom:14px;">
                    <input type="text" id="bkSnapshotName" class="form-control" placeholder="New snapshot name (e.g. Clean Tech Baseline, Client Room A)" style="flex:1; min-width:240px;" required>
                    <label style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; color:#334155; margin:0; cursor:pointer;">
                        <input type="checkbox" id="bkSnapshotSetBaseline" value="1">
                        Set as Default Tech Baseline
                    </label>
                    <button type="submit" id="bkSaveSnapshotBtn" class="btn btn-success font-icon-plus">Save as New Snapshot</button>
                </form>

                <div id="bkSnapshotStatus" style="margin-bottom:10px; font-size:13px; font-weight:600;"></div>

                <!-- SAVED SNAPSHOTS LIST -->
                <div id="bkSnapshotsContainer" style="border:1px solid #e2e8f0; border-radius:6px; overflow:hidden;">
                    <% if $SavedSnapshotsList %>
                        <% loop $SavedSnapshotsList %>
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:10px 14px; border-bottom:1px solid #f1f5f9; background:<% if $IsBaseline %>#fefce8<% else_if $IsActive %>#f0f9ff<% else %>#fff<% end_if %>;">
                            <div>
                                <div style="font-weight:700; font-size:13px; color:#0f172a;">
                                    $Name
                                    <% if $IsBaseline %>
                                        <span style="background:#fef08a; color:#854d0e; font-size:11px; padding:2px 7px; border-radius:4px; margin-left:6px;">Default Tech Baseline</span>
                                    <% end_if %>
                                    <% if $IsActive %>
                                        <span style="background:#dbeafe; color:#1e40af; font-size:11px; padding:2px 7px; border-radius:4px; margin-left:6px;">Active / Last Loaded</span>
                                    <% end_if %>
                                </div>
                                <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                    Saved $CreatedAt &bull; $TotalRecords records &bull; $AssetsCount assets &bull; $SizeKB KB ($ModulesText)
                                </div>
                            </div>
                            <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary btn-sm" onclick="loadNamedSnapshot('$ID', '$Name.JS', false)">Load Snapshot</button>
                                <button type="button" class="btn btn-outline-primary btn-sm" onclick="overwriteNamedSnapshot('$ID', '$Name.JS')">Update / Save Here</button>
                                <% if not $IsBaseline %>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setSnapshotBaseline('$ID')">Set as Baseline</button>
                                <% end_if %>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteNamedSnapshot('$ID', '$Name.JS')">Delete</button>
                            </div>
                        </div>
                        <% end_loop %>
                    <% else %>
                        <div style="padding:16px; font-size:13px; color:#64748b; text-align:center;">
                            No server snapshots saved yet. Enter a name above to capture your current database as a starting baseline.
                        </div>
                    <% end_if %>
                </div>
            </div>

            <!-- SECTION 2 & 3: PORTABLE FILE EXPORT & RESTORE -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(400px, 1fr)); gap:20px;">

                <div style="background:#fff; border:1px solid #dbe0e9; border-radius:8px; padding:18px;">
                    <h3 style="margin-top:0;">2. Download Portable Backup (.zip)</h3>
                    <p style="color:#556070; font-size:13px;">
                        Export selected modules and uploaded branding assets to your computer as a portable <code>.zip</code> archive.
                    </p>

                    <form id="bkExportForm" onsubmit="downloadBackupArchive(event)">
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
                            <input type="checkbox" id="bkIncludeAssets" name="include_assets" value="1" checked>
                            Include uploaded branding logos &amp; background wallpapers
                        </label>

                        <button type="submit" id="bkDownloadBtn" class="btn btn-primary font-icon-down-circled">Download Backup (.zip)</button>
                        <div id="bkExportStatus" style="margin-top:10px; font-size:13px; font-weight:600;"></div>
                    </form>
                </div>

                <div style="background:#fff; border:1px solid #dbe0e9; border-radius:8px; padding:18px;">
                    <h3 style="margin-top:0;">3. Restore from Backup File (.zip)</h3>
                    <p style="color:#556070; font-size:13px;">
                        Upload a portable <code>.zip</code> backup archive from another system to inspect and restore selected modules.
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
</div>

<script>
const BK_CSRF = '$CsrfToken';

function escHtml(s) {
    return String(s || '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}

function renderSnapshotsUI(snapshots, baselineId, activeId) {
    const container = document.getElementById('bkSnapshotsContainer');
    const banner = document.getElementById('bkBaselineBanner');
    const quickBar = document.getElementById('bkQuickSelectBar');
    const quickSel = document.getElementById('bkQuickSelect');
    if (!container) return;

    const baseSnap = (snapshots || []).find(s => s.isBaseline || s.id === baselineId);
    if (banner) {
        if (baseSnap) {
            banner.innerHTML = `
                <div style="display:flex; align-items:center; gap:10px; background:#fefce8; border:1px solid #fde047; padding:8px 12px; border-radius:6px;">
                    <div style="font-size:12px; color:#854d0e;"><strong>Tech Baseline:</strong> ${escHtml(baseSnap.name)}</div>
                    <button type="button" class="btn btn-warning btn-sm font-icon-sync" onclick="loadNamedSnapshot('${escHtml(baseSnap.id)}', '${escHtml(baseSnap.name)}', true)">Reset to Baseline</button>
                </div>`;
        } else {
            banner.innerHTML = '';
        }
    }

    if (quickBar && quickSel) {
        if (snapshots && snapshots.length > 0) {
            quickBar.style.display = 'flex';
            quickSel.innerHTML = snapshots.map(s => {
                const isBase = Boolean(s.isBaseline || s.id === baselineId);
                const isAct = Boolean(s.isActive || s.id === activeId);
                const tags = (isBase ? ' [Default Tech Baseline]' : '') + (isAct ? ' [Currently Loaded]' : '');
                return `<option value="${escHtml(s.id)}" data-name="${escHtml(s.name)}" ${isAct ? 'selected' : ''}>${escHtml(s.name)}${tags} — ${Number(s.totalRecords || 0)} records</option>`;
            }).join('');
        } else {
            quickBar.style.display = 'none';
            quickSel.innerHTML = '';
        }
    }

    if (!snapshots || snapshots.length === 0) {
        container.innerHTML = '<div style="padding:16px; font-size:13px; color:#64748b; text-align:center;">No server snapshots saved yet. Enter a name above to capture your current database as a starting baseline.</div>';
        return;
    }

    container.innerHTML = snapshots.map(s => {
        const isBase = Boolean(s.isBaseline || s.id === baselineId);
        const isAct = Boolean(s.isActive || s.id === activeId);
        const dt = s.createdAt ? new Date(s.createdAt).toLocaleString() : '';
        const mods = Array.isArray(s.modules) ? s.modules.join(', ') : '';
        const bg = isBase ? '#fefce8' : (isAct ? '#f0f9ff' : '#fff');
        return `
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; padding:10px 14px; border-bottom:1px solid #f1f5f9; background:${bg};">
                <div>
                    <div style="font-weight:700; font-size:13px; color:#0f172a;">
                        ${escHtml(s.name)}
                        ${isBase ? '<span style="background:#fef08a; color:#854d0e; font-size:11px; padding:2px 7px; border-radius:4px; margin-left:6px;">Default Tech Baseline</span>' : ''}
                        ${isAct ? '<span style="background:#dbeafe; color:#1e40af; font-size:11px; padding:2px 7px; border-radius:4px; margin-left:6px;">Active / Last Loaded</span>' : ''}
                    </div>
                    <div style="font-size:11px; color:#64748b; margin-top:2px;">
                        Saved ${escHtml(dt)} &bull; ${Number(s.totalRecords || 0)} records &bull; ${Number(s.assetsCount || 0)} assets &bull; ${Number(s.sizeKB || 1)} KB (${escHtml(mods)})
                    </div>
                </div>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" class="btn btn-primary btn-sm" onclick="loadNamedSnapshot('${escHtml(s.id)}', '${escHtml(s.name)}', false)">Load Snapshot</button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="overwriteNamedSnapshot('${escHtml(s.id)}', '${escHtml(s.name)}')">Update / Save Here</button>
                    ${!isBase ? `<button type="button" class="btn btn-outline-secondary btn-sm" onclick="setSnapshotBaseline('${escHtml(s.id)}')">Set as Baseline</button>` : ''}
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteNamedSnapshot('${escHtml(s.id)}', '${escHtml(s.name)}')">Delete</button>
                </div>
            </div>`;
    }).join('');
}

function loadSelectedQuickSnapshot() {
    const sel = document.getElementById('bkQuickSelect');
    if (!sel || !sel.value) return;
    const opt = sel.options[sel.selectedIndex];
    const name = opt ? (opt.dataset.name || opt.textContent) : sel.value;
    loadNamedSnapshot(sel.value, name, false);
}

function overwriteSelectedQuickSnapshot() {
    const sel = document.getElementById('bkQuickSelect');
    if (!sel || !sel.value) return;
    const opt = sel.options[sel.selectedIndex];
    const name = opt ? (opt.dataset.name || opt.textContent) : sel.value;
    overwriteNamedSnapshot(sel.value, name);
}

async function saveNamedSnapshot(e) {
    e.preventDefault();
    const nameInput = document.getElementById('bkSnapshotName');
    const baseChk = document.getElementById('bkSnapshotSetBaseline');
    const btn = document.getElementById('bkSaveSnapshotBtn');
    const status = document.getElementById('bkSnapshotStatus');

    const name = (nameInput.value || '').trim();
    if (!name) return;

    const fd = new FormData();
    fd.append('SecurityID', BK_CSRF);
    fd.append('snapshot_name', name);
    fd.append('set_as_baseline', baseChk.checked ? '1' : '0');
    fd.append('include_assets', document.getElementById('bkIncludeAssets')?.checked ? '1' : '0');
    document.querySelectorAll('.bk-export-chk').forEach(cb => fd.append('modules[]', cb.value));

    btn.disabled = true;
    status.style.color = '#1e40af';
    status.textContent = 'Saving snapshot "' + name + '"...';

    try {
        const res = await fetch('/admin/system-backup/snapshot-save', { method: 'POST', credentials: 'same-origin', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to save snapshot');

        nameInput.value = '';
        baseChk.checked = false;
        status.style.color = '#16a34a';
        status.textContent = 'Saved snapshot "' + data.snapshot.name + '" (' + data.snapshot.totalRecords + ' records).';
        renderSnapshotsUI(data.snapshots, data.baselineId, data.activeId);
    } catch (err) {
        status.style.color = '#dc2626';
        status.textContent = 'Snapshot error: ' + err.message;
    } finally {
        btn.disabled = false;
    }
}

async function overwriteNamedSnapshot(id, name) {
    if (!confirm('Overwrite snapshot "' + name + '" with the current live database state?')) return;

    const status = document.getElementById('bkSnapshotStatus');
    status.style.color = '#1e40af';
    status.textContent = 'Updating snapshot "' + name + '"...';

    try {
        const fd = new FormData();
        fd.append('SecurityID', BK_CSRF);
        fd.append('snapshot_id', id);
        fd.append('include_assets', document.getElementById('bkIncludeAssets')?.checked ? '1' : '0');
        document.querySelectorAll('.bk-export-chk').forEach(cb => fd.append('modules[]', cb.value));

        const res = await fetch('/admin/system-backup/snapshot-update', { method: 'POST', credentials: 'same-origin', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to update snapshot');

        status.style.color = '#16a34a';
        status.textContent = 'Updated snapshot "' + data.snapshot.name + '" with current live state (' + data.snapshot.totalRecords + ' records).';
        renderSnapshotsUI(data.snapshots, data.baselineId, data.activeId);
    } catch (err) {
        status.style.color = '#dc2626';
        status.textContent = 'Update error: ' + err.message;
    }
}

async function loadNamedSnapshot(id, name, isBaselineReset) {
    const promptMsg = isBaselineReset
        ? 'RESET SYSTEM TO TECH BASELINE "' + name + '"?\n\nThis will wipe current device/module changes and restore the database back to your saved Tech Baseline.'
        : 'Load snapshot "' + name + '"?\n\nThis will replace current database records with the saved snapshot state so you can work on it.';
    if (!confirm(promptMsg)) return;

    const status = document.getElementById('bkSnapshotStatus');
    status.style.color = '#1e40af';
    status.textContent = 'Loading "' + name + '"...';

    try {
        const fd = new FormData();
        fd.append('SecurityID', BK_CSRF);
        fd.append('snapshot_id', id);
        fd.append('restore_mode', 'replace');

        const res = await fetch('/admin/system-backup/snapshot-load', { method: 'POST', credentials: 'same-origin', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Load failed');

        status.style.color = '#16a34a';
        status.textContent = 'Loaded "' + name + '" (' + data.stats.recordsRestored + ' records, ' + data.stats.assetsRestored + ' assets). Reloading admin...';
        setTimeout(() => window.location.reload(), 900);
    } catch (err) {
        status.style.color = '#dc2626';
        status.textContent = 'Load failed: ' + err.message;
    }
}

async function setSnapshotBaseline(id) {
    const status = document.getElementById('bkSnapshotStatus');
    try {
        const fd = new FormData();
        fd.append('SecurityID', BK_CSRF);
        fd.append('snapshot_id', id);

        const res = await fetch('/admin/system-backup/snapshot-base', { method: 'POST', credentials: 'same-origin', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unable to set baseline');

        status.style.color = '#16a34a';
        status.textContent = 'Updated Default Tech Baseline.';
        renderSnapshotsUI(data.snapshots, data.baselineId, data.activeId);
    } catch (err) {
        status.style.color = '#dc2626';
        status.textContent = err.message;
    }
}

async function deleteNamedSnapshot(id, name) {
    if (!confirm('Delete saved snapshot "' + name + '"?')) return;
    const status = document.getElementById('bkSnapshotStatus');
    try {
        const fd = new FormData();
        fd.append('SecurityID', BK_CSRF);
        fd.append('snapshot_id', id);

        const res = await fetch('/admin/system-backup/snapshot-delete', { method: 'POST', credentials: 'same-origin', body: fd });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Unable to delete snapshot');

        status.style.color = '#475569';
        status.textContent = 'Deleted snapshot "' + name + '".';
        renderSnapshotsUI(data.snapshots, data.baselineId, data.activeId);
    } catch (err) {
        status.style.color = '#dc2626';
        status.textContent = err.message;
    }
}

function toggleBackupBoxes(scope, mode) {
    document.querySelectorAll('.bk-' + scope + '-chk').forEach(function(cb) {
        if (mode === 'all') cb.checked = true;
        else if (mode === 'none') cb.checked = false;
        else if (mode === 'inuse') cb.checked = cb.dataset.inuse === '1';
    });
}

async function downloadBackupArchive(e) {
    e.preventDefault();
    const form = document.getElementById('bkExportForm');
    const btn = document.getElementById('bkDownloadBtn');
    const status = document.getElementById('bkExportStatus');
    const selected = Array.from(form.querySelectorAll('.bk-export-chk:checked'));

    if (selected.length === 0) {
        status.style.color = '#dc2626';
        status.textContent = 'Please select at least one module to back up.';
        return;
    }

    btn.disabled = true;
    status.style.color = '#1e40af';
    status.textContent = 'Building backup archive...';

    try {
        const fd = new FormData(form);
        const res = await fetch('/api/system-backup/export', { method: 'POST', credentials: 'same-origin', body: fd });
        const contentType = res.headers.get('Content-Type') || '';
        if (!res.ok || contentType.indexOf('application/zip') === -1) {
            let msg = 'HTTP ' + res.status;
            const raw = await res.text();
            try {
                const j = JSON.parse(raw);
                if (j && j.error) msg = j.error;
            } catch (err) {
                msg = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180) || msg;
            }
            throw new Error(msg);
        }

        const blob = await res.blob();
        const disp = res.headers.get('Content-Disposition') || '';
        const match = disp.match(/filename="([^"]+)"/i);
        const filename = match ? match[1] : 'av-control-backup.zip';

        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 4000);

        status.style.color = '#16a34a';
        status.textContent = 'Downloaded ' + filename + ' (' + Math.max(1, Math.round(blob.size / 1024)) + ' KB).';
    } catch (err) {
        status.style.color = '#dc2626';
        status.textContent = 'Backup failed: ' + err.message;
    } finally {
        btn.disabled = false;
    }
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
    fd.append('SecurityID', BK_CSRF);
    fd.append('backup_zip', fileInput.files[0]);

    header.textContent = 'Inspecting backup archive...';
    box.style.display = 'block';
    list.innerHTML = '';

    try {
        const res = await fetch('/api/system-backup/inspect', { method: 'POST', credentials: 'same-origin', body: fd });
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
        return;
    }

    const fd = new FormData();
    fd.append('SecurityID', BK_CSRF);
    fd.append('backup_zip', fileInput.files[0]);
    fd.append('restore_mode', document.getElementById('bkRestoreMode').value);
    selected.forEach(k => fd.append('modules[]', k));

    btn.disabled = true;
    status.style.color = '#1e40af';
    status.textContent = 'Restoring modules...';

    try {
        const res = await fetch('/api/system-backup/restore', { method: 'POST', credentials: 'same-origin', body: fd });
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
