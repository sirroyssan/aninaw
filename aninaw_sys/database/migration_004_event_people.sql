-- ===========================================================================
-- ANINAW migration 004: people added directly to an event
-- ===========================================================================
-- Lets an event include individual people (students, faculty/staff, customers)
-- without creating a group. Run ONCE on a database that has migration 003; a
-- fresh install of schema.sql already contains it (Part 5). Safe to run again:
-- the table is created only if missing. No existing data is changed.
--
-- A collection rule that applies to "everyone in the event" charges the
-- members of the event's groups AND the people added here. Removing a person
-- marks the row inactive (history is kept) and cancels unpaid obligations.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `event_participants` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `participant_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('active','removed') NOT NULL DEFAULT 'active',
  `added_by` bigint(20) UNSIGNED NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `removed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_participant` (`event_id`, `participant_id`),
  KEY `idx_event_participants` (`institution_id`, `event_id`, `status`),
  KEY `idx_participant_events` (`institution_id`, `participant_id`),
  CONSTRAINT `event_participants_ibfk_1` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `event_participants_ibfk_2` FOREIGN KEY (`institution_id`, `participant_id`) REFERENCES `participants` (`institution_id`, `id`),
  CONSTRAINT `event_participants_ibfk_3` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
