-- Adds pending-invite tracking to the users table so paid members can be
-- bulk-invited to create their own portal login. Run once in phpMyAdmin.

ALTER TABLE users
  ADD COLUMN invite_token VARCHAR(48) NULL,
  ADD COLUMN invite_expires DATETIME NULL;
