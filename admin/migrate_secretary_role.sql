-- Run in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','treasurer','viewer','member','secretary') NOT NULL DEFAULT 'viewer';
