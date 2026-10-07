<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reports - Inventory & Order Management</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script defer src="/assets/js/http.js"></script>
    <script defer src="/assets/js/ui-helpers.js"></script>
    <script defer src="/assets/js/form-validation.js"></script>
    <script defer src="/assets/js/navigation.js"></script>
    <script defer src="/assets/js/modal.js"></script>
    <script defer src="/assets/js/dialog.js"></script>
    <script defer src="/assets/js/charts.js"></script>
    <script defer src="/assets/js/forms.js"></script>
    <script defer src="/assets/js/tables.js"></script>
    <script defer src="/assets/js/app.js"></script>
    <script defer src="/assets/js/inventory-operations.js"></script>
</head>
<body>
    <?php $workspaceTitle = 'Reports'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header">
            <div>
                <p class="app-title">Inventory Operations</p>
                <h1>Reports</h1>
                <p class="page-subtitle"><?= match ($type) { 'orders' => 'Order status report', 'outstanding' => 'Outstanding orders by age', default => 'Stock movement report' } ?> · <?= htmlspecialchars(($from ?? ($to !== null ? 'Beginning' : 'All dates')) . ($to !== null ? ' — ' . $to : ($from !== null ? ' onwards' : '')), ENT_QUOTES, 'UTF-8') ?><?= $actor->role() === \App\Entity\User::ROLE_SALES ? ' · Your orders only' : '' ?></p>
                <?php if ($type === 'outstanding'): ?><p class="page-subtitle">Age is whole days since the document was created. It is not a due date, SLA or lateness measure.<?= $actor->role() === \App\Entity\User::ROLE_WAREHOUSE_STAFF ? ' Showing receipts, issues and approved stock proposals awaiting warehouse action.' : '' ?></p><?php endif; ?>
            </div>
            <nav class="toolbar" aria-label="Report actions">
                <?php if ($report !== null): ?><a class="button" href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" download>Export CSV</a><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($report !== null): ?>
            <section class="metric-grid" aria-label="Report summary">
                <?php foreach ($report['metrics'] as $label => $value): ?>
                    <article class="metric-card"><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format($value) ?></strong></article>
                <?php endforeach; ?>
            </section>
            <section class="dashboard-grid report-charts" aria-label="Report charts">
                <?php foreach ($report['charts'] as $label => $values): ?>
                    <?php $chartId = 'report-' . strtolower(str_replace(' ', '-', $label)); ?>
                    <article class="dashboard-panel">
                        <h2 id="<?= $chartId ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h2>
                        <div class="chart" data-chart="<?= htmlspecialchars((string) json_encode($values), ENT_QUOTES, 'UTF-8') ?>" aria-labelledby="<?= $chartId ?>"></div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <section class="report-details" aria-label="Report details">
            <form class="filters" method="get" action="/reports">
                <label class="field"><span class="field-label">Report</span><select name="type">
                    <option value="orders" <?= $type === 'orders' ? 'selected' : '' ?>>Order Status</option>
                    <option value="outstanding" <?= $type === 'outstanding' ? 'selected' : '' ?>>Outstanding &amp; Aging</option>
                    <?php if ($canViewStock): ?><option value="stock-ledger" <?= $type === 'stock-ledger' ? 'selected' : '' ?>>Stock Movements</option><?php endif; ?>
                </select></label>
                <?php if ($type === 'outstanding'): ?>
                    <label class="field"><span class="field-label">Document</span><select name="document">
                        <option value="">All documents</option>
                        <?php foreach ($outstandingDocuments as $code => $label): ?><option value="<?= $code ?>" <?= ($filterQuery['document'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                    </select></label>
                    <label class="field"><span class="field-label">Age</span><select name="age">
                        <option value="">Any age</option>
                        <?php foreach (\App\Repository\Contract\OperationalQueryRepositoryInterface::AGE_BUCKETS as $bucket): ?><option value="<?= htmlspecialchars($bucket, ENT_QUOTES, 'UTF-8') ?>" <?= ($filterQuery['age'] ?? '') === $bucket ? 'selected' : '' ?>><?= htmlspecialchars($bucket, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
                    </select></label>
                <?php endif; ?>
                <label class="field"><span class="field-label"><?= $type === 'outstanding' ? 'Created from' : 'From' ?></span><input name="from" type="date" value="<?= htmlspecialchars($from ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
                <label class="field"><span class="field-label"><?= $type === 'outstanding' ? 'Created to' : 'To' ?></span><input name="to" type="date" value="<?= htmlspecialchars($to ?? '', ENT_QUOTES, 'UTF-8') ?>"></label>
                <button type="submit">Apply Filters</button>
                <a class="button button-quiet" href="/reports">Reset</a>
            </form>
            <?php if ($report !== null && $result !== null): ?>
                <div class="table-scroll report-table-scroll" role="region" aria-label="Report table" tabindex="0">
                    <table class="data-table" data-export="server" id="report-records">
                        <thead><tr><?php foreach ($report['columns'] as $column): ?><th scope="col"><?= htmlspecialchars((string) preg_replace('/(?<!^)([A-Z][a-z])/', ' $1', $column), ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                            <?php if ($result->items() === []): ?><tr class="empty"><td colspan="<?= count($report['columns']) ?>"><?= $type === 'outstanding' ? 'No open documents match this period.' : 'No records match this period. Adjust the dates or choose another report.' ?></td></tr><?php endif; ?>
                            <?php foreach ($result->items() as $row): ?>
                                <tr>
                                    <?php foreach ($report['columns'] as $column): ?>
                                        <td>
                                            <?php if ($column === 'Status'): ?>
                                                <?php
                                                $reportRowStatus = (string) ($row[$column] ?? '');
                                                $statusClass = match ($reportRowStatus) {
                                                    'PartiallyReceived' => 'partial',
                                                    'PendingApproval' => 'pending',
                                                    default => strtolower(str_replace([' ', '_'], '-', $reportRowStatus)),
                                                };
                                                $statusTone = match ($reportRowStatus) {
                                                    'Received', 'Approved', 'Fulfilled' => 'status-success',
                                                    'Ordered', 'PartiallyReceived', 'PendingApproval' => 'status-warning',
                                                    'Cancelled' => 'status-danger',
                                                    default => 'status-normal',
                                                };
                                                ?>
                                                <span class="status-badge <?= $statusTone ?> status-<?= htmlspecialchars($statusClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($reportRowStatus, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php else: ?>
                                                <?= htmlspecialchars((string) ($row[$column] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php require dirname(__DIR__) . '/partials/pagination.php'; ?>
            <?php endif; ?>
        </section>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
