'use strict';

(function exposeCharts(root) {
    root.InventoryCharts = {
        render() {
            document.querySelectorAll('[data-chart]').forEach((chart) => {
                let data = {};
                try {
                    data = JSON.parse(chart.getAttribute('data-chart') || '{}');
                } catch (_error) {
                    data = {};
                }
                const entries = Object.entries(data);
                if (entries.length === 0) {
                    chart.innerHTML = '<div class="empty-state"><strong>No chart data</strong><span>There are no records for this widget yet.</span></div>';
                    return;
                }
                const max = Math.max(...entries.map(([, value]) => Number(value) || 0), 1);
                chart.innerHTML = entries.map(([label, value]) => {
                    const numeric = Number(value) || 0;
                    const width = Math.max(6, Math.round((numeric / max) * 100));
                    return `
                        <div class="chart-row">
                            <span>${InventoryUi.escapeHtml(label)}</span>
                            <div class="chart-track"><i style="width: ${width}%"></i></div>
                            <strong>${numeric}</strong>
                        </div>
                    `;
                }).join('');
            });
        },
    };
})(typeof globalThis === 'object' ? globalThis : this);
