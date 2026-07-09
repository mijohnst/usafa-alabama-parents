-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
CREATE TABLE IF NOT EXISTS `form_throttle` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `form_name`  VARCHAR(50) NOT NULL,
  `ip_hash`    CHAR(64) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_ft_lookup` (`form_name`, `ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
