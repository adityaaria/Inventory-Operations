<?php
// Reusable barcode scan panel (D-18, ADR-024); behaviour in public/assets/js/barcode-scan.js.
// Inputs: $scanId (unique id prefix), $scanMode ('receive' | 'pick' | 'count'), $scanTitle, $scanHelp.
// Keyboard-wedge scanners type into the focused field and press Enter; the camera button only appears when the browser
// supports BarcodeDetector. The field has no name and sits outside every form, so it is never submitted or drafted.
$scanEscape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$scanPick = $scanMode === 'pick';
?>
<section class="card scan-panel" data-barcode-scanner data-scan-<?= $scanEscape($scanMode) ?> aria-labelledby="<?= $scanEscape($scanId) ?>-title">
    <div class="card-body">
        <div class="scan-panel-header">
            <h2 id="<?= $scanEscape($scanId) ?>-title"><?= $scanEscape($scanTitle) ?></h2>
            <?php if ($scanPick): ?><button type="button" class="button" data-pick-toggle aria-pressed="false" aria-controls="<?= $scanEscape($scanId) ?>-body">Start scan to pick</button><?php endif; ?>
        </div>
        <div id="<?= $scanEscape($scanId) ?>-body" class="scan-panel-body" <?= $scanPick ? 'data-pick-body hidden' : '' ?>>
            <label class="field" for="<?= $scanEscape($scanId) ?>-input">
                <span class="field-label">Barcode</span>
                <input id="<?= $scanEscape($scanId) ?>-input" type="text" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="done" maxlength="64"
                       aria-describedby="<?= $scanEscape($scanId) ?>-help <?= $scanEscape($scanId) ?>-status" data-scan-input>
            </label>
            <button type="button" class="button button-quiet" data-scan-camera hidden aria-pressed="false">Scan with camera</button>
            <video class="scan-video" data-scan-video hidden playsinline muted></video>
            <p id="<?= $scanEscape($scanId) ?>-help" class="field-hint"><?= $scanEscape($scanHelp) ?></p>
        </div>
        <p id="<?= $scanEscape($scanId) ?>-status" class="scan-status" role="status" aria-live="polite" aria-atomic="true" data-scan-status></p>
    </div>
</section>
