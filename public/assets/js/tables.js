'use strict';

(function exposeTables(root) {
    root.InventoryTables = {
        create() {
            function enhance() {
                document.querySelectorAll('table.data-table').forEach((table, index) => {
                    if (table.dataset.enhanced === 'true') return;
                    table.dataset.enhanced = 'true';
                    table.id = table.id || `data-table-${index + 1}`;
                    if (table.dataset.export !== 'server') {
                        const header = table.closest('main.page')?.querySelector('.page-header');
                        let toolbar = header?.querySelector('.toolbar');
                        if (!toolbar) {
                            toolbar = document.createElement('div');
                            toolbar.className = 'toolbar';
                            toolbar.setAttribute('aria-label', 'Table actions');
                            if (header) header.append(toolbar);
                            else table.parentNode?.insertBefore(toolbar, table);
                        }
                        const exportButton = document.createElement('button');
                        exportButton.type = 'button';
                        exportButton.className = 'button';
                        exportButton.dataset.exportTable = table.id;
                        exportButton.textContent = table.closest('main.page')?.querySelector('.pagination') ? 'Export Current Page' : 'Export CSV';
                        exportButton.addEventListener('click', () => exportTable(table));
                        toolbar.append(exportButton);
                    }
                    enhanceSelection(table);
                    addSorting(table);
                    upgradeEmptyRows(table);
                });
            }

            function addSorting(table) {
                table.querySelectorAll('thead th').forEach((th, index) => {
                    if (th.classList.contains('selection-column')) return;
                    if (th.dataset.sortKey) return; // Server-rendered links sort the full filtered result.
                    // Action columns and blank headers hold controls, not values; data-no-sort opts any other column out.
                    if ('noSort' in th.dataset || /^(actions?|)$/i.test(th.textContent.trim())) return;
                    th.tabIndex = 0;
                    th.classList.add('sortable');
                    th.setAttribute('aria-sort', th.getAttribute('aria-sort') || 'none');
                    th.title = 'Sort current page';
                    th.addEventListener('click', () => sort(table, index, th));
                    th.addEventListener('keydown', (event) => {
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            sort(table, index, th);
                        }
                    });
                });
            }

            function enhanceSelection(table) {
                if (!table.dataset.selectable) return;

                const selectAll = table.querySelector('.table-select-all');
                const rowCheckboxes = () => [...table.querySelectorAll('.table-row-select')];
                if (!selectAll) return;

                const bulkBar = document.createElement('div');
                bulkBar.className = 'bulk-action-bar';
                bulkBar.setAttribute('role', 'region');
                bulkBar.setAttribute('aria-label', 'Bulk selection status');
                bulkBar.innerHTML = '<strong class="bulk-action-count" aria-live="polite" aria-label="Selected count">0 selected</strong><span>Bulk actions are not available.</span>';
                table.parentNode?.insertBefore(bulkBar, table);

                selectAll.addEventListener('change', () => {
                    rowCheckboxes().forEach((checkbox) => {
                        const row = checkbox.closest('tr');
                        if (!row?.hidden) checkbox.checked = selectAll.checked;
                    });
                    syncSelection(table);
                });
                rowCheckboxes().forEach((checkbox) => checkbox.addEventListener('change', () => syncSelection(table)));
                table._selectionBar = bulkBar;
                syncSelection(table);
            }

            function syncSelection(table) {
                const selectAll = table.querySelector('.table-select-all');
                const checkboxes = [...table.querySelectorAll('.table-row-select')];
                if (!selectAll || checkboxes.length === 0) return;

                const visible = checkboxes.filter((checkbox) => !checkbox.closest('tr')?.hidden);
                checkboxes.filter((checkbox) => checkbox.closest('tr')?.hidden).forEach((checkbox) => {
                    checkbox.checked = false;
                });
                const selected = visible.filter((checkbox) => checkbox.checked).length;
                selectAll.checked = visible.length > 0 && selected === visible.length;
                selectAll.indeterminate = selected > 0 && selected < visible.length;
                const count = table._selectionBar?.querySelector('.bulk-action-count');
                if (count) count.textContent = `${selected} selected`;
                table._selectionBar?.classList.toggle('is-visible', selected > 0);
            }

            function sort(table, index, th) {
                const tbody = table.querySelector('tbody');
                if (!tbody) return;
                const direction = th.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
                table.querySelectorAll('th').forEach((header) => {
                    delete header.dataset.sortDirection;
                    header.setAttribute('aria-sort', 'none');
                });
                th.dataset.sortDirection = direction;
                if (direction === 'asc') {
                    th.setAttribute('aria-sort', 'ascending');
                } else {
                    th.setAttribute('aria-sort', 'descending');
                }
                [...tbody.querySelectorAll('tr:not(.empty):not(.generated-empty):not(.filtered-empty)')]
                    .sort((left, right) => InventoryUi.compareTableValues(left.children[index]?.textContent?.trim() || '', right.children[index]?.textContent?.trim() || '', direction))
                    .forEach((row) => tbody.append(row));
            }

            function upgradeEmptyRows(table) {
                table.querySelectorAll('tr.empty td').forEach((cell) => {
                    cell.innerHTML = `<div class="empty-state"><strong>No records found</strong><span>${cell.textContent.trim()}</span></div>`;
                });
            }

            function exportTable(table) {
                const rows = [...table.querySelectorAll('tr')]
                    .filter((row) => !row.hidden && !row.classList.contains('empty'))
                    .map((row) => [...row.children]
                        .filter((cell) => !cell.classList.contains('selection-column') && !cell.classList.contains('selection-cell'))
                        .map((cell) => InventoryUi.escapeCsvCell(cell.textContent)).join(','));
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
