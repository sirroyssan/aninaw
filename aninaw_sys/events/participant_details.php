<?php
/**
 * Participant details for an event (everyone in it: group members, people added directly
 * and anyone with a charge): ID, names, birthday, program, parent / guardian and contact,
 * address. On screen, printable and as CSV. Personal data: only the event's organizers,
 * document checkers and people managers may open it; every export is audited.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/event_requirements.php';
require_institution_context();

$eventId = (int) (get_int('event') ?? 0);
$event = require_found(find_event($eventId));
if (!can_check_event_documents($event) && !can('organization.manage')) {
    render_error_page(403, 'Not Allowed', 'Only the event\'s organizers or administrators can see participants\' personal details.');
}
$institutionId = (int) current_institution_id();
$roster = event_roster($eventId);
$typeFilter = isset(PARTICIPANT_TYPES[get_str('type', 20)]) ? get_str('type', 20) : '';
$people = $roster ? db_all(
    'SELECT p.id, p.participant_number, p.last_name, p.first_name, p.middle_name, p.no_middle_name, p.participant_type, p.birth_date, p.address,
            p.guardian_name, p.guardian_relationship, p.guardian_phone, p.phone, pr.program_code, pr.program_name
     FROM participants p LEFT JOIN programs pr ON pr.id = p.program_id AND pr.institution_id = p.institution_id
     WHERE p.institution_id = ? AND p.id IN (' . implode(',', array_map('intval', array_keys($roster))) . ')' . ($typeFilter ? ' AND p.participant_type = ?' : '') . '
     ORDER BY p.last_name, p.first_name, p.id',
    $typeFilter ? [$institutionId, $typeFilter] : [$institutionId]
) : [];

if (get_str('export', 10) === 'csv') {
    audit_log('report.exported', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['report' => 'event_participant_details', 'rows' => count($people), 'type' => $typeFilter ?: 'all']);
    send_csv('participants_' . $event['event_code'] . '_' . date('Ymd') . '.csv',
        ['Student ID', 'Last name', 'First name', 'Middle name', 'Birthday', 'Program', 'Program name', 'Type', 'Groups', 'Parent / guardian', 'Relationship', 'Guardian contact', 'Address', 'Phone'],
        array_map(static fn($p) => [$p['participant_number'], $p['last_name'], $p['first_name'], $p['middle_name'] ?: ($p['no_middle_name'] ? 'N/A' : ''), (string) $p['birth_date'],
            (string) $p['program_code'], (string) $p['program_name'], PARTICIPANT_TYPES[$p['participant_type']] ?? '', (string) ($roster[(int) $p['id']]['group_names'] ?? ''),
            (string) $p['guardian_name'], (string) $p['guardian_relationship'], (string) $p['guardian_phone'], (string) $p['address'], (string) $p['phone']], $people),
        [['Participant details', $event['event_name']], ['Generated', date('Y-m-d H:i') . ' by ' . auth_user()['username']]]);
}
audit_log('report.viewed', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['report' => 'event_participant_details', 'rows' => count($people)]);

$missing = static fn(array $p) => array_keys(array_filter([
    'birthday' => !$p['birth_date'], 'program' => $p['participant_type'] === 'student' && !$p['program_code'],
    'guardian' => $p['participant_type'] === 'student' && (!$p['guardian_name'] || !$p['guardian_phone']), 'address' => !$p['address'],
]));
$incomplete = count(array_filter($people, static fn($p) => $missing($p)));

$pageTitle = 'Participant Details';
$activeNav = 'events';
$breadcrumb = [auth_context()['label'], 'Events', $event['event_name']];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2><?= e($event['event_name']) ?> · Participant details</h2><p><?= e(auth_context()['label']) ?> · <?= number_format(count($people)) ?> people · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(auth_user()['username']) ?></p></div>
<?= page_actions('Everyone in the event with their details. Contains personal data: share only with people who need it.',
    button_link('events/view.php?id=' . $eventId . '&tab=people', 'Event', 'bi-arrow-left', 'btn-outline-secondary')
    . '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv'])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>
<?php if ($incomplete): ?>
    <div class="notice notice-warning d-print-none"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><?= number_format($incomplete) ?> of <?= number_format(count($people)) ?> records are missing a birthday, program, parent / guardian or address. Open a name to complete it.</div></div>
<?php endif; ?>
<div class="card panel">
    <form class="filter-bar d-print-none" method="get">
        <input type="hidden" name="event" value="<?= (int) $eventId ?>">
        <div class="filter-field"><label for="type">Type</label><select class="form-select" id="type" name="type" data-autosubmit><?= options_html(PARTICIPANT_TYPES, $typeFilter, 'Everyone') ?></select></div>
    </form>
    <?php if (!$people): ?>
        <div class="card-body"><?= empty_state('bi-people', 'No one in this event yet', 'Add people under the event\'s People tab or assign groups under Setup.') ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-clean table-report align-middle mb-0">
            <thead><tr><th>Student ID</th><th>Last name</th><th>First name</th><th>Middle name</th><th>Birthday</th><th>Program</th><th>Parent / guardian</th><th>Guardian contact</th><th>Address</th></tr></thead>
            <tbody><?php foreach ($people as $p): $gaps = $missing($p); ?>
                <tr>
                    <td class="fw-semibold"><?= e($p['participant_number']) ?><?= $gaps ? '<div class="d-print-none"><span class="badge badge-soft-warning" title="Missing: ' . e(implode(', ', $gaps)) . '">Incomplete</span></div>' : '' ?></td>
                    <td><a href="<?= e(url('participants/view.php?id=' . $p['id'])) ?>"><?= e($p['last_name']) ?></a></td>
                    <td><?= e($p['first_name']) ?></td>
                    <td><?= e($p['middle_name'] ?: ($p['no_middle_name'] ? 'N/A' : '')) ?></td>
                    <td><?= e($p['birth_date'] ? format_date($p['birth_date']) : '') ?></td>
                    <td title="<?= e((string) $p['program_name']) ?>"><?= e((string) $p['program_code']) ?></td>
                    <td><?= e((string) $p['guardian_name']) ?><?= $p['guardian_relationship'] ? '<div class="text-muted-sm">' . e($p['guardian_relationship']) . '</div>' : '' ?></td>
                    <td><?= e((string) $p['guardian_phone']) ?></td>
                    <td class="text-muted-sm" style="min-width: 180px; max-width: 260px"><?= e((string) $p['address']) ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
