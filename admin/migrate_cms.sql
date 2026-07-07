-- Run in phpMyAdmin → alabkmgg_members → SQL tab
-- CMS tables for leadership, announcements, gallery, sponsors

CREATE TABLE IF NOT EXISTS `leadership` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `name`           VARCHAR(150) NOT NULL,
  `role_title`     VARCHAR(100) NOT NULL,
  `bio`            TEXT,
  `photo_filename` VARCHAR(255),
  `email`          VARCHAR(200),
  `sort_order`     INT NOT NULL DEFAULT 0,
  `active`         TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcements` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `message`    VARCHAR(500) NOT NULL,
  `type`       ENUM('info','warning','urgent') NOT NULL DEFAULT 'info',
  `link_text`  VARCHAR(100),
  `link_url`   VARCHAR(500),
  `expires_at` DATETIME NULL,
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_photos` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `filename`    VARCHAR(255) NOT NULL,
  `caption`     VARCHAR(300),
  `sort_order`  INT NOT NULL DEFAULT 0,
  `active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sponsors` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `name`           VARCHAR(200) NOT NULL,
  `level`          ENUM('presenting','gold','silver','individual','other') NOT NULL DEFAULT 'individual',
  `description`    TEXT,
  `website_url`    VARCHAR(500),
  `logo_filename`  VARCHAR(255),
  `sort_order`     INT NOT NULL DEFAULT 0,
  `active`         TINYINT(1) NOT NULL DEFAULT 1,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed current officers
INSERT INTO `leadership` (name,role_title,bio,photo_filename,email,sort_order,active) VALUES
('Kari Johnston','President','Kari is the proud mother of three, including Cadet Hunter Johnston, Class of 2028, and lives in Madison, Alabama. She has been a nurse for 27 years and currently serves as a NICU nurse at Huntsville Hospital. Outside of work, Kari enjoys hiking local trails and giving back through her church and community. She is honored to serve as President of the USAFA Parents Club of Alabama.','officer-kari.jpg','president@alabamafalcons.org',10,1),
('Susan Jackson','Vice President','Susan Jackson is a mother of four whose youngest son, Logan, is a member of the United States Military Academy Class of 2029. An Army brat who graduated from the University of Alabama, Susan grew up with a strong appreciation for military service, further strengthened by her husband Mark''s service in the U.S. Army. She lives in Madison, Alabama.','officer-susan.jpg','vp@alabamafalcons.org',20,1),
('Shelley Kavlick','Secretary','Colonel (ret) Shelley Bischoff Kavlick served in the United States Air Force Regular and Reserve components, encompassing 25 years of commissioned service. She now calls Montgomery, AL home and is the proud mother of C2C Adam "AJ" Kavlick (USAFA 2027) majoring in Aerospace Engineering with a minor in Russian.','officer-kavlick.jpg','secretary@alabamafalcons.org',30,1),
('Mike Sheehy','Treasurer','Mike is a retired Army officer and federal civil servant who resides in Madison with his wife Dew. Their greatest joy is in their daughter, Lauren, USAFA class of 2028, and their son, Tevin, Auburn University class of 2029. As Treasurer, Mike manages the club''s finances, including dues, budgeting, accounting, and tax filings.','officer-mike.jpg','treasurer@alabamafalcons.org',40,1),
('Tony Kim','Member at Large','Retired Air Force officer and civil servant at Maxwell Air Force Base. Proud parent of a Navy officer and Cadet 3/C Ian Kim of the 26 Cadet Squadron "Barons" at USAFA.','officer-tony.jpg','atlarge@alabamafalcons.org',50,1);
