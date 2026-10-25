<?php
// Printable document layout (D-19, ADR-025): quotation, delivery note (surat jalan), invoice. No navigation; the toolbar
// is hidden when printing (print.css) and the Print button calls window.print() (print.js, no inline script).
$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$companyName = (string) ($GLOBALS['app_name'] ?? 'Inventory & Order Management');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($printTitle) ?> - <?= $escape($companyName) ?></title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/css/print.css">
    <script defer src="/assets/js/print.js"></script>
</head>
<body class="print-document">
<nav class="print-toolbar no-print" aria-label="Document actions"><a href="<?= $escape($backHref) ?>">Back</a><button type="button" data-print>Print</button></nav>
<main class="print-page" id="main-content">
<header class="print-header"><div><p class="print-company"><?= $escape($companyName) ?></p><h1><?= $escape($printHeading) ?></h1></div><div class="print-number"><strong><?= $escape($printNumber) ?></strong><?php if (($printStatus ?? '') !== ''): ?><span class="print-status"><?= $escape($printStatus) ?></span><?php endif; ?></div></header>
