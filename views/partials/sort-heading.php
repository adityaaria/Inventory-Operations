<?php
// $sortKey, $sortLabel, $criteria and $sortPath are supplied by the list view.
// Server-side sort of the whole filtered result; the arrow shows the active direction (↕ when inactive).
$headingActive = $criteria->sortBy() === $sortKey;
$headingDirection = $headingActive && $criteria->direction() === 'asc' ? 'desc' : 'asc';
$headingQuery = array_filter($_GET, static fn ($value): bool => is_string($value) || is_int($value));
$headingQuery['sort'] = $sortKey;
$headingQuery['direction'] = $headingDirection;
$headingQuery['page'] = 1;
$headingUrl = $sortPath . '?' . http_build_query($headingQuery);
$headingAria = $headingActive ? ($criteria->direction() === 'asc' ? 'ascending' : 'descending') : 'none';
$headingArrow = $headingActive ? ($criteria->direction() === 'asc' ? '↑' : '↓') : '↕';
$headingAction = 'Sort by ' . $sortLabel . ($headingDirection === 'asc' ? ', ascending' : ', descending');
?>
<th scope="col" data-sort-key="<?= htmlspecialchars($sortKey, ENT_QUOTES, 'UTF-8') ?>" aria-sort="<?= $headingAria ?>"><a class="sort-link<?= $headingActive ? ' is-active' : '' ?>" href="<?= htmlspecialchars($headingUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($headingAction, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($sortLabel, ENT_QUOTES, 'UTF-8') ?><span class="sort-indicator" aria-hidden="true"><?= $headingArrow ?></span></a></th>
