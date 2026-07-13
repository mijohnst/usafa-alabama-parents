-- Renames photo_submissions.STATUS to lowercase status, matching every
-- other column in the table (and the original migration that created it).
-- Purely cosmetic/consistency — the application code already works
-- correctly around the case mismatch, this just cleans up the schema.
-- Run once in phpMyAdmin.

ALTER TABLE photo_submissions
  CHANGE STATUS status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending';
