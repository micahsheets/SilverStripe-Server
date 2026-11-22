<div class="cms-content fill-height flexbox-area-grow cms-tabset" data-layout-type="border" data-pjax-fragment="Content">
    <div class="cms-content-header north">
        <div class="cms-content-header-info flexbox-area-grow vertical-align-items">
            <h2 class="cms-content-header-title">AV TouchPanel System Update &amp; Site Configuration</h2>
        </div>
    </div>

    <div class="cms-content-fields center ui-widget-content cms-panel-padded" style="overflow-y: auto;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(460px, 1fr)); gap: 1.5rem; max-width: 1180px; align-items: start;">

            <!-- LEFT COLUMN: Software Patch Updates -->
            <div>
                <% with $CurrentPatchInfo %>
                <div id="current-patch-card" style="margin-bottom: 1.25rem; padding: 1.15rem 1.5rem; background: #f8fafc; border: 1px solid #dbe0e9; border-radius: 6px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <div>
                            <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b;">Current Installed Patch Version</div>
                            <div id="current-patch-version" style="font-size: 1.15rem; font-weight: 700; color: #0f172a; margin-top: 2px;">$Version</div>
                        </div>
                        <span id="current-patch-badge" style="display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; background: #dcfce7; color: #166534;">
                            Active Build
                        </span>
                    </div>
                    <div id="current-patch-meta" style="margin-top: 0.65rem; font-size: 12px; color: #475569; line-height: 1.5;">
                        <% if $InstalledAt %><div><strong>Installed:</strong> $InstalledAt</div><% end_if %>
                        <% if $Created %><div><strong>Built:</strong> $Created</div><% end_if %>
                        <div><strong>Modules:</strong> $ModulesList</div>
                    </div>
                </div>
                <% end_with %>

                <div style="padding: 1.5rem; background: #fff; border: 1px solid #dbe0e9; border-radius: 6px;">
                    <h3 style="margin-top: 0; margin-bottom: 0.5rem; font-size: 1.05rem; color: #0f172a;">1. Software Code Patch</h3>
                    <p style="margin-top: 0; color: #43536d;">
                        Upload a code patch bundle (<code>av-panel-patch-*.zip</code>) to update application files, hardware modules, and rebuild the database schema.
                    </p>

                    <form id="ss-patch-form" enctype="multipart/form-data">
                        <input type="hidden" name="SecurityID" value="$SecurityID" />

                        <div class="form-group" style="margin-bottom: 1.25rem;">
                            <label class="form__field-label" for="patch-file"><strong>Patch Archive (.zip)</strong></label>
                            <input id="patch-file" type="file" name="patch" accept=".zip" required class="form-control" style="padding: 0.5rem;" />
                        </div>

                        <button type="submit" id="patch-submit-btn" class="btn btn-primary font-icon-upload">
                            Install Update &amp; Rebuild Database
                        </button>
                    </form>

                    <pre id="patch-output" style="display: none; margin-top: 1.25rem; padding: 1rem; background: #1e2430; color: #e2e8f0; border-radius: 4px; font-size: 12px; white-space: pre-wrap; max-height: 320px; overflow-y: auto;"></pre>
                </div>
            </div>

            <!-- RIGHT COLUMN: Offline Technician Site Configuration Export & Restore -->
            <div>
                <div style="margin-bottom: 1.25rem; padding: 1.5rem; background: #fff; border: 1px solid #dbe0e9; border-radius: 6px;">
                    <h3 style="margin-top: 0; margin-bottom: 0.5rem; font-size: 1.05rem; color: #0f172a;">2. Export Site Configuration (Bench &rarr; Pi)</h3>
                    <p style="margin-top: 0; color: #43536d;">
                        Download a complete portable backup of all configured <strong>Locations, Devices, IPs, Presets, User PINs, and Facility Logos/Backgrounds</strong> as a single <code>.zip</code> file—no Terminal or PowerShell required.
                    </p>

                    <form id="ss-export-config-form" method="GET" action="/admin/system-update/exportconfig">
                        <input type="hidden" name="SecurityID" value="$SecurityID" />
                        <div class="form-group" style="margin-bottom: 1rem;">
                            <label class="form__field-label" for="export-site-name"><strong>Client / Site Label (for filename)</strong></label>
                            <input id="export-site-name" type="text" name="site_name" placeholder="e.g. Auction-House-Main" class="form-control" style="padding: 0.5rem;" />
                        </div>

                        <button type="submit" class="btn btn-outline-primary font-icon-down-circled">
                            Download Site Configuration (.zip)
                        </button>
                    </form>
                </div>

                <div style="padding: 1.5rem; background: #fff; border: 1px solid #dbe0e9; border-radius: 6px;">
                    <h3 style="margin-top: 0; margin-bottom: 0.5rem; font-size: 1.05rem; color: #0f172a;">3. Restore Site Configuration onto This Panel</h3>
                    <p style="margin-top: 0; color: #43536d;">
                        Upload a previously exported Site Configuration <code>.zip</code> to load all pre-staged rooms, devices, presets, PINs, and branding onto this Raspberry Pi.
                    </p>

                    <form id="ss-import-config-form" enctype="multipart/form-data">
                        <input type="hidden" name="SecurityID" value="$SecurityID" />

                        <div class="form-group" style="margin-bottom: 1.25rem;">
                            <label class="form__field-label" for="site-bundle-file"><strong>Site Configuration Archive (.zip)</strong></label>
                            <input id="site-bundle-file" type="file" name="site_bundle" accept=".zip" required class="form-control" style="padding: 0.5rem;" />
                        </div>

                        <button type="submit" id="import-config-btn" class="btn btn-primary font-icon-sync">
                            Restore Site Configuration
                        </button>
                    </form>

                    <pre id="import-config-output" style="display: none; margin-top: 1.25rem; padding: 1rem; background: #1e2430; color: #e2e8f0; border-radius: 4px; font-size: 12px; white-space: pre-wrap; max-height: 320px; overflow-y: auto;"></pre>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
