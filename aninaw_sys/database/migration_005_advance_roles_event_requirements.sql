-- ===========================================================================
-- ANINAW migration 005: who may request cash advances; event document requirements
-- ===========================================================================
-- Run ONCE on a database that has migration 004; a fresh install of schema.sql
-- already contains it (Part 6). Safe to run again: tables are created only if
-- missing and seed rows are matched by their codes. No existing data changes.
--
-- 1. Cash advances: new roles Faculty, Dean and Program Chair may request
--    advances and submit liquidations; so may Class Presidents (collector role)
--    and Advisers. Approval and release stay with their existing roles, and
--    nobody approves or releases their own advance.
-- 2. Event requirements: each event lists the papers its people must submit
--    (parent's waiver, medical certificate, parent's ID with signature...).
--    One row per person and requirement records its status, who recorded and
--    who checked it, and an optional scanned copy (file_attachments, entity
--    'event_document', viewable only by the event's managers).

START TRANSACTION;

INSERT INTO `roles` (`role_code`, `role_name`, `scope_type`, `description`) VALUES
('faculty',       'Faculty',       'institution', 'Faculty member: may request cash advances and submit liquidations.'),
('dean',          'Dean',          'institution', 'Dean: may request cash advances and submit liquidations.'),
('program_chair', 'Program Chair', 'institution', 'Program chair: may request cash advances and submit liquidations.')
ON DUPLICATE KEY UPDATE `role_name` = VALUES(`role_name`), `description` = VALUES(`description`);

INSERT INTO `permissions` (`permission_code`, `permission_name`, `description`) VALUES
('events.requirements', 'Check event documents', 'Record and check the documents people submit for any event (waivers, medical certificates, IDs).')
ON DUPLICATE KEY UPDATE `permission_name` = VALUES(`permission_name`), `description` = VALUES(`description`);

INSERT IGNORE INTO `role_permissions` (`role_id`, `permission_id`)
SELECT r.id, p.id
FROM (
    SELECT 'faculty' AS role_code, 'dashboard.view' AS permission_code UNION ALL
    SELECT 'faculty', 'advances.request' UNION ALL
    SELECT 'faculty', 'liquidation.submit' UNION ALL
    SELECT 'dean', 'dashboard.view' UNION ALL
    SELECT 'dean', 'advances.request' UNION ALL
    SELECT 'dean', 'liquidation.submit' UNION ALL
    SELECT 'program_chair', 'dashboard.view' UNION ALL
    SELECT 'program_chair', 'advances.request' UNION ALL
    SELECT 'program_chair', 'liquidation.submit' UNION ALL
    SELECT 'collector', 'advances.request' UNION ALL
    SELECT 'collector', 'liquidation.submit' UNION ALL
    SELECT 'adviser', 'advances.request' UNION ALL
    SELECT 'adviser', 'liquidation.submit' UNION ALL
    SELECT 'event_organizer', 'liquidation.submit' UNION ALL
    SELECT 'faculty', 'self.advances.view' UNION ALL
    SELECT 'dean', 'self.advances.view' UNION ALL
    SELECT 'program_chair', 'self.advances.view' UNION ALL
    SELECT 'collector', 'self.advances.view' UNION ALL
    SELECT 'adviser', 'self.advances.view' UNION ALL
    SELECT 'event_organizer', 'self.advances.view' UNION ALL
    SELECT 'institution_admin', 'events.requirements'
) AS map
JOIN `roles` r ON r.role_code = map.role_code
JOIN `permissions` p ON p.permission_code = map.permission_code;

CREATE TABLE IF NOT EXISTS `event_requirements` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `requirement_name` varchar(180) NOT NULL,
  `description` varchar(1000) DEFAULT NULL,
  `applies_to_type` enum('all','student','employee','member','other') NOT NULL DEFAULT 'all' COMMENT 'Which kind of person must submit it',
  `is_required` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = optional, not counted for clearance',
  `due_date` date DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_requirement_tenant` (`institution_id`, `id`),
  KEY `idx_event_requirements` (`institution_id`, `event_id`, `status`),
  CONSTRAINT `event_requirements_ibfk_1` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `event_requirements_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `event_requirement_submissions` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `requirement_id` bigint(20) UNSIGNED NOT NULL,
  `participant_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('submitted','approved','rejected','waived') NOT NULL DEFAULT 'submitted' COMMENT 'No row = not yet submitted',
  `remarks` varchar(500) DEFAULT NULL,
  `received_at` datetime NOT NULL,
  `recorded_by` bigint(20) UNSIGNED NOT NULL,
  `reviewed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_requirement_person` (`requirement_id`, `participant_id`),
  UNIQUE KEY `uq_requirement_submission_tenant` (`institution_id`, `id`),
  KEY `idx_submissions_event` (`institution_id`, `event_id`, `participant_id`),
  CONSTRAINT `event_requirement_submissions_ibfk_1` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `event_requirement_submissions_ibfk_2` FOREIGN KEY (`institution_id`, `requirement_id`) REFERENCES `event_requirements` (`institution_id`, `id`),
  CONSTRAINT `event_requirement_submissions_ibfk_3` FOREIGN KEY (`institution_id`, `participant_id`) REFERENCES `participants` (`institution_id`, `id`),
  CONSTRAINT `event_requirement_submissions_ibfk_4` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `event_requirement_submissions_ibfk_5` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
