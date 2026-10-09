<?php
// Pop-up form that asks for a written reason before a rejecting action (opened by a [data-dialog-open] button).
// $dialog: id, title, intro, action, hidden (name => value), label, submit, open (bool), reason (old value), error.
$dialogEscape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$dialogId = $dialogEscape($dialog['id']);
?>
<div class="import-dialog reason-dialog" id="<?= $dialogId ?>" <?= ($dialog['open'] ?? false) ? '' : 'hidden' ?>>
    <dialog open class="modal-panel" aria-modal="true" aria-labelledby="<?= $dialogId ?>-title" tabindex="-1">
        <header class="modal-header">
            <h2 id="<?= $dialogId ?>-title"><?= $dialogEscape($dialog['title']) ?></h2>
            <button class="modal-close" type="button" aria-label="Close dialog">Close</button>
        </header>
        <div class="modal-body">
            <form method="post" action="<?= $dialogEscape($dialog['action']) ?>" class="form" data-skip-confirm>
                <input type="hidden" name="csrf_token" value="<?= $dialogEscape($GLOBALS['csrf_token'] ?? '') ?>">
                <?php foreach ($dialog['hidden'] as $dialogName => $dialogValue): ?>
                    <input type="hidden" name="<?= $dialogEscape($dialogName) ?>" value="<?= $dialogEscape($dialogValue) ?>">
                <?php endforeach; ?>
                <p><?= $dialogEscape($dialog['intro']) ?></p>
                <?php if (($dialog['error'] ?? '') !== ''): ?><p class="alert alert-danger" role="alert"><?= $dialogEscape($dialog['error']) ?></p><?php endif; ?>
                <label class="field"><span class="field-label"><?= $dialogEscape($dialog['label']) ?></span><textarea name="reason" maxlength="500" rows="4" required data-dialog-focus><?= $dialogEscape($dialog['reason'] ?? '') ?></textarea></label>
                <div class="modal-footer"><button type="button" class="button button-quiet" data-dialog-close>Cancel</button><button type="submit" class="button-danger"><?= $dialogEscape($dialog['submit']) ?></button></div>
            </form>
        </div>
    </dialog>
</div>
