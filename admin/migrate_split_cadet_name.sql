-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
-- Splits the combined "First / Middle Name" field into two separate columns
-- to match the First Name / Middle Name boxes already on the Application
-- and Update forms. Existing data is preserved as-is in the renamed
-- cadet_first_name column (old combined values stay there); the new
-- cadet_middle_name column starts empty for existing records — nothing is
-- auto-split, since guessing where a first name ends and a middle name
-- begins in old data would risk getting it wrong. New/updated records will
-- store the two separately going forward.
ALTER TABLE `members`
  CHANGE COLUMN `cadet_first_middle` `cadet_first_name` VARCHAR(150),
  ADD COLUMN `cadet_middle_name` VARCHAR(100) DEFAULT '' AFTER `cadet_first_name`;
