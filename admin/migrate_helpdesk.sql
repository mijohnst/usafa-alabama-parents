-- Run in phpMyAdmin → alabkmgg_members → SQL tab

-- Add 'tech' role to users table
ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','secretary','treasurer','viewer','member','tech') NOT NULL DEFAULT 'viewer';

-- Tickets table
CREATE TABLE IF NOT EXISTS `tickets` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_number` VARCHAR(10) NOT NULL UNIQUE,
  `category`      VARCHAR(100) NOT NULL,
  `subject`       VARCHAR(300) NOT NULL,
  `description`   TEXT NOT NULL,
  `status`        ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
  `priority`      ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `submitted_by`  INT NULL,
  `assigned_to`   INT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`)  REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ticket comments / activity log
CREATE TABLE IF NOT EXISTS `ticket_comments` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id`   INT NOT NULL,
  `user_id`     INT NULL,
  `comment`     TEXT NOT NULL,
  `is_internal` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`)   REFERENCES `users`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
