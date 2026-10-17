-- ===========================================================================
-- ANINAW migration 007: people's names in capital letters
-- ===========================================================================
-- From now on every first, middle and last name is saved in capitals (forms,
-- CSV and PDF imports, walk-in customers). This one-time update converts the
-- names already saved, so the People list is consistent. Only the case of the
-- letters changes (Ñ is kept). Safe to run again. A fresh install of schema.sql
-- has nothing to convert.

START TRANSACTION;

UPDATE `participants`
SET `first_name` = UPPER(`first_name`),
    `middle_name` = UPPER(`middle_name`),
    `last_name` = UPPER(`last_name`)
WHERE BINARY `first_name` <> BINARY UPPER(`first_name`)
   OR BINARY `last_name` <> BINARY UPPER(`last_name`)
   OR (`middle_name` IS NOT NULL AND BINARY `middle_name` <> BINARY UPPER(`middle_name`));

COMMIT;
