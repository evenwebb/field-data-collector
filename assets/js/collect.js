document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('reportForm');
    const successEl = document.getElementById('success');
    const errorEl = document.getElementById('error');
    const submitBtn = document.getElementById('submitBtn');
    const addAnotherBtn = document.getElementById('addAnother');
    const photosInput = document.getElementById('photos');
    const photoPreviewEl = document.getElementById('photoPreview');
    const photoWarningsEl = document.getElementById('photoWarnings');
    const draftNoticeEl = document.getElementById('draftNotice');
    const queueStatusEl = document.getElementById('queueStatus');
    const lightboxEl = document.getElementById('collectLightbox');
    const lightboxImageEl = document.getElementById('collectLightboxImage');
    const lightboxCloseEl = document.getElementById('collectLightboxClose');
    const lightboxBackdropEl = document.getElementById('collectLightboxBackdrop');

    const limits = window.COLLECT_LIMITS || {};
    const maxPhotos = Number(limits.maxPhotos || 3);
    const maxUploadSize = Number(limits.maxUploadSize || (10 * 1024 * 1024));
    const allowedMimeTypes = new Set(Array.isArray(limits.allowedMimeTypes) ? limits.allowedMimeTypes : ['image/jpeg', 'image/png', 'image/webp']);
    const draftKey = 'field_reports_collect_draft_' + (window.PROJECT_SLUG || '');
    const queueDbName = 'field_reports_collect_queue_v1';
    let selectedFiles = [];
    let previewUrls = [];
    let autosaveTimer = null;
    let queueProcessing = false;

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    }

    function clearPreviewUrls() {
        previewUrls.forEach((url) => URL.revokeObjectURL(url));
        previewUrls = [];
    }

    function closeLightbox() {
        if (!lightboxEl) return;
        lightboxEl.hidden = true;
        document.body.style.overflow = '';
        lightboxImageEl?.removeAttribute('src');
    }

    function openLightbox(url) {
        if (!lightboxEl || !lightboxImageEl) return;
        lightboxImageEl.src = url;
        lightboxEl.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function showPhotoWarnings(messages) {
        if (!photoWarningsEl) return;
        if (!messages || messages.length === 0) {
            photoWarningsEl.hidden = true;
            photoWarningsEl.textContent = '';
            return;
        }
        photoWarningsEl.hidden = false;
        photoWarningsEl.innerHTML = messages.map((m) => escapeHtml(m)).join('<br>');
    }

    function showError(message) {
        errorEl.textContent = message;
        errorEl.hidden = false;
    }

    function getSelections() {
        const selections = {};
        form.querySelectorAll('input[name^="selections"]').forEach((inp) => {
            if (inp.checked) {
                const match = inp.name.match(/selections\[([^\]]+)\]/);
                if (match) selections[match[1]] = inp.value;
            }
        });
        return selections;
    }

    function hasAllRequiredSelections() {
        const names = new Set();
        form.querySelectorAll('input[type="radio"][name^="selections"]').forEach((inp) => {
            names.add(inp.name);
        });
        for (const name of names) {
            if (!form.querySelector(`input[name="${CSS.escape(name)}"]:checked`)) {
                return false;
            }
        }
        return true;
    }

    function renderPhotoPreview() {
        closeLightbox();
        clearPreviewUrls();
        photoPreviewEl.innerHTML = '';
        selectedFiles.forEach((file, index) => {
            const objectUrl = URL.createObjectURL(file);
            previewUrls.push(objectUrl);

            const item = document.createElement('div');
            item.className = 'photo-preview-item';

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'photo-preview-btn';
            btn.title = 'Open preview';
            btn.addEventListener('click', () => openLightbox(objectUrl));

            const img = document.createElement('img');
            img.src = objectUrl;
            img.alt = 'Preview ' + (index + 1);
            img.className = 'photo-preview';
            btn.appendChild(img);

            const controls = document.createElement('div');
            controls.className = 'photo-preview-controls';
            controls.innerHTML = `
                <button type="button" data-action="left" ${index === 0 ? 'disabled' : ''}>←</button>
                <button type="button" data-action="right" ${index === selectedFiles.length - 1 ? 'disabled' : ''}>→</button>
                <button type="button" data-action="remove">✕</button>
            `;
            controls.addEventListener('click', (e) => {
                const target = e.target.closest('button[data-action]');
                if (!target) return;
                const action = target.dataset.action;
                if (action === 'remove') {
                    selectedFiles.splice(index, 1);
                } else if (action === 'left' && index > 0) {
                    const tmp = selectedFiles[index - 1];
                    selectedFiles[index - 1] = selectedFiles[index];
                    selectedFiles[index] = tmp;
                } else if (action === 'right' && index < selectedFiles.length - 1) {
                    const tmp = selectedFiles[index + 1];
                    selectedFiles[index + 1] = selectedFiles[index];
                    selectedFiles[index] = tmp;
                }
                renderPhotoPreview();
            });

            item.appendChild(btn);
            item.appendChild(controls);
            photoPreviewEl.appendChild(item);
        });
    }

    function addFiles(files) {
        const warnings = [];
        files.forEach((file) => {
            if (selectedFiles.length >= maxPhotos) {
                warnings.push('Maximum ' + maxPhotos + ' photos allowed.');
                return;
            }
            if (!allowedMimeTypes.has(file.type)) {
                warnings.push(file.name + ': unsupported format. Use JPG/PNG/WebP.');
                return;
            }
            if (file.size > maxUploadSize) {
                warnings.push(file.name + ': file too large.');
                return;
            }
            selectedFiles.push(file);
        });
        showPhotoWarnings(warnings);
        renderPhotoPreview();
    }

    function saveDraft() {
        const payload = {
            note: document.getElementById('note')?.value || '',
            selections: getSelections(),
            updated_at: new Date().toISOString(),
        };
        localStorage.setItem(draftKey, JSON.stringify(payload));
    }

    function queueDraftSave() {
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(saveDraft, 250);
    }

    function restoreDraft() {
        const raw = localStorage.getItem(draftKey);
        if (!raw) return;
        let draft;
        try {
            draft = JSON.parse(raw);
        } catch (_e) {
            return;
        }
        if (!draft || typeof draft !== 'object') return;
        const noteInput = document.getElementById('note');
        if (noteInput && !noteInput.value && typeof draft.note === 'string') {
            noteInput.value = draft.note;
        }
        const selections = draft.selections && typeof draft.selections === 'object' ? draft.selections : {};
        Object.entries(selections).forEach(([label, value]) => {
            const escapedLabel = CSS.escape(label);
            const escapedValue = CSS.escape(String(value));
            const radio = form.querySelector(`input[name="selections[${escapedLabel}]"][value="${escapedValue}"]`);
            if (radio) {
                radio.checked = true;
            }
        });
        if (draftNoticeEl) {
            draftNoticeEl.hidden = false;
        }
    }

    function clearDraft() {
        localStorage.removeItem(draftKey);
    }

    function openQueueDb() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(queueDbName, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains('submissions')) {
                    db.createObjectStore('submissions', { keyPath: 'id', autoIncrement: true });
                }
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async function getQueuedSubmissions() {
        const db = await openQueueDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('submissions', 'readonly');
            const store = tx.objectStore('submissions');
            const req = store.getAll();
            req.onsuccess = () => resolve(req.result || []);
            req.onerror = () => reject(req.error);
        });
    }

    async function saveQueuedSubmission(payload) {
        const db = await openQueueDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('submissions', 'readwrite');
            const store = tx.objectStore('submissions');
            const req = store.add(payload);
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    async function deleteQueuedSubmission(id) {
        const db = await openQueueDb();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('submissions', 'readwrite');
            const store = tx.objectStore('submissions');
            const req = store.delete(id);
            req.onsuccess = () => resolve(true);
            req.onerror = () => reject(req.error);
        });
    }

    async function refreshQueueStatus() {
        if (!queueStatusEl) return;
        let queuedCount = 0;
        try {
            const all = await getQueuedSubmissions();
            queuedCount = all.length;
        } catch (_e) {
            queuedCount = 0;
        }
        if (queuedCount <= 0) {
            queueStatusEl.hidden = true;
            queueStatusEl.textContent = '';
            return;
        }
        queueStatusEl.hidden = false;
        queueStatusEl.textContent = queuedCount + ' submission' + (queuedCount === 1 ? '' : 's') + ' queued offline. Will retry automatically.';
    }

    async function submitPayload(payload) {
        const formData = new FormData();
        formData.set('project', payload.project);
        formData.set('selections', JSON.stringify(payload.selections || {}));
        formData.set('note', payload.note || '');
        formData.set('lat', payload.lat ?? '');
        formData.set('lng', payload.lng ?? '');

        (payload.photos || []).forEach((photo) => {
            const blob = photo.blob || photo.file || photo;
            let file;
            try {
                file = new File([blob], photo.name || 'photo.jpg', {
                    type: photo.type || blob.type || 'image/jpeg',
                    lastModified: photo.lastModified || Date.now(),
                });
            } catch (_e) {
                file = blob;
            }
            formData.append('photos[]', file, photo.name || 'photo.jpg');
        });

        const apiUrl = (window.BASE_URL || '') + '/api/submit_report.php?p=' + encodeURIComponent(payload.project);
        const res = await fetch(apiUrl, { method: 'POST', body: formData });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            const err = new Error(data.error || 'Submit failed');
            err.server = true;
            throw err;
        }
        return data;
    }

    async function processQueue() {
        if (queueProcessing || !navigator.onLine) return;
        queueProcessing = true;
        try {
            const all = await getQueuedSubmissions();
            for (const item of all) {
                try {
                    await submitPayload(item.payload);
                    await deleteQueuedSubmission(item.id);
                } catch (err) {
                    // Stop on first failure to avoid tight retry loops on persistent server errors.
                    break;
                }
            }
        } catch (_e) {
            // Ignore queue errors on the UI path.
        } finally {
            queueProcessing = false;
            await refreshQueueStatus();
        }
    }

    function resetFormState() {
        form.reset();
        selectedFiles = [];
        showPhotoWarnings([]);
        renderPhotoPreview();
        document.getElementById('lat').value = '';
        document.getElementById('lng').value = '';
    }

    // Get geolocation
    function getLocation() {
        return new Promise((resolve) => {
            if (!navigator.geolocation) {
                resolve({ lat: null, lng: null });
                return;
            }
            navigator.geolocation.getCurrentPosition(
                (pos) => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
                () => resolve({ lat: null, lng: null }),
                { timeout: 10000, maximumAge: 60000 }
            );
        });
    }

    photosInput.addEventListener('change', () => {
        addFiles(Array.from(photosInput.files || []));
        photosInput.value = '';
        queueDraftSave();
    });

    form.addEventListener('input', queueDraftSave);
    form.addEventListener('change', queueDraftSave);

    lightboxCloseEl?.addEventListener('click', closeLightbox);
    lightboxBackdropEl?.addEventListener('click', closeLightbox);
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeLightbox();
    });

    addAnotherBtn?.addEventListener('click', () => {
        successEl.hidden = true;
        errorEl.hidden = true;
        form.hidden = false;
        resetFormState();
    });

    window.addEventListener('beforeunload', clearPreviewUrls);
    window.addEventListener('online', processQueue);

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        closeLightbox();
        errorEl.hidden = true;
        errorEl.textContent = '';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

        if (selectedFiles.length === 0) {
            showError('At least one photo is required.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Report';
            return;
        }
        if (!hasAllRequiredSelections()) {
            showError('Please complete all required options.');
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Report';
            return;
        }

        const loc = await getLocation();
        const payload = {
            project: (window.PROJECT_SLUG || form.querySelector('input[name="project"]')?.value || '').trim(),
            selections: getSelections(),
            note: document.getElementById('note')?.value || '',
            lat: loc.lat ?? '',
            lng: loc.lng ?? '',
            photos: selectedFiles.map((file) => ({
                name: file.name,
                type: file.type,
                lastModified: file.lastModified,
                blob: file,
            })),
        };

        try {
            await submitPayload(payload);
            clearDraft();
            form.hidden = true;
            successEl.hidden = false;
            successEl.querySelector('p').textContent = 'Report submitted successfully!';
            resetFormState();
        } catch (err) {
            const networkError = !err || !err.server;
            if (!navigator.onLine || networkError) {
                try {
                    await saveQueuedSubmission({
                        createdAt: new Date().toISOString(),
                        payload,
                    });
                    clearDraft();
                    form.hidden = true;
                    successEl.hidden = false;
                    successEl.querySelector('p').textContent = 'No connection. Report queued and will upload automatically.';
                    resetFormState();
                    await refreshQueueStatus();
                } catch (_queueError) {
                    showError('Offline queue failed. Please try again.');
                }
            } else {
                showError(err.message || 'Submit failed.');
            }
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit Report';
        }
    });

    restoreDraft();
    refreshQueueStatus();
    processQueue();
});
