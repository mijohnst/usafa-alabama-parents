-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
-- Splits the single is_board_member flag into per-parent flags, and lets
-- meeting_attendance track parent1 and parent2 as separate attendees.

ALTER TABLE `members`
  CHANGE COLUMN `is_board_member` `parent1_is_board_member` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `parent2_is_board_member` TINYINT(1) NOT NULL DEFAULT 0 AFTER `parent1_is_board_member`;

ALTER TABLE `meeting_attendance`
  DROP PRIMARY KEY,
  ADD COLUMN `parent_slot` TINYINT(1) NOT NULL DEFAULT 1 AFTER `member_id`,
  ADD PRIMARY KEY (`meeting_id`, `member_id`, `parent_slot`);
