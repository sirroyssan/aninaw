-- ===========================================================================
-- ANINAW migration 011: correcting a collection amount after payments, and
-- settling overpayments. A lowered rule amount is recorded in
-- rule_corrections; money paid above the new amount becomes an overpayment
-- (Overpayments / Refunds Payable, 2100) that is refunded in cash or applied
-- to the payer's other charges. Each settlement is a numbered
-- payment_adjustment whose parts say which payments the money came from.
-- Safe to run more than once.
-- ===========================================================================
START TRANSACTION;

CREATE TABLE IF NOT EXISTS `rule_corrections` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `collection_rule_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `old_amount` decimal(12,2) NOT NULL,
  `new_amount` decimal(12,2) NOT NULL,
  `charges_updated` int(11) NOT NULL DEFAULT 0,
  `overpaid_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason` text NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rule_correction_tenant` (`institution_id`, `id`),
  KEY `idx_rule_corrections_rule` (`institution_id`, `collection_rule_id`),
  CONSTRAINT `rule_corrections_ibfk_1` FOREIGN KEY (`collection_rule_id`) REFERENCES `collection_rules` (`id`),
  CONSTRAINT `rule_corrections_ibfk_2` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `rule_corrections_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_adjustments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `adjustment_number` varchar(80) NOT NULL,
  `adjustment_type` enum('refund','apply') NOT NULL,
  `participant_id` bigint(20) UNSIGNED NOT NULL,
  `event_id` bigint(20) UNSIGNED NOT NULL,
  `fund_id` bigint(20) UNSIGNED NOT NULL,
  `from_obligation_id` bigint(20) UNSIGNED NOT NULL,
  `to_obligation_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'apply: the charge that received the money',
  `financial_account_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'refund: where the cash came from',
  `amount` decimal(12,2) NOT NULL,
  `adjustment_date` date NOT NULL,
  `reason` text NOT NULL,
  `created_by` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_adjustment_number` (`institution_id`, `adjustment_number`),
  UNIQUE KEY `uq_adjustment_tenant` (`institution_id`, `id`),
  KEY `idx_adjustments_event` (`institution_id`, `event_id`),
  CONSTRAINT `payment_adjustments_ibfk_1` FOREIGN KEY (`institution_id`, `participant_id`) REFERENCES `participants` (`institution_id`, `id`),
  CONSTRAINT `payment_adjustments_ibfk_2` FOREIGN KEY (`institution_id`, `event_id`) REFERENCES `events` (`institution_id`, `id`),
  CONSTRAINT `payment_adjustments_ibfk_3` FOREIGN KEY (`institution_id`, `fund_id`) REFERENCES `fund_accounts` (`institution_id`, `id`),
  CONSTRAINT `payment_adjustments_ibfk_4` FOREIGN KEY (`institution_id`, `from_obligation_id`) REFERENCES `event_obligations` (`institution_id`, `id`),
  CONSTRAINT `payment_adjustments_ibfk_5` FOREIGN KEY (`institution_id`, `to_obligation_id`) REFERENCES `event_obligations` (`institution_id`, `id`),
  CONSTRAINT `payment_adjustments_ibfk_6` FOREIGN KEY (`institution_id`, `financial_account_id`) REFERENCES `financial_accounts` (`institution_id`, `id`),
  CONSTRAINT `payment_adjustments_ibfk_7` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `payment_adjustment_parts` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` bigint(20) UNSIGNED NOT NULL,
  `adjustment_id` bigint(20) UNSIGNED NOT NULL,
  `payment_id` bigint(20) UNSIGNED NOT NULL COMMENT 'the payment whose allocation was moved or refunded',
  `amount` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_adjustment_parts_payment` (`institution_id`, `payment_id`),
  CONSTRAINT `payment_adjustment_parts_ibfk_1` FOREIGN KEY (`institution_id`, `adjustment_id`) REFERENCES `payment_adjustments` (`institution_id`, `id`),
  CONSTRAINT `payment_adjustment_parts_ibfk_2` FOREIGN KEY (`institution_id`, `payment_id`) REFERENCES `payments` (`institution_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
