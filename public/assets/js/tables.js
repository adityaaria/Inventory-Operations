'use strict';

(function exposeTables(root) {
    root.InventoryTables = {
        create() {
            function enhance() {
                document.querySelectorAll('table.data-table').forEach((table, index) => {
                    if (table.dataset.enhanced === 'true') return;
                    table.dataset.enhanced = 'true';
                    table.id = table.id || `data-table-${index + 1}`;
                    const toolbar = document.createElement('div');
                    toolbar.className = 'table-toolbar';
                    toolbar.innerHTML = `<label class="table-search">Search table <input type="search" placeholder="Type to filter rows"></label><button type="button" class="button" data-export-table="${table.id}">Export CSV</button>`;
                    table.parentNode?.insertBefore(toolbar, table);
                    const search = toolbar.querySelector('input[type="search"]');
                    const key = `inventory.table.search.${location.pathname}.${table.id}`;
                    if (search) {
                        const debouncedFilter = InventoryUi.debounce((value) => filter(table, value), 150);
                        search.value = readSessionValue(key);
                        search.addEventListener('input', () => {
                            writeSessionValue(key, search.value);
                            debouncedFilter(search.value);
                        });
                        if (search.value !== '') filter(table, search.value);
                    }
                    toolbar.querySelector('[data-export-table]')?.addEventListener('click', () => exportTable(table));
                    addSorting(table);
                    upgradeEmptyRows(table);
                });
            }

            function filter(table, term) {
                const markStart = `table-filter:${table.id}:start`;
                const markEnd = `table-filter:${table.id}:end`;
                const canMeasure = typeof performance !== 'undefined' && typeof performance.mark === 'function';
                if (canMeasure) performance.mark(markStart);

                let visible = 0;
                table.querySelectorAll('tbody tr').forEach((row) => {
                    if (row.classList.contains('empty')) return;
                    const match = InventoryUi.matchesFilter(row.textContent, term);
                    row.hidden = !match;
                    if (match) visible += 1;
                });
                setGeneratedEmpty(table, visible === 0);

                if (canMeasure) {
                    performance.mark(markEnd);
                    performance.measure(`table-filter:${table.id}`, markStart, markEnd);
                }
            }

            function addSorting(table) {
                table.querySelectorAll('thead th').forEach((th, index) => {
                    th.tabIndex = 0;
                    th.classList.add('sortable');
                    th.addEventListener('click', () => sort(table, index, th));
                    th.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            sort(table, index, th);
                        }
                    });
                });
            }

            function sort(table, index, th) {
                const tbody = table.querySelector('tbody');
                if (!tbody) return;
                const direction = th.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
                table.querySelectorAll('th').forEach((header) => delete header.dataset.sortDirection);
                th.dataset.sortDirection = direction;
                [...tbody.querySelectorAll('tr:not(.empty):not(.generated-empty)')]
                    .sort((left, right) => InventoryUi.compareTableValues(left.children[index]?.textContent?.trim() || '', right.children[index]?.textContent?.trim() || '', direction))
                    .forEach((row) => tbody.append(row));
            }

            function upgradeEmptyRows(table) {
                table.querySelectorAll('tr.empty td').forEach((cell) => {
                    cell.innerHTML = `<div class="empty-state"><strong>No records found</strong><span>${cell.textContent.trim()}</span></div>`;
                });
            }

            function setGeneratedEmpty(table, show) {
                let row = table.querySelector('tr.generated-empty');
                const columnCount = table.querySelectorAll('thead th').length || 1;
                if (!row) {
                    row = document.createElement('tr');
                    row.className = 'empty generated-empty';
                    row.innerHTML = `<td colspan="${columnCount}"><div class="empty-state"><strong>No matching rows</strong><span>Adjust the table search to see more records.</span></div></td>`;
                    table.querySelector('tbody')?.append(row);
                }
                row.hidden = !show;
            }

            function readSessionValue(key) {
                try { return window.sessionStorage.getItem(key) || ''; } catch (_error) { return ''; }
            }

            function writeSessionValue(key, value) {
                try {
                    if (value === '') window.sessionStorage.removeItem(key);
                    else window.sessionStorage.setItem(key, value);
                } catch (_error) {
                    // Storage may be unavailable in private or restricted browser contexts.
                }
            }

            function exportTable(table) {
                const rows = [...table.querySelectorAll('tr')]
                    .filter((row) => !row.hidden && !row.classList.contains('empty'))
                    .map((row) => [...row.children].map((cell) => InventoryUi.escapeCsvCell(cell.textContent)).join(','));
                const blob = new Blob([rows.join('\n')], {type: 'text/csv'});
                const link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = `${document.title.split(' - ')[0].toLowerCase().replace(/[^a-z0-9]+/g, '-') || 'table'}.csv`;
                link.click();
                URL.revokeObjectURL(link.href);
            }

            return {enhance};
        },
    };
})(typeof globalThis === 'object' ? globalThis : this);
