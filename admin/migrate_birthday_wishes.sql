-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
-- Tracks which cadets already got a birthday email this year, so the
-- daily cron job never double-sends (e.g. if it's triggered twice in a day).
CREATE TABLE IF NOT EXISTS `birthday_email_log` (
  `member_id` INT UNSIGNED NOT NULL,
  `year_sent` SMALLINT UNSIGNED NOT NULL,
  `sent_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`member_id`, `year_sent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
