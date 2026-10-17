-- ===========================================================================
-- ANINAW migration 009: accounts get a default password (Lastname + year +
-- special character) that must be changed at first sign-in; e-mail becomes
-- optional so students without one can have a login (username = ID number).
-- Safe to run more than once.
-- ===========================================================================
START TRANSACTION;

ALTER TABLE `users`
  MODIFY `email` varchar(190) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `must_change_password` tinyint(1) NOT NULL DEFAULT 0 AFTER `password_hash`;

COMMIT;
