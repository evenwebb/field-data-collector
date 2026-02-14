document.addEventListener('DOMContentLoaded', () => {
    const slug = window.PROJECT_SLUG;
    const optionGroups = window.OPTION_GROUPS || [];
    if (!slug) return;

    const reportsList = document.getElementById('reportsList');
    const bulkActions = document.getElementById('bulkActions');
    const selectedCount = document.getElementById('selectedCount');
    const filterOption = document.getElementById('filterOption');
    const filterValue = document.getElementById('filterValue');

    let reports = [];
    let selectedIds = new Set();

    let searchDebounce = null;
    document.getElementById('filterSearch')?.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(loadReports, 400);
    });

    // Filter value options
    filterOption?.addEventListener('change', () => {
        filterValue.innerHTML = '<option value="">-- Value --</option>';
        filterValue.disabled = true;
        const label = filterOption.value;
        if (!label) return;
        const group = optionGroups.find(g => g.label === label);
        if (group) {
            filterValue.disabled = false;
            group.choices.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c;
                opt.textContent = c;
                filterValue.appendChild(opt);
            });
        }
    });

    async function loadReports() {
        const params = new URLSearchParams({ project: slug });
        const fo = document.getElementById('filterOption')?.value;
        const fv = document.getElementById('filterValue')?.value;
        const from = document.getElementById('filterFrom')?.value;
        const to = document.getElementById('filterTo')?.value;
        const search = document.getElementById('filterSearch')?.value;
        if (fo && fv) { params.set('filter_option', fo); params.set('filter_value', fv); }
        if (from) params.set('from', from);
        if (to) params.set('to', to);
        if (search) params.set('search', search);

        try {
            const apiBase = window.API_BASE || (window.BASE_URL ? window.BASE_URL + '/api' : 'api');
            const res = await fetch(apiBase + '/reports.php?' + params);
            const data = await res.json();
            reports = data.reports || [];
            if (reportsList) renderReports();
            const countEl = document.getElementById('reportCount');
            if (countEl) countEl.textContent = reports.length === 0 ? 'No reports' : reports.length + ' report' + (reports.length !== 1 ? 's' : '');
        } catch (e) {
            if (reportsList) reportsList.innerHTML = '<p class="error">Failed to load reports</p>';
        }
    }

    function renderReports() {
        if (!reportsList) return;
        if (reports.length === 0) {
            reportsList.innerHTML = '<p class="empty">No reports yet. Share the collection link to start.</p>';
            return;
        }
        const base = window.BASE_URL || '';
        reportsList.innerHTML = reports.map(r => {
            const sel = Object.entries(r.selections || {}).map(([k,v]) => `${k}: ${v}`).join(', ');
            const photoUrl = r.primary_photo ? base + '/thumb.php?p=' + encodeURIComponent(slug) + '&photo=' + encodeURIComponent(r.primary_photo) : '';
            return `
            <div class="card report-card" data-id="${r.id}">
                <label class="report-check">
                    <input type="checkbox" class="report-select" value="${r.id}">
                </label>
                <div class="report-thumb">
                    ${photoUrl ? `<img src="${photoUrl}" alt="" loading="lazy" onerror="var s=document.createElement('span');s.className='no-thumb';s.textContent='No photo';this.replaceWith(s)">` : '<span class="no-thumb">No photo</span>'}
                </div>
                <div class="report-info">
                    <div class="report-selections">${escapeHtml(sel)}</div>
                    ${(r.address || r.road_name) ? `<div class="report-road">${escapeHtml(r.address || r.road_name)}</div>` : ''}
                    <div class="report-note">${escapeHtml((r.note || '').slice(0, 100))}${(r.note || '').length > 100 ? '...' : ''}</div>
                    <div class="report-meta">
                        <span class="date">${formatDate(r.created_at)}</span>
                    </div>
                </div>
            </div>`;
        }).join('');

        reportsList.querySelectorAll('.report-select').forEach(cb => {
            cb.addEventListener('change', updateBulkActions);
        });
        reportsList.querySelectorAll('.report-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.target.closest('.report-check')) return;
                openReportDetail(parseInt(card.dataset.id));
            });
        });
    }

    let detailMap = null;
    let currentDetailReportId = null;

    function openReportDetail(id) {
        const report = reports.find(r => r.id === id);
        if (!report) return;
        const modal = document.getElementById('reportDetailModal');
        if (!modal) return;

        const base = window.BASE_URL || '';
        const sel = Object.entries(report.selections || {}).map(([k, v]) => `${k}: ${v}`).join(', ');
        const photosHtml = (report.photos || []).map(p => {
            const url = base + '/photo.php?p=' + encodeURIComponent(slug) + '&photo=' + encodeURIComponent(p.photo_path);
            return `<img src="${url}" alt="" loading="lazy">`;
        }).join('') || '<span class="no-photos">No photos</span>';

        document.getElementById('reportDetailPhotos').innerHTML = photosHtml;
        const coords = report.lat != null && report.lng != null ? `${report.lat.toFixed(5)}, ${report.lng.toFixed(5)}` : '';
        const locationText = report.address ? report.address + (coords ? ` (${coords})` : '') : (coords || '');
        document.getElementById('reportDetailInfo').innerHTML = `
            <h3>${escapeHtml(sel)}</h3>
            ${report.road_name ? `<p class="report-detail-road">${escapeHtml(report.road_name)}</p>` : ''}
            <p class="report-detail-note">${escapeHtml(report.note || '')}</p>
            <div class="report-detail-meta">
                <span class="date">${formatDate(report.created_at)}</span>
            </div>
            ${locationText ? `<p class="report-detail-location">${escapeHtml(locationText)}</p>` : ''}
            ${report.lat != null && report.lng != null ? '<p class="report-detail-map-label">Where it was taken</p>' : ''}
        `;

        const mapEl = document.getElementById('reportDetailMap');
        if (report.lat != null && report.lng != null) {
            mapEl.hidden = false;
            mapEl.style.height = '320px';
            if (detailMap) detailMap.remove();
            detailMap = L.map('reportDetailMap').setView([report.lat, report.lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(detailMap);
            L.marker([report.lat, report.lng]).addTo(detailMap);
        } else {
            mapEl.hidden = true;
            if (detailMap) { detailMap.remove(); detailMap = null; }
        }

        currentDetailReportId = id;
        document.getElementById('reportDetailView').hidden = false;
        document.getElementById('reportDetailEdit').hidden = true;
        modal.hidden = false;
        if (detailMap) setTimeout(() => detailMap.invalidateSize(), 50);
        document.body.style.overflow = 'hidden';
    }

    function switchToEditMode() {
        const report = reports.find(r => r.id === currentDetailReportId);
        if (!report) return;
        const fieldsEl = document.getElementById('reportEditFields');
        fieldsEl.innerHTML = optionGroups.map((g, i) => {
            const val = (report.selections || {})[g.label] ?? '';
            const opts = (g.choices || []).map(c => `<option value="${escapeHtml(c)}" ${c === val ? 'selected' : ''}>${escapeHtml(c)}</option>`).join('');
            return `<label for="reportEditSel_${i}">${escapeHtml(g.label)}</label>
<select id="reportEditSel_${i}" data-label="${escapeHtml(g.label)}">${opts}</select>`;
        }).join('');
        document.getElementById('reportEditNote').value = report.note || '';
        document.getElementById('reportEditComment').value = report.comment || '';
        document.getElementById('reportDetailView').hidden = true;
        document.getElementById('reportDetailEdit').hidden = false;
    }

    function switchToViewMode() {
        document.getElementById('reportDetailView').hidden = false;
        document.getElementById('reportDetailEdit').hidden = true;
    }

    function closeReportDetail() {
        const modal = document.getElementById('reportDetailModal');
        if (modal) modal.hidden = true;
        document.body.style.overflow = '';
        if (detailMap) { detailMap.remove(); detailMap = null; }
    }

    function updateBulkActions() {
        selectedIds.clear();
        reportsList?.querySelectorAll('.report-select:checked').forEach(cb => {
            selectedIds.add(parseInt(cb.value));
        });
        if (bulkActions && selectedCount) {
            bulkActions.hidden = selectedIds.size === 0;
            selectedCount.textContent = selectedIds.size + ' selected';
        }
    }

    async function bulkDelete() {
        if (selectedIds.size === 0 || !confirm('Delete ' + selectedIds.size + ' reports?')) return;
        try {
            const res = await fetch((window.API_BASE || 'api') + '/reports.php?p=' + encodeURIComponent(slug), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'bulk_delete', ids: [...selectedIds] }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) {
                toast(data.error || 'Delete failed', 'error');
                return;
            }
            selectedIds.clear();
            loadReports();
        } catch (e) {
            toast('Delete failed: ' + (e.message || 'Network error'), 'error');
        }
    }

    document.getElementById('reportDetailModal')?.querySelector('.modal-close')?.addEventListener('click', closeReportDetail);
    document.getElementById('reportDetailModal')?.querySelector('.modal-backdrop')?.addEventListener('click', closeReportDetail);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeReportDetail(); });

    document.getElementById('reportDetailEditBtn')?.addEventListener('click', switchToEditMode);
    document.getElementById('reportEditCancel')?.addEventListener('click', switchToViewMode);
    document.getElementById('reportEditForm')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = currentDetailReportId;
        if (!id) return;
        const selections = {};
        optionGroups.forEach((g, i) => {
            const sel = document.getElementById('reportEditSel_' + i);
            if (sel && sel.value) selections[g.label] = sel.value;
        });
        const note = document.getElementById('reportEditNote').value;
        const comment = document.getElementById('reportEditComment').value;
        try {
            const res = await fetch((window.API_BASE || 'api') + '/reports.php?p=' + encodeURIComponent(slug), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, selections, note, comment }),
            });
            const data = await res.json();
            if (data.error) {
                toast(data.error || 'Save failed', 'error');
                return;
            }
            await loadReports();
            switchToViewMode();
            openReportDetail(id);
            toast('Saved', 'success');
        } catch (err) {
            toast('Failed to save', 'error');
        }
    });

    document.getElementById('applyFilters')?.addEventListener('click', loadReports);
    document.getElementById('clearFilters')?.addEventListener('click', () => {
        const fv = document.getElementById('filterValue');
        if (document.getElementById('filterOption')) document.getElementById('filterOption').value = '';
        if (fv) { fv.value = ''; fv.innerHTML = '<option value="">-- Value --</option>'; fv.disabled = true; }
        document.getElementById('filterFrom')?.value && (document.getElementById('filterFrom').value = '');
        document.getElementById('filterTo')?.value && (document.getElementById('filterTo').value = '');
        document.getElementById('filterSearch')?.value && (document.getElementById('filterSearch').value = '');
        loadReports();
    });
    document.getElementById('bulkDelete')?.addEventListener('click', bulkDelete);

    document.querySelectorAll('.collapsible-trigger').forEach(btn => {
        const content = document.getElementById(btn.getAttribute('aria-controls'));
        if (content && !content.hidden) {
            btn.querySelector('.collapsible-icon')?.classList.add('expanded');
        }
        btn.addEventListener('click', () => {
            const expanded = btn.getAttribute('aria-expanded') === 'true';
            if (content) {
                content.hidden = expanded;
                btn.setAttribute('aria-expanded', !expanded);
                btn.querySelector('.collapsible-icon')?.classList.toggle('expanded', !expanded);
            }
        });
    });

    function toast(msg, type = 'info') {
        const el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.textContent = msg;
        document.body.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));
        setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 300); }, 2500);
    }
    window.projectToast = toast;

    document.getElementById('copyLink')?.addEventListener('click', () => {
        const input = document.getElementById('collectUrl');
        input.select();
        navigator.clipboard?.writeText(input.value).then(() => {
            const btn = document.getElementById('copyLink');
            const t = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(() => btn.textContent = t, 2000);
            toast('Link copied', 'success');
        });
    });

    function buildExportUrl(format) {
        const apiBase = window.API_BASE || (window.BASE_URL ? window.BASE_URL + '/api' : window.location.origin + '/api');
        const url = new URL(apiBase + '/export.php');
        url.searchParams.set('p', slug);
        url.searchParams.set('format', format);
        const from = document.getElementById('exportFrom')?.value;
        const to = document.getElementById('exportTo')?.value;
        if (from) url.searchParams.set('from', from);
        if (to) url.searchParams.set('to', to);
        return url.toString();
    }

    async function triggerExport(format) {
        const url = buildExportUrl(format);
        const btns = document.querySelectorAll('#exportZip, #exportJpg, #exportPdf');
        btns.forEach(b => { b.disabled = true; });
        try {
            const res = await fetch(url);
            const contentType = res.headers.get('Content-Type') || '';
            if (contentType.includes('application/json')) {
                const data = await res.json().catch(() => ({}));
                toast(data.error || 'Export failed', 'error');
                return;
            }
            const blob = await res.blob();
            const disp = res.headers.get('Content-Disposition') || '';
            const match = disp.match(/filename\*?=(?:UTF-8'')?"?([^";\n]+)"?/i) || disp.match(/filename="?([^";\n]+)"?/i);
            const filename = match ? match[1].trim() : (format === 'jpg' ? slug + '-export.jpg' : slug + '-export.' + format);
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = filename;
            a.click();
            URL.revokeObjectURL(a.href);
            toast('Export ready', 'success');
        } catch (e) {
            toast('Export failed: ' + (e.message || 'Network error'), 'error');
        } finally {
            btns.forEach(b => { b.disabled = false; });
        }
    }

    document.getElementById('exportZip')?.addEventListener('click', () => triggerExport('zip'));
    document.getElementById('exportJpg')?.addEventListener('click', () => triggerExport('jpg'));
    document.getElementById('exportPdf')?.addEventListener('click', () => triggerExport('pdf'));

    document.getElementById('shareExport')?.addEventListener('click', async () => {
        try {
            const from = document.getElementById('exportFrom')?.value || '';
            const to = document.getElementById('exportTo')?.value || '';
            const apiBase = window.API_BASE || (window.BASE_URL ? window.BASE_URL + '/api' : 'api');
            const res = await fetch(apiBase + '/export.php?p=' + encodeURIComponent(slug) + '&format=zip&share=1' + (from ? '&from=' + from : '') + (to ? '&to=' + to : ''));
            const data = await res.json();
            if (data.url) {
                navigator.clipboard?.writeText(data.url);
                toast('Share link copied. Valid for 1 hour.', 'success');
            } else if (data.error) {
                toast(data.error, 'error');
            }
        } catch (e) {
            toast('Failed to get share link', 'error');
        }
    });

    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
    function formatDate(s) {
        if (!s) return '';
        const d = new Date(s);
        return d.toLocaleDateString();
    }

    loadReports();
    window.openReportDetail = openReportDetail;

    // Edit project form
    const editForm = document.getElementById('editProjectForm');
    if (editForm) {
        const editOptionGroups = document.getElementById('editOptionGroups');
        const addEditGroup = document.getElementById('addEditGroup');
        const editSlugInput = document.getElementById('editSlug');
        const slugChangeWarning = document.getElementById('slugChangeWarning');
        const originalSlug = slug;
        let editGroups = JSON.parse(JSON.stringify(window.OPTION_GROUPS || []));

        editSlugInput?.addEventListener('input', () => {
            const val = editSlugInput.value.trim();
            slugChangeWarning.hidden = !(val && val !== originalSlug);
        });

        function renderEditGroups() {
            editOptionGroups.innerHTML = editGroups.map((g, i) => `
                <div class="option-group" data-i="${i}">
                    <label>Option group ${i + 1}</label>
                    <input type="text" class="group-label" value="${escapeHtml(g.label)}" placeholder="Label">
                    <input type="text" class="group-choices" value="${escapeHtml(g.choices.join(', '))}" placeholder="Choices (comma-separated)">
                </div>
            `).join('');
        }
        renderEditGroups();

        addEditGroup?.addEventListener('click', () => {
            editGroups.push({ label: '', choices: [] });
            renderEditGroups();
        });

        editForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const name = document.getElementById('editName').value.trim();
            const newSlug = (editSlugInput?.value || '').trim();
            const groups = [];
            editOptionGroups.querySelectorAll('.option-group').forEach(el => {
                const label = el.querySelector('.group-label').value.trim();
                const choicesStr = el.querySelector('.group-choices').value.trim();
                if (label && choicesStr) {
                    groups.push({ label, choices: choicesStr.split(/[,\n]/).map(s => s.trim()).filter(Boolean) });
                }
            });
            if (!name || groups.length === 0) {
                toast('Name and at least one option group required', 'error');
                return;
            }
            if (newSlug && !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(newSlug)) {
                toast('URL slug must be lowercase letters, numbers, and hyphens only', 'error');
                return;
            }
            if (newSlug && newSlug !== originalSlug && !confirm('Changing the URL slug will break existing collection links. Anyone with the old link will get an error. Continue?')) {
                return;
            }
            try {
                const apiBase = window.API_BASE || (window.BASE_URL ? window.BASE_URL + '/api' : 'api');
                const res = await fetch(apiBase + '/project.php?p=' + encodeURIComponent(slug), {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, slug: newSlug || originalSlug, option_groups: groups }),
                });
                const data = await res.json();
                if (data.error) {
                    toast(data.error || 'Save failed', 'error');
                    return;
                }
                window.location.href = (window.BASE_URL || '') + '/project/' + encodeURIComponent(data.slug);
            } catch (err) {
                toast('Failed to save', 'error');
            }
        });
    }
});
