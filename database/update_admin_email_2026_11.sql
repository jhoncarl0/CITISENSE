-- ---------------------------------------------------------------------------
-- CitiSense — change the administrator's email address (Nov 2026)
--
--   old: 07dablo.christille@gmail.com
--   new: brgyduljo.fatima@gmail.com
--
-- The admin website has no hard-coded admin email: the address is the login
-- email stored in `users`. Run this once in phpMyAdmin (SQL tab) on the
-- `citisense` database. It only changes the ADMIN account that still uses the
-- old address, and does nothing if the new address is already taken by
-- another account (an email must stay unique for logging in). Safe to run
-- more than once.
--
-- ALSO CHECK (outside this repository): the mobile API service sends the
-- "forgot password" codes. If its mail settings (environment variables such as
-- the sender / "from" address or SMTP user on Railway) still use
-- 07dablo.christille@gmail.com, change them there to brgyduljo.fatima@gmail.com.
-- ---------------------------------------------------------------------------

-- MySQL Workbench "safe update mode" (Error 1175) only allows an UPDATE whose
-- WHERE uses a key column. These lines switch it off for this one statement,
-- in this connection only, and switch it back on right after.
SET @cs_safe_updates = @@SQL_SAFE_UPDATES;
SET SQL_SAFE_UPDATES = 0;

UPDATE users
SET email = 'brgyduljo.fatima@gmail.com'
WHERE email = '07dablo.christille@gmail.com'
  AND role = 'admin'
  AND NOT EXISTS (
      SELECT 1 FROM (
          SELECT userID FROM users WHERE email = 'brgyduljo.fatima@gmail.com'
      ) AS taken
  );

SET SQL_SAFE_UPDATES = @cs_safe_updates;

-- Check the result: the admin account should now show the new address.
SELECT userID, firstName, lastName, email, role
FROM users
WHERE email IN ('07dablo.christille@gmail.com', 'brgyduljo.fatima@gmail.com');
