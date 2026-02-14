document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('reportForm');
    const successEl = document.getElementById('success');
    const errorEl = document.getElementById('error');
    const submitBtn = document.getElementById('submitBtn');
    const addAnotherBtn = document.getElementById('addAnother');
    const photosInput = document.getElementById('photos');

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

    // Photo preview
    photosInput.addEventListener('change', () => {
        const preview = document.getElementById('photoPreview');
        preview.innerHTML = '';
        const files = photosInput.files;
        for (let i = 0; i < Math.min(files.length, 3); i++) {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(files[i]);
            img.alt = 'Preview ' + (i + 1);
            img.className = 'photo-preview';
            preview.appendChild(img);
        }
    });

    addAnotherBtn?.addEventListener('click', () => {
        successEl.hidden = true;
        errorEl.hidden = true;
        form.reset();
        document.getElementById('photoPreview').innerHTML = '';
        document.getElementById('lat').value = '';
        document.getElementById('lng').value = '';
        form.hidden = false;
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        errorEl.hidden = true;
        errorEl.textContent = '';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

        const loc = await getLocation();
        document.getElementById('lat').value = loc.lat ?? '';
        document.getElementById('lng').value = loc.lng ?? '';

        const formData = new FormData(form);
        const selections = {};
        form.querySelectorAll('input[name^="selections"]').forEach((inp) => {
            if (inp.checked) {
                const match = inp.name.match(/selections\[([^\]]+)\]/);
                if (match) selections[match[1]] = inp.value;
            }
        });
        formData.set('selections', JSON.stringify(selections));
        formData.set('lat', loc.lat ?? '');
        formData.set('lng', loc.lng ?? '');

        try {
            const project = formData.get('project');
            const apiUrl = (window.BASE_URL || '') + '/api/submit_report.php?p=' + encodeURIComponent(project);
            const res = await fetch(apiUrl, {
                method: 'POST',
                body: formData,
            });
            const data = await res.json();
            if (data.error) {
                errorEl.textContent = data.error;
                errorEl.hidden = false;
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit Report';
                return;
            }
            form.hidden = true;
            successEl.hidden = false;
        } catch (err) {
            errorEl.textContent = 'Network error. Please try again.';
            errorEl.hidden = false;
        }
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Report';
    });
});
