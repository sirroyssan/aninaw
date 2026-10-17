<?php
/**
 * Person pop-up (assets/js/person_modal.js): names with data-person-card open participants/card.php in it.
 * Optional $personModalEvent: event whose charges the card shows. Reopens after an "Edit details" error.
 */
$personModalEvent ??= null;
$reopenPerson = old('action') === 'edit_person' ? (int) old('participant_id') : 0;
if ($reopenPerson) {
    pass_old_input_on();
}
?>
<div class="modal fade" id="person-modal" tabindex="-1" aria-labelledby="person-modal-title" aria-hidden="true"<?= $reopenPerson ? ' data-reopen-card="' . e(url('participants/card.php?id=' . $reopenPerson . ($personModalEvent ? '&event=' . $personModalEvent : '') . '&fragment=1')) . '" data-reopen-profile="' . e(url('participants/view.php?id=' . $reopenPerson)) . '"' : '' ?>>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="person-modal-title" data-person-title>Person</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" data-person-body></div>
            <div class="modal-footer justify-content-start py-2"><a class="text-muted-sm" href="#" data-person-full>Open the full profile (payments, receipts, documents)</a></div>
        </div>
    </div>
</div>
