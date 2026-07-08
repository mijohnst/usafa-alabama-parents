-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `members`
  ADD COLUMN `is_board_member` TINYINT(1) NOT NULL DEFAULT 0 AFTER `directory_consent`;
