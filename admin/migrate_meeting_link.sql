-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `club_meetings`
  ADD COLUMN `meeting_link` VARCHAR(500) NOT NULL DEFAULT '' AFTER `location`;
