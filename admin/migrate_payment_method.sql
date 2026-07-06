-- Run in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `purchases`
  ADD COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT '' AFTER `notes`;
