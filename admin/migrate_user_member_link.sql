-- Links a portal login to the cadet family record it belongs to, so a
-- member can see their own dues status. Run once in phpMyAdmin.

ALTER TABLE users
  ADD COLUMN member_id INT NULL;
