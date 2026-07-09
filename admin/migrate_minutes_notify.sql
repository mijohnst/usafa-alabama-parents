-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
-- Token used to give board members (who may not have admin logins) a
-- direct link to the posted minutes file without requiring login.
ALTER TABLE `club_meetings`
  ADD COLUMN `minutes_token` VARCHAR(48) NULL DEFAULT NULL AFTER `minutes_file`;
