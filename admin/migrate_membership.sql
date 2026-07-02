-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `members`
  ADD COLUMN `membership_paid` TINYINT(1) NOT NULL DEFAULT 0 AFTER `remarks`,
  ADD COLUMN `membership_year` VARCHAR(9)  NOT NULL DEFAULT '' AFTER `membership_paid`;
