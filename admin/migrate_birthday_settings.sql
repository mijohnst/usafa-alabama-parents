-- Run this in phpMyAdmin → alabkmgg_members → SQL tab
INSERT INTO `site_settings` (setting_key, setting_value, setting_label, setting_type) VALUES
('birthday_cadet_subject', 'Happy Birthday, {name}! 🎉', 'Cadet Email — Subject', 'text'),
('birthday_cadet_body', 'Happy Birthday, {name}!\n\nThe USAFA Parents Club of Alabama is thinking of you today and wishing you a fantastic birthday.\nThank you for everything you do — we''re proud of you!\n\nAim High · Fly · Fight · Win\nUSAFA Parents Club of Alabama\nhttps://alabamafalcons.org/', 'Cadet Email — Body', 'textarea'),
('birthday_parent_subject', 'It''s {cadet_name}''s Birthday! 🎂', 'Parent Email — Subject', 'text'),
('birthday_parent_body', 'Hi,\n\nJust a note from the USAFA Parents Club of Alabama — today is {cadet_name}''s birthday! We hope {name} has a wonderful day.\n\nThank you for being part of our club family.\n\nUSAFA Parents Club of Alabama\nhttps://alabamafalcons.org/', 'Parent Email — Body', 'textarea');
