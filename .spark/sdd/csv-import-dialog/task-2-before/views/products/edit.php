<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Product - Inventory & Order Management</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/app.css">
    <script defer src="/assets/js/http.js"></script>
    <script defer src="/assets/js/ui-helpers.js"></script>
    <script defer src="/assets/js/form-validation.js"></script>
    <script defer src="/assets/js/navigation.js"></script>
    <script defer src="/assets/js/modal.js"></script>
    <script defer src="/assets/js/charts.js"></script>
    <script defer src="/assets/js/forms.js"></script>
    <script defer src="/assets/js/tables.js"></script>
    <script defer src="/assets/js/app.js"></script>
</head>
<body>
    <main class="page">
        <h1>Edit Product</h1>
        <?php if ($error !== ''): ?><p class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($product !== null): ?>
            <form method="post" action="/products/update" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $product->id() ?>">
                <label>SKU <input name="sku" required value="<?= htmlspecialchars($product->sku(), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Name <input name="name" required value="<?= htmlspecialchars($product->name(), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Unit <input name="unit" required value="<?= htmlspecialchars($product->unit(), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label>Purchase Price <input name="purchase_price" type="number" min="0" step="0.01" required value="<?= $product->purchasePrice() ?>"></label>
                <label>Selling Price <input name="selling_price" type="number" min="0" step="0.01" required value="<?= $product->sellingPrice() ?>"></label>
                <label>Reorder Point <input name="reorder_point" type="number" min="0" required value="<?= $product->reorderPoint() ?>"></label>
                <label>
                    Category
                    <select name="category_id" required>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= $category->id() ?>" <?= $category->id() === $product->categoryId() ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category->name(), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Update</button>
                <button type="button" class="button" data-cancel-href="/products">Cancel</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
