-- Run in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `purchases`
  ADD COLUMN `order_number`    VARCHAR(100)   NOT NULL DEFAULT '' AFTER `vendor`,
  ADD COLUMN `amount_shipping` DECIMAL(10,2)  NOT NULL DEFAULT 0.00 AFTER `amount_tax`;
