-- Event Albums & Photos
-- Run once on the server: mysql -u USER -p DBNAME < migrate_event_albums.sql

CREATE TABLE IF NOT EXISTS event_albums (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(120) NOT NULL,
    event_date   DATE         DEFAULT NULL,
    description  VARCHAR(500) DEFAULT '',
    cover_photo_id INT UNSIGNED DEFAULT NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    visible      TINYINT(1)   NOT NULL DEFAULT 1,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_photos (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    album_id   INT UNSIGNED NOT NULL,
    filename   VARCHAR(120) NOT NULL,
    caption    VARCHAR(300) DEFAULT '',
    sort_order INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ep_album FOREIGN KEY (album_id) REFERENCES event_albums(id) ON DELETE CASCADE,
    INDEX idx_ep_album (album_id),
    INDEX idx_ep_sort  (album_id, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
