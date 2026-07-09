-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
-- Links a vault_documents row back to the club_meetings row it was
-- auto-mirrored from, so upload/replace/delete in Meeting Minutes can
-- keep the Document Vault copy in sync. NULL for documents uploaded
-- directly in the Vault (unrelated to minutes).
ALTER TABLE `vault_documents`
  ADD COLUMN `source_meeting_id` INT UNSIGNED NULL DEFAULT NULL AFTER `uploaded_by`,
  ADD INDEX `idx_vd_source_meeting` (`source_meeting_id`);
