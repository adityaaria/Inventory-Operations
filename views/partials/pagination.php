<?php
$previousPageUrl = $paginationPath . '?' . http_build_query(array_replace($paginationQuery, ['page' => max(1, $result->page() - 1)]));
$nextPageUrl = $paginationPath . '?' . http_build_query(array_replace($paginationQuery, ['page' => min($result->pages(), $result->page() + 1)]));
?>
<nav class="pagination" aria-label="<?= htmlspecialchars($paginationLabel . ' pagination', ENT_QUOTES, 'UTF-8') ?>">
    <span class="pagination-summary">Total <?= $result->total() ?> <?= htmlspecialchars(strtolower($paginationLabel), ENT_QUOTES, 'UTF-8') ?></span>
    <div class="pagination-actions">
        <?php if ($result->page() > 1): ?><a href="<?= htmlspecialchars($previousPageUrl, ENT_QUOTES, 'UTF-8') ?>">Previous</a><?php else: ?><span aria-disabled="true">Previous</span><?php endif; ?>
        <strong class="pagination-current" aria-current="page">Page <?= $result->page() ?> of <?= $result->pages() ?></strong>
        <?php if ($result->page() < $result->pages()): ?><a href="<?= htmlspecialchars($nextPageUrl, ENT_QUOTES, 'UTF-8') ?>">Next</a><?php else: ?><span aria-disabled="true">Next</span><?php endif; ?>
    </div>
</nav>
