-- Run in phpMyAdmin → alabkmgg_members → SQL tab
CREATE TABLE IF NOT EXISTS `site_settings` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `setting_label` VARCHAR(200) NOT NULL DEFAULT '',
  `setting_type`  ENUM('text','textarea','url','number') NOT NULL DEFAULT 'text',
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `site_settings` (setting_key, setting_value, setting_label, setting_type) VALUES
('hero_subtitle',      'Uniting current, incoming, and alumni families of United States Air Force Academy cadets across the great state of Alabama — offering support, community, and connection from appointment through graduation and beyond.', 'Hero Subtitle Text', 'textarea'),
('hero_cta_text',      'Join Our Club',     'Hero Button Text',    'text'),
('hero_cta_url',       '#membership',       'Hero Button URL',     'url'),
('membership_dues',    '$75',               'Annual Dues Amount',  'text'),
('membership_description', 'Membership dues cover cadet support activities, club operations, and care packages for Alabama cadets throughout their Academy journey. As a member, you''ll be part of a community of Alabama families who share the unique experience of having a son or daughter at the Academy — with access to resources and a network of parents who understand the journey firsthand.', 'Membership Benefits Description', 'textarea'),
('president_letter',   'I am absolutely thrilled to connect with you today! As we look toward the future of our club, I am filled with pride for the young men and women we represent—our incredible cadets who are working so hard at the Academy.\n\nWe are so excited to continue supporting Alabama''s cadet families. Our mission is to ensure that every cadet always feels the support of their Alabama roots, and every family feels connected to a community that understands their unique journey.\n\nTo continue our mission, we need your help. We ask all families to get current on their parent dues. These funds go directly toward the programs and initiatives that celebrate our cadets'' milestones.\n\nWe want you to be a part of your cadet''s journey! Whether you have a little time to give or a lot, there are many ways to get involved — care packages, event planning, cadet recognition, communications, and mentorship programs. Your involvement directly supports our Alabama cadets!\n\nThis is only the beginning. Together, we are supporting the future leaders of this great nation.\n\nGod bless each of your families.', 'President''s Letter Content', 'textarea'),
('president_name',     'Kari Johnston',     'President Name (signature)',  'text'),
('president_title',    'President, USAFA Parents Club of Alabama', 'President Title (signature)', 'text'),
('facebook_url',       'https://www.facebook.com/groups/usafaal', 'Facebook Group URL', 'url'),
('footer_resources',   'USAFA Official Site|https://www.usafa.edu\nParents Page|https://www.usafa.edu/parents\nAcademy Calendar|https://www.usafa.edu/academics/academic-calendar/\nCheckpoints Online|https://usafa.org/checkpoints_online\nWebguy|https://www.usafawebguy.com/', 'Footer Resource Links (one per line: Title|URL)', 'textarea');
