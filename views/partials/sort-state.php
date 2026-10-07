<?php // Keeps the header-selected sort when filters are applied; $criteria is supplied by the list view. ?>
<input type="hidden" name="sort" value="<?= htmlspecialchars($criteria->sortBy(), ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="direction" value="<?= htmlspecialchars($criteria->direction(), ENT_QUOTES, 'UTF-8') ?>">
