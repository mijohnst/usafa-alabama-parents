-- Run in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `purchases`
  ADD COLUMN `receipt_required` TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`,
  ADD COLUMN `approved_note`    VARCHAR(500) NOT NULL DEFAULT '' AFTER `receipt_required`,
  ADD COLUMN `reimbursed_note`  VARCHAR(500) NOT NULL DEFAULT '' AFTER `approved_note`;

CREATE TABLE IF NOT EXISTS `event_budgets` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `event`       VARCHAR(200) NOT NULL UNIQUE,
  `budget`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `fiscal_year` VARCHAR(9)   NOT NULL DEFAULT '',
  `notes`       TEXT,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
