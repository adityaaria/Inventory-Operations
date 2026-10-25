<?php
// Price source of an SO or quotation line (D-19, ADR-025). $sourceLine: price_source, price_list_name (optional).
$sourceLabel = ($sourceLine['price_source'] ?? 'MASTER') === 'PRICE_LIST'
    ? 'Price list' . (($sourceLine['price_list_name'] ?? null) !== null ? ' ' . $sourceLine['price_list_name'] : '')
    : 'Master price';
?><span class="status-badge <?= ($sourceLine['price_source'] ?? 'MASTER') === 'PRICE_LIST' ? 'status-success' : 'status-normal' ?>" data-price-source><?= htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8') ?></span>
