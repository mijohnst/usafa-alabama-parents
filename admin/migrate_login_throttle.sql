-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `users`
  ADD COLUMN `failed_attempts` INT NOT NULL DEFAULT 0 AFTER `active`,
  ADD COLUMN `locked_until` DATETIME NULL DEFAULT NULL AFTER `failed_attempts`;
