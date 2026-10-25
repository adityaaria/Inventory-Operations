<?php
// Presentation only: route/service authorization remains authoritative.
$workspaceRole = (string) ($GLOBALS['workspace_role'] ?? '');
$workspacePath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$workspaceItems = [
    ['Dashboard', '/dashboard', 'Operations'],
    ['Work Queue', '/work-queue', 'Operations'],
    ['Products', '/products', 'Operations'],
    ['Purchase Orders', '/purchase-orders', 'Operations', ['Admin', 'WarehouseStaff']],
    ['Sales Orders', '/sales-orders', 'Operations'],
    // D-19 / ADR-025: quotations, delivery notes (surat jalan) and invoices with PPN.
    ['Quotations', '/quotations', 'Operations', ['Admin', 'Sales']],
    ['Delivery Notes', '/delivery-notes', 'Operations'],
    ['Invoices', '/invoices', 'Operations', ['Admin', 'Sales']],
    ['Reports', '/reports', 'Operations'],
    ['Stock Operations', '/inventory-operations', 'Operations', ['Admin', 'WarehouseStaff']],
    ['In Transit', '/inventory-operations/in-transit', 'Operations', ['Admin', 'WarehouseStaff']],
    ['Replenishment', '/replenishment', 'Operations', ['Admin', 'WarehouseStaff']],
    // D-17 / ADR-023: expiry report and lot recall/trace.
    ['Expiry', '/reports/expiry', 'Operations', ['Admin', 'WarehouseStaff']],
    ['Lot Trace', '/lots', 'Operations', ['Admin', 'WarehouseStaff']],
    // D-18 / ADR-024: stock count sessions.
    ['Stock Counts', '/stock-counts', 'Operations', ['Admin', 'WarehouseStaff']],
    // D-20 / ADR-026: inventory valuation (FIFO per batch), reconciliation, gross margin and losses (Admin only).
    ['Valuation', '/reports/valuation', 'Operations', ['Admin']],
    ['Users', '/users', 'Management', ['Admin']],
    ['Audit Trail', '/audit-trail', 'Management', ['Admin']],
    ['Categories', '/categories', 'Management'],
    ['Warehouses', '/warehouses', 'Management'],
    ['Suppliers', '/suppliers', 'Management', ['Admin', 'WarehouseStaff']],
    ['Customers', '/customers', 'Management', ['Admin', 'Sales']],
    ['Price Lists', '/price-lists', 'Management', ['Admin']],
];
$workspaceIcons = [
    'Work Queue' => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="m7 9 1 1 2-2m2 1h5m-10 6 1 1 2-2m2 1h5"/>',
    'Stock Operations' => '<path d="M3 8h16l-4-4M21 16H5l4 4"/>',
    'Replenishment' => '<path d="M12 3v18M3 12h18"/>',
    'In Transit' => '<path d="M3 6h11v10H3zM14 9h4l3 3v4h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    'Expiry' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2M9 2h6"/>',
    'Stock Counts' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h2m-2 4h2m-2 4h2m3-8h3m-3 4h3m-3 4h3"/>',
    'Lot Trace' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6M8 10h4"/>',
    'Valuation' => '<circle cx="12" cy="12" r="9"/><path d="M15 8.5c-.7-.9-1.8-1.5-3-1.5-1.7 0-3 1-3 2.5S10.3 12 12 12s3 1 3 2.5-1.3 2.5-3 2.5c-1.2 0-2.3-.6-3-1.5M12 5v2m0 10v2"/>',
    'Audit Trail' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'Dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'Products' => '<path d="m12 3 9 5-9 5-9-5 9-5ZM3 8v9l9 5 9-5V8M12 13v9"/>',
    'Orders' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'Reports' => '<path d="M4 3v18h17M8 16v-5M13 16V7M18 16V4"/>',
    'Users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M18 15a5 5 0 0 1 3 5"/>',
    'Categories' => '<path d="M3 3h8l10 10-8 8L3 11V3Z"/><circle cx="7.5" cy="7.5" r="1"/>',
    'Warehouses' => '<path d="m3 9 9-6 9 6v12H3V9ZM8 21V11h8v10M8 15h8"/>',
    'Quotations' => '<path d="M6 3h9l3 3v15H6zM9 9h6M9 13h6M9 17h3"/>',
    'Delivery Notes' => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/><path d="M6 10h5"/>',
    'Invoices' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3"/>',
    'Price Lists' => '<path d="M3 12 12 3h8v8l-9 9z"/><circle cx="16" cy="8" r="1.5"/>',
    'Parties' => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M8 5V3h8v2M8 10h8M8 14h8M8 18h4"/>',
 ];
