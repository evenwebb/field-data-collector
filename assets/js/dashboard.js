document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('createProjectForm');
    if (!form) return;

    const addBtn = document.getElementById('addOptionGroup');
    let groupCount = 1;

    addBtn?.addEventListener('click', () => {
        groupCount++;
        const div = document.createElement('div');
        div.className = 'option-group';
        div.innerHTML = `
            <label>Option group ${groupCount}</label>
            <input type="text" class="group-label" placeholder="Label (e.g. Category)">
            <input type="text" class="group-choices" placeholder="Choices (comma-separated, e.g. A, B, C)">
        `;
        document.getElementById('optionGroups').appendChild(div);
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const errorEl = document.getElementById('createError');
        errorEl.hidden = true;
        errorEl.textContent = '';

        const name = document.getElementById('name').value.trim();
        if (!name) {
            errorEl.textContent = 'Project name required';
            errorEl.hidden = false;
            return;
        }

        const slugInput = document.getElementById('slug');
        const slug = slugInput ? slugInput.value.trim() : '';
        if (slug && !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
            errorEl.textContent = 'URL slug must be lowercase letters, numbers, and hyphens only';
            errorEl.hidden = false;
            return;
        }

        const optionGroups = [];
        document.querySelectorAll('.option-group').forEach((el, i) => {
            const label = el.querySelector('.group-label').value.trim();
            const choicesStr = el.querySelector('.group-choices').value.trim();
            if (!label || !choicesStr) return;
            const choices = choicesStr.split(/[,\n]/).map(s => s.trim()).filter(Boolean);
            if (choices.length > 0) {
                optionGroups.push({ label, choices });
            }
        });

        if (optionGroups.length === 0) {
            errorEl.textContent = 'Add at least one option group with choices';
            errorEl.hidden = false;
            return;
        }

        const payload = { name, option_groups: optionGroups };
        if (slug) payload.slug = slug;

        try {
            const apiUrl = (window.BASE_URL || '') + '/api/project.php';
            const res = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.error) {
                errorEl.textContent = data.error;
                errorEl.hidden = false;
                return;
            }
            window.location.href = (window.BASE_URL || '') + '/project/' + encodeURIComponent(data.slug);
        } catch (err) {
            errorEl.textContent = 'Network error. Please try again.';
            errorEl.hidden = false;
        }
    });
});
