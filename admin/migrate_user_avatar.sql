-- Adds a self-uploaded profile picture to portal user accounts. Run once
-- in phpMyAdmin.

ALTER TABLE users
  ADD COLUMN avatar_filename VARCHAR(255) NULL;
