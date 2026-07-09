-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
-- Corrects the description text for the meeting_reminder automated email —
-- no schema change, just documentation. The actual behavior fix (Special/
-- Other meetings no longer email anyone) is in code, not the database.
UPDATE `automated_emails`
SET `description` = 'Sent the morning of a club meeting. Board meetings go to board-flagged parents only, General meetings go to all active members. Special and Other meetings never send a reminder.'
WHERE `email_key` = 'meeting_reminder';
