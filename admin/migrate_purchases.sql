-- Run in phpMyAdmin → alabkmgg_members → SQL tab
CREATE TABLE IF NOT EXISTS `purchases` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `vendor`         VARCHAR(200) NOT NULL,
  `description`    VARCHAR(500) NOT NULL,
  `event`          VARCHAR(200) NOT NULL DEFAULT '',
  `category`       VARCHAR(100) NOT NULL DEFAULT '',
  `purchase_date`  DATE NOT NULL,
  `amount_pretax`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_tax`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `amount_total`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `receipt_filename` VARCHAR(255) NULL,
  `submitted_by`   INT NULL,
  `status`         ENUM('pending','approved','reimbursed') NOT NULL DEFAULT 'pending',
  `notes`          TEXT,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
