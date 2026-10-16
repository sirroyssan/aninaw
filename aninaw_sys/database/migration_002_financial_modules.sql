-- ===========================================================================
-- ANINAW migration 002: financial management modules (approved additions)
-- ===========================================================================
-- Run this ONCE on a database that already has database/schema.sql from
-- milestone 1. (A fresh install gets the same statements at the end of schema.sql.)
--
-- 1. financial_accounts.custodian_membership_id
--    The schema cannot record who physically holds a cash box or controls an
--    account. Custody accountability, turnovers (custody moves from collector to
--    cashier), deposits and the cash custody report all need it.
--
-- 2. file_attachments.entity_type + entity_id
--    Only expenses and advance expenses could reference a file. Deposit slips,
--    transfer documents, turnover and payment evidence, and server-side download
--    authorisation need to know which record a file belongs to.
--
-- 3. event_organizers
--    Events only have created_by (the creator, kept for the audit trail). The
--    spec requires assigning one or more organizers to an event.
--
-- 4. New permission codes and role mappings (data only; matched by code).
--
-- All changes are additive. No existing column or row is modified.

START TRANSACTION;

ALTER TABLE `financial_accounts`
  ADD COLUMN `custodian_membership_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `account_reference`,
  ADD KEY `idx_financial_account_custodian` (`institution_id`, `custodian_membership_id`),
  ADD CONSTRAINT `financial_accounts_ibfk_2` FOREIGN KEY (`institution_id`, `custodian_membership_id`)
      REFERENCES `institution_memberships` (`institution_id`, `id`);

ALTER TABLE `file_attachments`
  ADD COLUMN `entity_type` varchar(60) DEFAULT NULL AFTER `uploaded_by_membership_id`,
  ADD COLUMN `entity_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `entity_type`,
  ADD KEY `idx_attachment_entity` (`institution_id`, `entity_type`, `entity_id`),
  ADD KEY `idx_attachment_checksum` (`institution_id`, `checksum`);

CREATE TABLE IF NOT EXISTS `event_organizers` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `membership_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_by` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_organizer` (`event_id`, `membership_id`),
  KEY `idx_event_organizer_member` (`institution_id`, `membership_id`),
  CONSTRAINT `event_organizers_ibfk_1` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `event_organizers_ibfk_2` FOREIGN KEY (`institution_id`, `membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  CONSTRAINT `event_organizers_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `permissions` (`permission_code`, `permission_name`, `description`) VALUES
('organization.manage', 'Manage organization',    'Manage departments, groups, participants and officers.'),
('participants.view',   'View participants',      'Search and view participant records.'),
('payments.reverse',    'Reverse payments',       'Void receipts and reverse posted payments with a reason.'),
('transfers.approve',   'Approve transfers',      'Approve or reject fund transfer requests.'),
('ledger.post',         'Post journal entries',   'Post balanced adjusting entries and reversals.'),
('budgets.view',        'View budgets',           'View budgets and budget-versus-actual reports.'),
('budgets.manage',      'Manage budgets',         'Create budgets, lines and revisions.'),
('budgets.approve',     'Approve budgets',        'Approve, activate and close budgets.')
ON DUPLICATE KEY UPDATE `permission_name` = VALUES(`permission_name`), `description` = VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM (
    SELECT 'institution_admin' AS role_code, 'organization.manage' AS permission_code UNION ALL
    SELECT 'institution_admin', 'participants.view' UNION ALL
    SELECT 'institution_admin', 'expenses.view' UNION ALL
    SELECT 'institution_admin', 'expenses.approve' UNION ALL
    SELECT 'institution_admin', 'advances.view' UNION ALL
    SELECT 'institution_admin', 'advances.approve' UNION ALL
    SELECT 'institution_admin', 'transfers.approve' UNION ALL
    SELECT 'institution_admin', 'budgets.view' UNION ALL
    SELECT 'institution_admin', 'budgets.approve' UNION ALL

    SELECT 'treasurer', 'participants.view' UNION ALL
    SELECT 'treasurer', 'collections.record' UNION ALL
    SELECT 'treasurer', 'receipts.issue' UNION ALL
    SELECT 'treasurer', 'turnovers.receive' UNION ALL
    SELECT 'treasurer', 'payments.reverse' UNION ALL
    SELECT 'treasurer', 'transfers.approve' UNION ALL
    SELECT 'treasurer', 'ledger.post' UNION ALL
    SELECT 'treasurer', 'budgets.view' UNION ALL
    SELECT 'treasurer', 'budgets.manage' UNION ALL

    SELECT 'event_organizer', 'participants.view' UNION ALL
    SELECT 'event_organizer', 'budgets.view' UNION ALL
    SELECT 'event_organizer', 'budgets.manage' UNION ALL

    SELECT 'cashier', 'participants.view' UNION ALL
    SELECT 'collector', 'participants.view' UNION ALL
    SELECT 'adviser', 'participants.view' UNION ALL

    SELECT 'auditor', 'participants.view' UNION ALL
    SELECT 'auditor', 'budgets.view'
) AS map
JOIN `roles` r ON r.role_code = map.role_code
JOIN `permissions` p ON p.permission_code = map.permission_code;

COMMIT;
