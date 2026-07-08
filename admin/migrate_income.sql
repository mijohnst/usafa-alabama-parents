-- Income ledger for club treasurer
-- Run once: mysql -u USER -p DBNAME < migrate_income.sql

CREATE TABLE IF NOT EXISTS income_entries (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entry_date     DATE         NOT NULL,
    source         VARCHAR(200) NOT NULL DEFAULT '',
    source_type    ENUM('dues','sponsorship','event_fee','donation','other') NOT NULL DEFAULT 'other',
    description    VARCHAR(500) DEFAULT '',
    amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(60)   DEFAULT '',
    notes          TEXT          DEFAULT '',
    received_by    INT           DEFAULT NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ie_date (entry_date),
    INDEX idx_ie_type (source_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
