<?php
/**
 * Authorization helpers and role-aware navigation.
 * Permissions always come from the database for the active context (see auth_bootstrap_request()).
 */
declare(strict_types=1);

function can(string $permission): bool
{
    return isset(auth_state()['permissions'][$permission]);
}

function can_any(array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (can($permission)) {
            return true;
        }
    }
    return false;
}

/** Stops the request with a 403 page unless the user holds at least one of the permissions. */
function require_permission(string|array $permissions): void
{
    $permissions = (array) $permissions;
    if (can_any($permissions)) {
        return;
    }

    $user = auth_user();
    audit_log(
        'auth.permission_denied',
        'permission',
        null,
        current_institution_id(),
        $user ? (int) $user['id'] : null,
        ['required' => $permissions, 'path' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 255)]
    );

    if (wants_json()) {
        json_response(['ok' => false, 'message' => 'You do not have permission to perform this action.'], 403);
    }
    render_error_page(403, 'Access Denied', 'You do not have permission to open this page. If you believe this is a mistake, contact your institution administrator.');
}

/** Requires an institution context (as opposed to platform administration). */
function require_institution_context(): void
{
    if (current_institution_id() === null) {
        render_error_page(403, 'Institution Required', 'This page is available only while working inside an institution. Switch to an institution to continue.');
    }
}

/**
 * Sidebar navigation for the active context. Items appear only when the user holds
 * one of the listed permissions. `ready` marks modules that are fully built; the rest
 * open a protected page announcing the module and carry a "Soon" badge.
 */
function navigation_sections(): array
{
    $context = auth_context();
    if (!$context) {
        return [];
    }

    if ($context['type'] === 'platform') {
        $sections = [
            'Platform' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'path' => 'dashboard/index.php', 'any' => ['platform.institutions.view'], 'ready' => true],
                ['key' => 'institutions', 'label' => 'Institutions', 'icon' => 'bi-building', 'path' => 'institutions/index.php', 'any' => ['platform.institutions.view'], 'ready' => true],
                ['key' => 'users', 'label' => 'Users', 'icon' => 'bi-people', 'path' => 'users/index.php', 'any' => ['platform.users.view'], 'ready' => true],
                ['key' => 'audit', 'label' => 'Audit Trail', 'icon' => 'bi-clipboard-check', 'path' => 'reports/audit_trail.php', 'any' => ['platform.audit.view'], 'ready' => false],
            ],
        ];
    } else {
        $sections = [
            'Overview' => [
                ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-grid-1x2', 'path' => 'dashboard/index.php', 'any' => ['dashboard.view'], 'ready' => true],
            ],
            'Administration' => [
                ['key' => 'settings', 'label' => 'Institution Settings', 'icon' => 'bi-gear', 'path' => 'institutions/settings.php', 'any' => ['institution.settings.manage'], 'ready' => true],
                ['key' => 'users', 'label' => 'Users', 'icon' => 'bi-people', 'path' => 'users/index.php', 'any' => ['users.view'], 'ready' => true],
                ['key' => 'roles', 'label' => 'Roles & Permissions', 'icon' => 'bi-shield-lock', 'path' => 'users/roles.php', 'any' => ['roles.assign'], 'ready' => true],
            ],
            'Organization' => [
                ['key' => 'groups', 'label' => 'Departments & Groups', 'icon' => 'bi-diagram-3', 'path' => 'groups/index.php', 'any' => ['participants.view', 'organization.manage'], 'ready' => true],
                ['key' => 'participants', 'label' => 'Participants', 'icon' => 'bi-person-vcard', 'path' => 'participants/index.php', 'any' => ['participants.view', 'organization.manage'], 'ready' => true],
            ],
            'Collections' => [
                ['key' => 'events', 'label' => 'Events', 'icon' => 'bi-calendar-event', 'path' => 'events/index.php', 'any' => ['events.view', 'events.manage'], 'ready' => true],
                ['key' => 'collections', 'label' => 'Collections', 'icon' => 'bi-cash-coin', 'path' => 'collections/index.php', 'any' => ['collections.view', 'collections.record'], 'ready' => true],
                ['key' => 'turnovers', 'label' => 'Cash Turnovers', 'icon' => 'bi-arrow-left-right', 'path' => 'turnovers/index.php', 'any' => ['turnovers.view', 'turnovers.submit', 'turnovers.receive'], 'ready' => true],
            ],
            'Finance' => [
                ['key' => 'funds', 'label' => 'Funds', 'icon' => 'bi-wallet', 'path' => 'funds/index.php', 'any' => ['funds.view'], 'ready' => true],
                ['key' => 'accounts', 'label' => 'Financial Accounts', 'icon' => 'bi-bank2', 'path' => 'funds/accounts.php', 'any' => ['funds.view_all', 'funds.manage', 'reconciliation.manage'], 'ready' => true],
                ['key' => 'expenses', 'label' => 'Expenses', 'icon' => 'bi-receipt', 'path' => 'expenses/index.php', 'any' => ['expenses.view'], 'ready' => false],
                ['key' => 'advances', 'label' => 'Cash Advances', 'icon' => 'bi-wallet2', 'path' => 'advances/index.php', 'any' => ['advances.view', 'advances.request', 'self.advances.view'], 'ready' => false],
                ['key' => 'liquidation', 'label' => 'Liquidation', 'icon' => 'bi-journal-check', 'path' => 'liquidation/index.php', 'any' => ['liquidation.submit', 'liquidation.review'], 'ready' => false],
                ['key' => 'cash-flow', 'label' => 'Cash Flow', 'icon' => 'bi-activity', 'path' => 'cash_flow/index.php', 'any' => ['cashflow.view'], 'ready' => false],
                ['key' => 'deposits', 'label' => 'Deposits', 'icon' => 'bi-bank', 'path' => 'deposits/index.php', 'any' => ['deposits.manage'], 'ready' => false],
                ['key' => 'transfers', 'label' => 'Transfers', 'icon' => 'bi-shuffle', 'path' => 'transfers/index.php', 'any' => ['transfers.manage'], 'ready' => false],
                ['key' => 'reconciliation', 'label' => 'Reconciliation', 'icon' => 'bi-check2-square', 'path' => 'reconciliation/index.php', 'any' => ['reconciliation.manage'], 'ready' => false],
            ],
            'Oversight' => [
                ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bi-bar-chart', 'path' => 'reports/index.php', 'any' => ['reports.view'], 'ready' => false],
                ['key' => 'audit', 'label' => 'Audit Trail', 'icon' => 'bi-clipboard-check', 'path' => 'reports/audit_trail.php', 'any' => ['audit.view'], 'ready' => false],
            ],
        ];
    }

    $visible = [];
    foreach ($sections as $title => $items) {
        $items = array_values(array_filter($items, static fn(array $item): bool => can_any($item['any'])));
        if ($items) {
            $visible[$title] = $items;
        }
    }
    return $visible;
}
