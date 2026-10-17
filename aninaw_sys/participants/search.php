<?php
/**
 * People search for pickers (JSON): ?q= (2+ characters, name or ID number), optional
 * &group= to flag who is already an active member. At most 20 results; only people the
 * user may see (participant_visibility). Nothing is listed without a search.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission(['participants.view', 'organization.manage']);

$q = trim(get_str('q', 100));
if (mb_strlen($q) < 2) {
    json_response(['ok' => true, 'results' => []]);
}
$groupId = get_int('group') ?? 0;
[$visibility, $visParams] = participant_visibility('p');
$like = '%' . addcslashes($q, '%_\\') . '%';
$rows = db_all(
    "SELECT p.id, p.participant_number, p.first_name, p.middle_name, p.last_name, p.participant_type,
            EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = p.institution_id AND gm.group_id = ? AND gm.participant_id = p.id AND gm.status = 'active') AS in_group
     FROM participants p
     WHERE p.institution_id = ? AND p.status = 'active' AND {$visibility}
       AND (p.participant_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR CONCAT(p.last_name, ', ', p.first_name) LIKE ?)
     ORDER BY (p.participant_number = ?) DESC, p.last_name, p.first_name LIMIT 20",
    array_merge([$groupId, current_institution_id()], $visParams, array_fill(0, 5, $like), [$q])
);
json_response(['ok' => true, 'results' => array_map(static fn($r) => [
    'id' => (int) $r['id'],
    'number' => $r['participant_number'],
    'name' => $r['last_name'] . ', ' . $r['first_name'] . ($r['middle_name'] ? ' ' . mb_substr($r['middle_name'], 0, 1) . '.' : ''),
    'type' => PARTICIPANT_TYPES[$r['participant_type']] ?? $r['participant_type'],
    'in_group' => (bool) $r['in_group'],
], $rows)]);
