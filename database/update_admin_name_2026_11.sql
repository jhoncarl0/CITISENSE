-- ---------------------------------------------------------------------------
-- CitiSense — change the administrator's name (Nov 2026)
--
--   first name:  Christille    ->  Duljo-Fatima
--   last name:   Dablo         ->  Admin
--
-- The admin website has no hard-coded admin name: it is stored in `users`.
-- Run this once in phpMyAdmin (SQL tab) on the `citisense` database. It only
-- changes the ADMIN account named "Christille Dablo"; residents and other
-- accounts are not touched. Safe to run more than once.
--
-- The name is copied into the login session, so the sidebar and the
-- Dashboard greeting show the new name after the admin logs out and logs in
-- again.
-- ---------------------------------------------------------------------------

-- MySQL Workbench "safe update mode" (Error 1175) only allows an UPDATE whose
-- WHERE uses a key column. These lines switch it off for this one statement,
-- in this connection only, and switch it back on right after.
SET @cs_safe_updates = @@SQL_SAFE_UPDATES;
SET SQL_SAFE_UPDATES = 0;

UPDATE users
SET firstName = 'Duljo-Fatima',
    lastName  = 'Admin'
WHERE role = 'admin'
  AND firstName = 'Christille'
  AND lastName  = 'Dablo';

SET SQL_SAFE_UPDATES = @cs_safe_updates;

-- Check the result: the admin account should now show the new name.
SELECT userID, firstName, lastName, email, role
FROM users
WHERE role = 'admin';
