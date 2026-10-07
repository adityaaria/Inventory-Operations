<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Category - Inventory & Order Management</title>
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
    <?php $formState = new \App\Support\FormState($old ?? []); ?>
    <?php $workspaceTitle = 'Edit Category'; require dirname(__DIR__) . '/partials/workspace-start.php'; ?>
    <main class="page" id="main-content">
        <header class="page-header">
            <div><p class="app-title">Inventory Operations</p><h1>Edit Category</h1><p class="page-subtitle">Update the category details.</p></div>
            <nav class="toolbar"><a href="/categories">Categories</a></nav>
        </header>
        <?php if ($error !== ''): ?><p class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if ($category !== null): ?>
            <form method="post" action="/categories/update" class="form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($GLOBALS['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="id" value="<?= $category->id() ?>">
                <label class="field"><span class="field-label">Name</span><input name="name" required value="<?= htmlspecialchars($formState->value('name', $category->name()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                <label class="field"><span class="field-label">Description</span><textarea name="description" rows="3"><?= htmlspecialchars($formState->value('description', $category->description()), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea></label>
                <div class="form-actions"><button type="submit">Update</button><button type="button" class="button button-quiet" data-cancel-href="/categories">Cancel</button></div>
            </form>
        <?php endif; ?>
    </main>
    <?php require dirname(__DIR__) . '/partials/workspace-end.php'; ?>
</body>
</html>
