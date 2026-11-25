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

UPDATE users
SET firstName = 'Duljo-Fatima',
    lastName  = 'Admin'
WHERE role = 'admin'
  AND firstName = 'Christille'
  AND lastName  = 'Dablo';

-- Check the result: the admin account should now show the new name.
SELECT userID, firstName, lastName, email, role
FROM users
WHERE role = 'admin';
