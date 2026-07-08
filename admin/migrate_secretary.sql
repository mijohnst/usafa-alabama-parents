-- Secretary tools: meetings, attendance, correspondence log
-- Run once in phpMyAdmin

CREATE TABLE IF NOT EXISTS club_meetings (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    meeting_date DATE         NOT NULL,
    meeting_type ENUM('general','board','special','other') NOT NULL DEFAULT 'general',
    title        VARCHAR(200) NOT NULL DEFAULT '',
    location     VARCHAR(200) DEFAULT '',
    notes        TEXT         DEFAULT '',
    minutes_file VARCHAR(255) DEFAULT '',
    created_by   INT          DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cm_date (meeting_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meeting_attendance (
    meeting_id   INT UNSIGNED NOT NULL,
    member_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (meeting_id, member_id),
    INDEX idx_ma_member (member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS correspondence_log (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    log_date     DATE         NOT NULL,
    direction    ENUM('sent','received') NOT NULL DEFAULT 'sent',
    contact_name VARCHAR(200) NOT NULL DEFAULT '',
    contact_org  VARCHAR(200) DEFAULT '',
    subject      VARCHAR(500) NOT NULL DEFAULT '',
    method       ENUM('email','letter','phone','in-person','other') NOT NULL DEFAULT 'email',
    notes        TEXT         DEFAULT '',
    logged_by    INT          DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cl_date (log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
