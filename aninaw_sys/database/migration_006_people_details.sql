-- ===========================================================================
-- ANINAW migration 006: people details and academic programs
-- ===========================================================================
-- Run ONCE after migration 005; a fresh install of schema.sql already contains
-- it (Part 7). Safe to run again: the table and columns are created only if
-- missing and programs are matched by code. No existing data changes.
--
-- * programs: each institution's academic programs (BSIT, BSCS, ...), editable.
-- * participants: address, birthday, program, "no middle name" flag, and the
--   parent / guardian with relationship and contact number.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `programs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `program_code` varchar(30) NOT NULL,
  `program_name` varchar(180) NOT NULL,
  `department_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_program_code` (`institution_id`, `program_code`),
  UNIQUE KEY `uq_program_tenant` (`institution_id`, `id`),
  CONSTRAINT `programs_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  CONSTRAINT `programs_ibfk_2` FOREIGN KEY (`institution_id`, `department_id`) REFERENCES `departments` (`institution_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO `programs` (`institution_id`, `program_code`, `program_name`)
SELECT i.id, p.code, p.name FROM `institutions` i
JOIN (
    SELECT 'BSIT' AS code, 'Bachelor of Science in Information Technology' AS name UNION ALL
    SELECT 'BSCS', 'Bachelor of Science in Computer Science' UNION ALL
    SELECT 'BSIS', 'Bachelor of Science in Information Systems' UNION ALL
    SELECT 'ACT', 'Associate in Computer Technology' UNION ALL
    SELECT 'BEEd', 'Bachelor of Elementary Education' UNION ALL
    SELECT 'BECEd', 'Bachelor of Early Childhood Education' UNION ALL
    SELECT 'BECAEd', 'Bachelor of Culture and Arts Education'
) AS p;

ALTER TABLE `participants`
  ADD COLUMN IF NOT EXISTS `no_middle_name` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = confirmed the person has no middle name' AFTER `middle_name`,
  ADD COLUMN IF NOT EXISTS `birth_date` date DEFAULT NULL AFTER `phone`,
  ADD COLUMN IF NOT EXISTS `address` varchar(500) DEFAULT NULL AFTER `birth_date`,
  ADD COLUMN IF NOT EXISTS `program_id` bigint(20) UNSIGNED DEFAULT NULL AFTER `address`,
  ADD COLUMN IF NOT EXISTS `guardian_name` varchar(180) DEFAULT NULL AFTER `program_id`,
  ADD COLUMN IF NOT EXISTS `guardian_relationship` varchar(60) DEFAULT NULL AFTER `guardian_name`,
  ADD COLUMN IF NOT EXISTS `guardian_phone` varchar(50) DEFAULT NULL AFTER `guardian_relationship`;

ALTER TABLE `participants`
  ADD KEY IF NOT EXISTS `idx_participant_program` (`institution_id`, `program_id`);

ALTER TABLE `participants`
  ADD CONSTRAINT `participants_program_fk` FOREIGN KEY IF NOT EXISTS (`institution_id`, `program_id`) REFERENCES `programs` (`institution_id`, `id`);

COMMIT;
