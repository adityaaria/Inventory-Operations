<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products - Inventory & Order Management</title>
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
</head>
<body>
    <main class="page">
        <header class="page-header">
            <div>
                <p class="app-title">Inventory Operations</p>
                <h1>Products</h1>
                <p class="page-subtitle">Search, filter, and review product stock levels.</p>
            </div>
            <nav class="toolbar">
                <a href="/">Home</a>
                <?php if ($canWrite): ?><a href="/products/create">Create Product</a><?php endif; ?>
                <?php if ($canWrite): ?><button type="button" class="button" data-dialog-open="import-dialog">Import CSV</button><?php endif; ?>
            </nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <form method="get" action="/products" class="filters">
            <input name="q" placeholder="Search name or SKU" value="<?= htmlspecialchars($criteria->term(), ENT_QUOTES, 'UTF-8') ?>">
            <select name="category_id">
                <option value="">All categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= $category->id() ?>" <?= $criteria->categoryId() === $category->id() ? 'selected' : '' ?>>
                        <?= htmlspecialchars($category->name(), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="stock_status">
                <option value="">All stock</option>
                <option value="low" <?= $criteria->stockStatus() === 'low' ? 'selected' : '' ?>>Low stock</option>
                <option value="normal" <?= $criteria->stockStatus() === 'normal' ? 'selected' : '' ?>>Normal stock</option>
            </select>
            <button type="submit">Filter</button>
        </form>
        <?php if ($canWrite): ?>
            <div class="import-dialog" id="import-dialog" <?= $error !== '' ? '' : 'hidden' ?>>
                <section class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="import-dialog-title" tabindex="-1">
                    <header class="modal-header">
                        <h2 id="import-dialog-title">Import CSV</h2>
                        <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
                    </header>
                    <div class="modal-body">
                        <form method="post" action="/products/import" enctype="multipart/form-data" class="form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <label>Import CSV <textarea name="csv_data" rows="3" placeholder="sku,name,unit,purchase_price,selling_price,reorder_point,category_id"></textarea></label>
                            <label>CSV File <input name="csv_file" type="file" accept=".csv,text/csv"></label>
                            <button type="submit">Import CSV</button>
                        </form>
                    </div>
                </section>
            </div>
        <?php endif; ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>Name</th>
                    <th>Unit</th>
                    <th>Purchase Price</th>
                    <th>Selling Price</th>
                    <th>Reorder</th>
                    <th>Warehouse Stock</th>
                    <?php if ($canWrite): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->items() === []): ?>
                    <tr class="empty">
                        <td colspan="<?= $canWrite ? 8 : 7 ?>">No products found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($result->items() as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product->sku(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($product->name(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($product->unit(), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= number_format($product->purchasePrice(), 2) ?></td>
                        <td><?= number_format($product->sellingPrice(), 2) ?></td>
                        <td><?= $product->reorderPoint() ?></td>
                        <td>
                            <?php foreach (($stocksByProduct[$product->id()] ?? []) as $stock): ?>
                                <div>
                                    <?= htmlspecialchars($stock->warehouseName(), ENT_QUOTES, 'UTF-8') ?>:
                                    <span class="<?= $stock->isLowStock() ? 'status-badge stock-low' : 'status-badge status-normal' ?>">
                                        <?= $stock->quantity() ?><?= $stock->isLowStock() ? ' Low' : ' In stock' ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </td>
                        <?php if ($canWrite): ?>
                            <td><a class="action-link" href="/products/edit?id=<?= $product->id() ?>">Edit</a></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        $baseQuery = [
            'q' => $criteria->term(),
            'category_id' => $criteria->categoryId(),
            'stock_status' => $criteria->stockStatus(),
            'sort' => $criteria->sortBy(),
            'direction' => $criteria->direction(),
            'per_page' => $criteria->perPage(),
        ];
        $previousPageUrl = '/products?' . http_build_query($baseQuery + ['page' => max(1, $result->page() - 1)]);
        $nextPageUrl = '/products?' . http_build_query($baseQuery + ['page' => min($result->pages(), $result->page() + 1)]);
        ?>
        <nav class="pagination" aria-label="Products pagination">
            <span class="pagination-summary">Total <?= $result->total() ?> products</span>
            <div class="pagination-actions">
                <?php if ($result->page() > 1): ?><a href="<?= htmlspecialchars($previousPageUrl, ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php else: ?><span aria-disabled="true">Previous</span><?php endif; ?>
                <strong class="pagination-current">Page <?= $result->page() ?> of <?= $result->pages() ?></strong>
                <?php if ($result->page() < $result->pages()): ?><a href="<?= htmlspecialchars($nextPageUrl, ENT_QUOTES, 'UTF-8') ?>">Next</a><?php else: ?><span aria-disabled="true">Next</span><?php endif; ?>
            </div>
        </nav>
    </main>
</body>
</html>
