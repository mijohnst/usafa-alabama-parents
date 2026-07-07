-- Run in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','tech','officer','secretary','treasurer','viewer','member') NOT NULL DEFAULT 'viewer';
