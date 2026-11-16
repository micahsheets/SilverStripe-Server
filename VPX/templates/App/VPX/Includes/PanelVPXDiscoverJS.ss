<script>
let csrfToken = '';
let locations = [];
let discovered = [];

function showToast(msg, isError = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = isError ? 'show error' : 'show';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.className = ''; }, 3500);
}

async function initDiscover() {
    const res = await fetch('/api/vpx/status');
    const data = await res.json();
    if (data.success) {
        csrfToken = data.csrfToken || '';
        locations = data.locations || [];
    }
}

async function runScan() {
    const btn = document.getElementById('scanBtn');
    const subnet = document.getElementById('subnetInput').value.trim();
    btn.disabled = true;
    btn.textContent = 'Scanning...';

    try {
        const res = await fetch('/api/vpx/discover', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({ subnet })
        });
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Scan failed');

        discovered = data.devices || [];
        renderResults();
        showToast('Found ' + discovered.length + ' VPX device(s)');
    } catch (e) {
        showToast(e.message, true);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Scan Network (Broadcast + Subnet)';
    }
}

function renderResults() {
    const table = document.getElementById('resultsTable');
    const body = document.getElementById('resultsBody');
    const importBtn = document.getElementById('importBtn');
    body.innerHTML = '';

    if (discovered.length === 0) {
        table.style.display = 'none';
        importBtn.style.display = 'none';
        return;
    }

    table.style.display = 'table';
    importBtn.style.display = 'inline-block';

    discovered.forEach((dev, idx) => {
        const tr = document.createElement('tr');
        const locOptions = '<option value="0">-- Unassigned --</option>' +
            locations.map(l => `<option value="${l.id}">${l.title}</option>`).join('');

        tr.innerHTML = `
            <td><input type="checkbox" class="row-chk" data-idx="${idx}" ${!dev.alreadyExists ? 'checked' : ''}></td>
            <td><strong>${dev.ip}</strong><br><small style="color:var(--text-muted)">${dev.mac || ''}</small></td>
            <td><span class="status-pill ${dev.alreadyExists ? 'status-exists' : 'status-new'}">${dev.alreadyExists ? 'In DB' : 'New'}</span></td>
            <td><input type="text" class="tbl-input" id="title_${idx}" value="${dev.title}"></td>
            <td><input type="text" class="tbl-input" id="host_${idx}" value="${dev.hostname}"></td>
            <td>
                <select class="tbl-select" id="mode_${idx}">
                    <option value="Decoder" ${dev.mode === 'Decoder' ? 'selected' : ''}>Decoder</option>
                    <option value="Encoder" ${dev.mode === 'Encoder' ? 'selected' : ''}>Encoder</option>
                </select>
            </td>
            <td><select class="tbl-select" id="loc_${idx}">${locOptions}</select></td>
        `;
        body.appendChild(tr);
    });
}

function toggleAllRows(checked) {
    document.querySelectorAll('.row-chk').forEach(cb => { cb.checked = checked; });
}

async function importSelected() {
    const selected = [];
    document.querySelectorAll('.row-chk:checked').forEach(cb => {
        const idx = Number(cb.dataset.idx);
        const base = discovered[idx];
        selected.push({
            ip: base.ip,
            udpPort: base.udpPort,
            mac: base.mac,
            title: document.getElementById('title_' + idx).value.trim(),
            hostname: document.getElementById('host_' + idx).value.trim(),
            mode: document.getElementById('mode_' + idx).value,
            locationId: Number(document.getElementById('loc_' + idx).value)
        });
    });

    if (selected.length === 0) {
        showToast('Check at least one device to import', true);
        return;
    }

    const res = await fetch('/api/vpx/import', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
        body: JSON.stringify({ devices: selected })
    });
    const data = await res.json();
    if (data.success) {
        showToast(`Imported: ${data.created} added, ${data.updated} updated`);
        runScan();
    } else {
        showToast(data.error || 'Import failed', true);
    }
}

initDiscover();
</script>
