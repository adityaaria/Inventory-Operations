<?php
// Links between the valuation reports (D-20, ADR-026; Admin only). $current names the active page.
$valuationLinks = ['valuation' => ['/reports/valuation', 'Inventory Valuation'], 'reconciliation' => ['/reports/valuation-reconciliation', 'Reconciliation'], 'margin' => ['/reports/gross-margin', 'Gross Margin'], 'losses' => ['/reports/inventory-losses', 'Losses']];
$valuationNav = '';
foreach ($valuationLinks as $valuationKey => [$valuationHref, $valuationLabel]) {
    if ($valuationKey !== ($current ?? '')) {
        $valuationNav .= '<a href="' . $valuationHref . '">' . $valuationLabel . '</a>';
    }
}
$valuationStartNote = static function (array $start): string {
    if ($start['started_at'] === null) {
        return 'Every movement is valued at its lot cost (no history before the valuation start).';
    }

    return 'Valuation starts ' . \App\Service\InventoryValuationService::label((string) $start['started_at']) . ' (ledger rows up to #' . (int) $start['watermark'] . ' predate lots and have no cost; they are reported as "before valuation start" and never given one).';
};
$rupiahCents = static fn (int $cents): string => \App\Support\Money::rupiah($cents / 100);
