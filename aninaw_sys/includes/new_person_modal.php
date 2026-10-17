<?php
/**
 * "Add a new person" modal: registers someone not yet in People (strict: students need a middle
 * name or "No middle name") and the page's action adds them where they were needed (a group, an event).
 * Expects $newPerson = ['action', 'title', 'intro', 'submit', 'type' (default type), 'hidden' (extra inputs HTML)].
 * Reopens with the typed values when the save came back with an error. member_picker.js prefills the name.
 */
$reopen = old('action') === $newPerson['action'];
?>
<div class="modal fade" id="new-member-modal" tabindex="-1" aria-labelledby="new-member-title" aria-hidden="true"<?= $reopen ? ' data-open-on-load' : '' ?>>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <form method="post" class="modal-content">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <input type="hidden" name="action" value="<?= e($newPerson['action']) ?>"><?= $newPerson['hidden'] ?? '' ?>
            <div class="modal-header">
                <h2 class="modal-title h5" id="new-member-title"><?= e($newPerson['title']) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted-sm"><?= e($newPerson['intro']) ?></p>
                <?php $pf = static fn(string $key, $default = '') => $reopen ? old($key, $default) : $default; $pfPrefix = 'nm'; $pfType = $newPerson['type']; require APP_ROOT . '/includes/person_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1" aria-hidden="true"></i><?= e($newPerson['submit']) ?></button>
            </div>
        </form>
    </div>
</div>
