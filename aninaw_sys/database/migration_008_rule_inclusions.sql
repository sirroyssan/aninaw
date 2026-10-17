-- ===========================================================================
-- ANINAW migration 008: what a collection includes (e.g. Registration Fee:
-- T-shirt, Lanyard, AM/PM snacks, Lunch), shown on payment forms and receipts.
-- Safe to run more than once.
-- ===========================================================================
START TRANSACTION;

ALTER TABLE `collection_rules`
  ADD COLUMN IF NOT EXISTS `inclusions` text DEFAULT NULL AFTER `rule_name`;

COMMIT;
