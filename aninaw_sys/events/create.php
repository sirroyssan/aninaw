<?php
/**
 * Create or edit an event. Funds, groups, organizers and collection rules are set on the event page.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/events.php';
require_institution_context();
require_permission('events.manage');

$eventId = get_int('id');
$event = $eventId ? require_found(find_event($eventId)) : null;
if ($event && !$event['can_manage']) {
    render_error_page(403, 'Access Denied', 'Only the event creator or its assigned organizers can edit this event.');
}

if (is_post()) {
    handle_post(function () use ($eventId) {
        $id = save_event($eventId, $_POST);
        flash('success', $eventId ? 'Event updated' : 'Event created', $eventId ? '' : 'Next: assign the fund, the participating groups and the collection rules.');
        return 'events/view.php?id=' . $id . ($eventId ? '' : '&tab=setup');
    });
}

$field = static fn(string $key, string $default = '') => $event[$key] ?? old($key, $default);

$pageTitle = $event ? 'Edit Event' : 'New Event';
$activeNav = 'events';
$breadcrumb = [auth_context()['label'], 'Events'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" class="card panel form-card" style="max-width: 880px">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label required" for="event_code">Code</label><input class="form-control" id="event_code" name="event_code" required maxlength="60" value="<?= e($field('event_code')) ?>" placeholder="ICSFEST26"></div>
            <div class="col-md-8"><label class="form-label required" for="event_name">Name</label><input class="form-control" id="event_name" name="event_name" required maxlength="180" value="<?= e($field('event_name')) ?>" placeholder="ICS Fest 2026"></div>
            <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" rows="3" maxlength="4000"><?= e($field('description')) ?></textarea></div>
            <div class="col-md-4"><label class="form-label" for="start_date">Start date</label><input class="form-control" type="date" id="start_date" name="start_date" value="<?= e($field('start_date')) ?>"></div>
            <div class="col-md-4"><label class="form-label" for="end_date">End date</label><input class="form-control" type="date" id="end_date" name="end_date" value="<?= e($field('end_date')) ?>"></div>
            <div class="col-md-4"><label class="form-label" for="collection_deadline">Collection deadline</label><input class="form-control" type="date" id="collection_deadline" name="collection_deadline" value="<?= e($field('collection_deadline')) ?>"></div>
        </div>
        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url($event ? 'events/view.php?id=' . $event['id'] : 'events/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i><?= $event ? 'Save Changes' : 'Create Event' ?></button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