(function () {
    document.addEventListener('submit', async function (e) {
        if (!e.target) return;

        // Handler A: Code Patch Upload
        if (e.target.id === 'ss-patch-form') {
            e.preventDefault();

            const btn = document.getElementById('patch-submit-btn');
            const out = document.getElementById('patch-output');
            btn.disabled = true;
            btn.textContent = 'Installing Update...';
            out.style.display = 'block';
            out.textContent = 'Uploading archive, extracting files, and running db:build --flush...';

            try {
                const formData = new FormData(e.target);
                const secId = formData.get('SecurityID') || '';
                const res = await fetch('/admin/system-update/upload', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-SecurityID': secId
                    }
                });

                const rawText = await res.text();
                let data;
                try {
                    data = JSON.parse(rawText);
                } catch (parseErr) {
                    out.textContent = `Server returned non-JSON (HTTP ${res.status}):\n\n` + rawText.slice(0, 1200);
                    return;
                }

                if (data.success && data.version) {
                    const verEl = document.getElementById('current-patch-version');
                    const metaEl = document.getElementById('current-patch-meta');
                    if (verEl) verEl.textContent = data.version;
                    if (metaEl) {
                        const mods = Array.isArray(data.modules) && data.modules.length ? data.modules.join(', ') : 'Core only';
                        metaEl.innerHTML =
                            (data.installedAt ? `<div><strong>Installed:</strong> ${data.installedAt}</div>` : '') +
                            (data.created ? `<div><strong>Built:</strong> ${data.created}</div>` : '') +
                            `<div><strong>Modules:</strong> ${mods}</div>`;
                    }
                }

                out.textContent = JSON.stringify(data, null, 2);
            } catch (err) {
                out.textContent = 'Request failed: ' + err.message;
            } finally {
                btn.disabled = false;
                btn.textContent = 'Install Update & Rebuild Database';
            }
            return;
        }

        // Handler B: Site Configuration Restore Upload
        if (e.target.id === 'ss-import-config-form') {
            e.preventDefault();
            if (!confirm('This will replace all current Locations, Devices, Presets, User PINs, and Panel Settings on this unit with the uploaded configuration. Continue?')) {
                return;
            }

            const btn = document.getElementById('import-config-btn');
            const out = document.getElementById('import-config-output');
            btn.disabled = true;
            btn.textContent = 'Restoring Configuration...';
            out.style.display = 'block';
            out.textContent = 'Uploading site configuration archive and restoring database & assets...';

            try {
                const formData = new FormData(e.target);
                const secId = formData.get('SecurityID') || '';
                const res = await fetch('/admin/system-update/importconfig', {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-SecurityID': secId
                    }
                });

                const rawText = await res.text();
                let data;
                try {
                    data = JSON.parse(rawText);
                } catch (parseErr) {
                    out.textContent = `Server returned non-JSON (HTTP ${res.status}):\n\n` + rawText.slice(0, 1200);
                    return;
                }

                out.textContent = JSON.stringify(data, null, 2);
            } catch (err) {
                out.textContent = 'Request failed: ' + err.message;
            } finally {
                btn.disabled = false;
                btn.textContent = 'Restore Site Configuration';
            }
        }
    });
})();
</script>
