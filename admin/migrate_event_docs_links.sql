-- Add external link support to event_documents
-- Run once: mysql -u USER -p DBNAME < migrate_event_docs_links.sql

ALTER TABLE event_documents
  ADD COLUMN type ENUM('file','link') NOT NULL DEFAULT 'file' AFTER label,
  ADD COLUMN url  VARCHAR(2000)       DEFAULT NULL              AFTER type;
