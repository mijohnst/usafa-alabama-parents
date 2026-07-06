-- Run in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `event_budgets`
  ADD COLUMN `last_notified_pct` INT NOT NULL DEFAULT 0 AFTER `notes`;
