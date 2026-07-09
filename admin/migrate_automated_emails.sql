-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
-- Generalizes the birthday-email system into a reusable "automated emails"
-- framework (enable/disable + templates + timing), managed from the new
-- admin/automated-emails.php page instead of Site Settings.

CREATE TABLE IF NOT EXISTS `automated_emails` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email_key`   VARCHAR(50) NOT NULL UNIQUE,
  `label`       VARCHAR(150) NOT NULL,
  `description` VARCHAR(300) NOT NULL DEFAULT '',
  `enabled`     TINYINT(1) NOT NULL DEFAULT 1,
  `days_offset` INT NOT NULL DEFAULT 0,
  `subject`     VARCHAR(255) NOT NULL DEFAULT '',
  `body`        TEXT,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generic "already sent" tracking, replaces the birthday-only birthday_email_log.
CREATE TABLE IF NOT EXISTS `automated_email_log` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `email_key`  VARCHAR(50) NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `period_key` VARCHAR(20) NOT NULL,
  `sent_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_send` (`email_key`, `subject_id`, `period_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Remove the birthday-specific rows from site_settings — superseded by automated_emails below.
DELETE FROM `site_settings` WHERE `setting_key` IN
  ('birthday_cadet_subject','birthday_cadet_body','birthday_parent_subject','birthday_parent_body');

INSERT INTO `automated_emails` (email_key, label, description, enabled, days_offset, subject, body) VALUES
('birthday_cadet', 'Cadet Birthday — Cadet Email', 'Sent to the cadet directly on their birthday, if we have their email on file.', 1, 0,
 'Happy Birthday, {name}! 🎉',
 'Happy Birthday, {name}!\n\nThe USAFA Parents Club of Alabama is thinking of you today and wishing you a fantastic birthday.\nThank you for everything you do — we''re proud of you!\n\nAim High · Fly · Fight · Win\nUSAFA Parents Club of Alabama\nhttps://alabamafalcons.org/'),

('birthday_parent', 'Cadet Birthday — Parent Email', 'Sent to the parent(s) recognizing their cadet''s birthday.', 1, 0,
 'Celebrating {cadet_name} Today! 🎂',
 'On behalf of the USAFA Parents Club of Alabama, we want to take a moment to recognize {cadet_name} on their birthday today.\n\nCadets like {name} inspire us with their dedication, discipline, and hard work, and we are incredibly proud of everything they''ve accomplished on their journey at the Academy. We hope today is filled with celebration, and that {name} feels the pride and support of the entire Alabama Falcons family.\n\nHappy Birthday, {name}!\n\nUSAFA Parents Club of Alabama\nhttps://alabamafalcons.org/'),

('dues_renewal', 'Dues Renewal Reminder', 'Sent to parent(s) once, N days before their membership''s paid-through date ends.', 1, 30,
 'Time to Renew Your USAFA Parents Club Membership',
 'Hi {parent_name},\n\nYour USAFA Parents Club of Alabama membership for {cadet_name} is set to expire on {expire_date}.\n\nRenewing helps us continue supporting Alabama cadet families through care packages, events, and community — and keeps you connected with everything happening in the club.\n\nYou can renew anytime here: https://alabamafalcons.org/membership.html\n\nThank you for being part of our club family!\n\nUSAFA Parents Club of Alabama\nhttps://alabamafalcons.org/'),

('meeting_reminder', 'Meeting Reminder', 'Sent the morning of a club meeting. Board meetings go to board-flagged parents only; other meeting types go to all active members.', 1, 0,
 'Reminder: {meeting_title} Today',
 'Hi,\n\nJust a reminder that {meeting_title} is today, {meeting_date}.\n\nLocation: {meeting_location}\nJoin Link: {meeting_link}\n\nWe hope to see you there!\n\nUSAFA Parents Club of Alabama\nhttps://alabamafalcons.org/'),

('new_member_welcome', 'New Member Welcome Follow-up', 'Sent to parent(s) once, N days after a new membership application is received.', 1, 3,
 'Welcome to the USAFA Parents Club of Alabama!',
 'Hi {parent_name},\n\nWelcome to the USAFA Parents Club of Alabama! We''re so glad {cadet_name} and your family are part of our community.\n\nA few ways to get connected:\n- Mentorship Program: https://alabamafalcons.org/mentorship.html\n- Upcoming Events: https://alabamafalcons.org/events-calendar.html\n- Family Resources: https://alabamafalcons.org/familyresources.html\n\nIf you ever have questions, don''t hesitate to reach out to us at info@alabamafalcons.org.\n\nWe''re glad you''re here!\n\nUSAFA Parents Club of Alabama\nhttps://alabamafalcons.org/'),

('lapsed_reengagement', 'Lapsed Member Re-engagement', 'Sent to parent(s) once, N days after their membership''s paid-through date has passed with no renewal.', 1, 60,
 'We''d Love to Have You Back — {cadet_name}',
 'Hi {parent_name},\n\nWe noticed your USAFA Parents Club of Alabama membership for {cadet_name} lapsed on {expire_date}, and we wanted to reach out.\n\nWe''d love for your family to stay connected with the Alabama cadet parent community — through events, care packages, and the support network that comes with having a cadet at the Academy.\n\nRenewing only takes a minute: https://alabamafalcons.org/membership.html\n\nWe hope to see you again soon!\n\nUSAFA Parents Club of Alabama\nhttps://alabamafalcons.org/');
