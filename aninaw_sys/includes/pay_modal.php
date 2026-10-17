<?php
/**
 * The "Record payment" pop-up used by the Collections list and an event's Balances tab:
 * links with data-pay (assets/js/collections.js) load the payment form for one person and event into it.
 */
?>
<div class="modal fade" id="pay-modal" tabindex="-1" aria-labelledby="pay-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="pay-modal-title"><span class="text-muted-sm d-block">Record payment</span><span data-pay-title></span></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0" data-pay-body></div>
            <div class="modal-footer justify-content-start py-2"><a class="text-muted-sm" href="#" data-pay-full>Open the full payment page</a></div>
        </div>
    </div>
</div>
