<!doctype html>
<html lang="en">
<head>
    <script src="/assets/js/page-transitions.js"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> - Inventory &amp; Order Management</title>
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
<?php $workspaceTitle=$title; require dirname(__DIR__).'/partials/workspace-start.php'; $escape=static fn($value): string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); ?>
<main class="page" id="main-content">
<header class="page-header"><div><p class="app-title">Inventory Operations</p><h1><?= $escape($title) ?></h1><p class="page-subtitle"><?= $escape($subtitle) ?></p></div><nav class="toolbar" aria-label="Page actions"><?= $toolbar ?></nav></header>
<?php if(($error??'')!==''): ?><p class="alert alert-danger" role="alert"><?= $escape($error) ?></p><?php endif; ?>
