-- ===========================================================================
-- ANINAW migration 010: solicitations and donations. A donation (or pledge)
-- from a donor goes to a fund, optionally for an event. Money received is an
-- ordinary payment + receipt (so turnovers, reversals and receipt checks work),
-- linked through donation_payments and booked as Donations & Solicitations
-- income (4300). Safe to run more than once.
-- ===========================================================================
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `donations` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `donation_number` varchar(80) NOT NULL,
  `donor_participant_id` bigint(20) UNSIGNED NOT NULL COMMENT 'Donor kept in People (type Customer / Other) so receipts name the payer',
  `donor_kind` enum('individual','company','alumni','parent','organization','other') NOT NULL DEFAULT 'individual',
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED DEFAULT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `pledged_amount` decimal(12,2) DEFAULT NULL COMMENT 'NULL when received outright without a pledge',
  `pledge_date` date DEFAULT NULL,
  `expected_date` date DEFAULT NULL,
  `solicited_by_membership_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` enum('pledged','partially_received','received','cancelled') NOT NULL DEFAULT 'pledged',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_donation_number` (`institution_id`, `donation_number`),
  UNIQUE KEY `uq_donation_tenant` (`institution_id`, `id`),
  KEY `idx_donation_event` (`institution_id`, `event_id`),
  KEY `idx_donation_fund` (`institution_id`, `fund_id`),
  CONSTRAINT `donations_ibfk_1` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`),
  CONSTRAINT `donations_ibfk_2` FOREIGN KEY (`institution_id`, `donor_participant_id`) REFERENCES `participants` (`institution_id`, `id`),
  CONSTRAINT `donations_ibfk_3` FOREIGN KEY (`institution_id`, `fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  CONSTRAINT `donations_ibfk_4` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `donations_ibfk_5` FOREIGN KEY (`institution_id`, `solicited_by_membership_id`) REFERENCES `institution_memberships` (`institution_id`, `id`),
  CONSTRAINT `donations_ibfk_6` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `donation_payments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `donation_id` bigint(20) UNSIGNED NOT NULL,
  `payment_id` bigint(20) UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_donation_payment` (`institution_id`, `payment_id`),
  KEY `idx_donation_payments` (`institution_id`, `donation_id`),
  CONSTRAINT `donation_payments_ibfk_1` FOREIGN KEY (`institution_id`, `donation_id`) REFERENCES `donations` (`institution_id`, `id`),
  CONSTRAINT `donation_payments_ibfk_2` FOREIGN KEY (`institution_id`, `payment_id`) REFERENCES `payments` (`institution_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