?>
<div class="app-shell">
    <aside class="sidebar" id="primary-navigation">
        <div class="sidebar-header">
        <a class="brand" href="/"><span class="brand-mark">IO</span><span><small>Workspace</small><strong>Inventory Ops</strong></span></a>
        <button class="sidebar-close" type="button" aria-label="Close navigation menu">×</button>
        </div>
        <nav class="side-nav" aria-label="Main navigation">
            <?php foreach (['Operations', 'Management'] as $workspaceGroup): ?>
                <div class="nav-group"><p class="nav-group-label"><?= $workspaceGroup ?></p>
                <?php foreach ($workspaceItems as $workspaceItem): ?>
                    <?php
                    [$workspaceLabel, $workspaceHref, $workspaceItemGroup] = $workspaceItem;
                    if ($workspaceItemGroup !== $workspaceGroup || (isset($workspaceItem[3]) && !in_array($workspaceRole, $workspaceItem[3], true))) { continue; }
                    $workspaceActive = $workspacePath === $workspaceHref || str_starts_with($workspacePath, $workspaceHref . '/') || ($workspaceHref === '/dashboard' && $workspacePath === '/');
                    // A more specific entry (In Transit under Stock Operations) owns its own path.
                    if ($workspaceHref === '/inventory-operations' && str_starts_with($workspacePath, '/inventory-operations/in-transit')) { $workspaceActive = false; }
                    $workspaceValuationPage = in_array($workspacePath, ['/reports/valuation', '/reports/valuation-reconciliation', '/reports/gross-margin', '/reports/inventory-losses'], true);
                    if ($workspaceHref === '/reports' && (str_starts_with($workspacePath, '/reports/expiry') || str_starts_with($workspacePath, '/reports/stock-counts') || $workspaceValuationPage)) { $workspaceActive = false; }
                    if ($workspaceHref === '/reports/valuation') { $workspaceActive = $workspaceValuationPage; }
                    if ($workspaceHref === '/stock-counts' && str_starts_with($workspacePath, '/reports/stock-counts')) { $workspaceActive = true; }
                    $workspaceIcon = match (true) {
                        str_contains($workspaceLabel, 'Orders') => 'Orders',
                        in_array($workspaceLabel, ['Suppliers', 'Customers'], true) => 'Parties',
                        default => $workspaceLabel,
                    };
                    ?>
                    <a href="<?= $workspaceHref ?>"<?= $workspaceActive ? ' class="is-active" aria-current="page"' : '' ?>><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $workspaceIcons[$workspaceIcon] ?></svg><span><?= $workspaceLabel ?></span></a>
                <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <?php require __DIR__ . '/profile-menu.php'; ?>
    </aside>
    <button class="sidebar-backdrop" type="button" aria-label="Close navigation menu" tabindex="-1"></button>
    <div class="main-content">
        <script defer src="/assets/js/order-items.js"></script>
        <script defer src="/assets/js/price-resolution.js"></script>
        <script defer src="/assets/js/form-drafts.js"></script>
        <script defer src="/assets/js/barcode-scan.js"></script>
        <a class="skip-link" href="#main-content">Skip to main content</a>
        <div class="workspace-header">
        <button class="sidebar-toggle" type="button" aria-controls="primary-navigation" aria-expanded="false">Menu</button>
        <nav class="workspace-topbar" aria-label="Breadcrumb"><a href="/dashboard">Workspace</a><span aria-hidden="true">/</span><span aria-current="page"><?= htmlspecialchars($workspaceTitle, ENT_QUOTES, 'UTF-8') ?></span></nav>
        </div>
