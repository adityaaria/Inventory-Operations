<?php
// $sortKey, $sortLabel, $criteria and $sortPath are supplied by the list view.
$headingDirection = $criteria->sortBy() === $sortKey && $criteria->direction() === 'asc' ? 'desc' : 'asc';
$headingQuery = array_filter($_GET, static fn ($value): bool => is_string($value) || is_int($value));
$headingQuery['sort'] = $sortKey;
$headingQuery['direction'] = $headingDirection;
$headingQuery['page'] = 1;
$headingUrl = $sortPath . '?' . http_build_query($headingQuery);
$headingAria = $criteria->sortBy() === $sortKey ? ($criteria->direction() === 'asc' ? 'ascending' : 'descending') : 'none';
?>
<th data-sort-key="<?= htmlspecialchars($sortKey, ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= $headingAria ?>"><a href="<?= htmlspecialchars($headingUrl, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sortLabel, ENT_QUOTES, 'UTF-8') ?></a></th>
