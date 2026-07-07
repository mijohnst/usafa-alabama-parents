-- Run in phpMyAdmin → alabkmgg_members → SQL tab

-- Volunteer submissions
CREATE TABLE IF NOT EXISTS `volunteers` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(200) NOT NULL,
  `email`        VARCHAR(200) NOT NULL,
  `phone`        VARCHAR(30),
  `areas`        TEXT,
  `availability` VARCHAR(200),
  `cadet_info`   VARCHAR(200),
  `comments`     TEXT,
  `created_at`   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document vault
CREATE TABLE IF NOT EXISTS `vault_documents` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `title`       VARCHAR(300) NOT NULL,
  `category`    VARCHAR(100) NOT NULL DEFAULT 'Other',
  `description` TEXT,
  `filename`    VARCHAR(255) NOT NULL,
  `file_size`   INT NOT NULL DEFAULT 0,
  `mime_type`   VARCHAR(100) NOT NULL DEFAULT '',
  `uploaded_by` INT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
