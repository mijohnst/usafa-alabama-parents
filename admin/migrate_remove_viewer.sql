-- Run in phpMyAdmin → alabkmgg_members → SQL tab
-- Convert any existing viewer accounts to member first
UPDATE `users` SET `role` = 'member' WHERE `role` = 'viewer';

-- Remove 'viewer' from the role ENUM
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','tech','officer','secretary','treasurer','member') NOT NULL DEFAULT 'member';
