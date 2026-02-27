document.addEventListener('DOMContentLoaded', () => {
    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    const slug = window.PROJECT_SLUG;
    if (!slug) return;

    const mapEl = document.getElementById('map');
    if (!mapEl) return;

    const apiBase = window.API_BASE || (window.BASE_URL ? window.BASE_URL + '/api' : 'api');
    fetch(apiBase + '/reports.php?p=' + encodeURIComponent(slug) + '&with_photos=0')
        .then(r => r.json())
        .then(data => {
            const reports = (data.reports || []).filter(r => r.lat != null && r.lng != null);
            if (reports.length === 0) {
                mapEl.parentElement.innerHTML = '<p>No reports with location data.</p>';
                return;
            }

            const map = L.map('map').setView([reports[0].lat, reports[0].lng], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
            }).addTo(map);

            reports.forEach(r => {
                const sel = Object.entries(r.selections || {}).map(([k, v]) => `${k}: ${v}`).join(', ');
                const addr = (r.address || r.road_name || '').slice(0, 80);
                const note = (r.note || '').slice(0, 100);
                const popup = `<strong>${escapeHtml(sel)}</strong>${addr ? `<br><small>${escapeHtml(addr)}</small>` : ''}<br>${escapeHtml(note)}<br><button type="button" class="map-view-report" data-id="${r.id}">View report</button>`;
                const marker = L.marker([r.lat, r.lng]).addTo(map).bindPopup(popup);
                marker.on('popupopen', function() {
                    const popupEl = marker.getPopup().getElement();
                    popupEl?.querySelector('.map-view-report')?.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (typeof window.openReportDetail === 'function') {
                            window.openReportDetail(r.id);
                        }
                    });
                });
            });

            if (reports.length > 1) {
                const bounds = L.latLngBounds(reports.map(r => [r.lat, r.lng]));
                map.fitBounds(bounds, { padding: [20, 20] });
            }
        })
        .catch(() => {
            mapEl.parentElement.innerHTML = '<p>Failed to load map data.</p>';
        });
});
