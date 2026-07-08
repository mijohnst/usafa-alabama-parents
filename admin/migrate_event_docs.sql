-- Event Documents (per album) — PPT, PDF, XLS, DOC, etc.
-- Run once: mysql -u USER -p DBNAME < migrate_event_docs.sql

CREATE TABLE IF NOT EXISTS event_documents (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    album_id      INT UNSIGNED NOT NULL,
    filename      VARCHAR(120) NOT NULL,
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    label         VARCHAR(200) DEFAULT '',
    sort_order    INT          NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ed_album FOREIGN KEY (album_id) REFERENCES event_albums(id) ON DELETE CASCADE,
    INDEX idx_ed_album (album_id),
    INDEX idx_ed_sort  (album_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
