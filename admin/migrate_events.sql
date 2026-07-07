-- Run in phpMyAdmin → alabkmgg_members → SQL tab
CREATE TABLE IF NOT EXISTS `events` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `title`          VARCHAR(300) NOT NULL,
  `event_date`     DATE NULL,
  `event_date_end` DATE NULL,
  `event_time`     VARCHAR(100),
  `location`       VARCHAR(300),
  `description`    TEXT,
  `tag`            VARCHAR(50),
  `group_label`    ENUM('past','upcoming','planning') NOT NULL DEFAULT 'upcoming',
  `cta_text`       VARCHAR(100),
  `cta_url`        VARCHAR(500),
  `cta_note`       TEXT,
  `sort_order`     INT NOT NULL DEFAULT 0,
  `visible`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed with current events
INSERT INTO `events` (title,event_date,event_date_end,event_time,location,description,tag,group_label,cta_text,cta_url,cta_note,sort_order,visible) VALUES
('Class of 2030 Appointee Send-off','2026-06-06',NULL,'12:00–2:00 pm','The Ritchey Center, Maxwell AFB, AL','Celebrating our newest appointees before they head to USAFA.','Social','past','View Photos','gallery.html',NULL,10,1),
('OCF Pre-Inprocessing Day Reception — Class of 2030','2026-06-23',NULL,'4:00–8:00 pm','2355 Bricker Road, Monument, CO','Officers'' Christian Fellowship hosts a reception with dessert for Class of 2030 cadets and their families to meet other Christians before BCT begins. This event is hosted by OCF and is not sponsored by the USAFA Parents Club of Alabama.','Community','past',NULL,NULL,NULL,20,1),
('Strong Academy Day','2026-08-22',NULL,'9:00 am – noon','Auburn Research and Innovation Campus, 345 Voyager Way, Huntsville (near Bridge Street)',NULL,'Social','upcoming',NULL,NULL,NULL,30,1),
('Parents Weekend Social at USAFA','2026-09-04','2026-09-06',NULL,NULL,'Connect with other Alabama families at USAFA','Social','upcoming',NULL,NULL,NULL,40,1),
('Thanksgiving','2026-11-24','2026-11-30',NULL,NULL,'Thanksgiving break. Check with your cadet about release time.','Academy','upcoming',NULL,NULL,NULL,50,1),
('Winter Break','2026-12-17',NULL,NULL,NULL,'December 17 – January 3. Check with your cadet about release time.','Academy','upcoming',NULL,NULL,NULL,60,1),
('Care Packs',NULL,NULL,NULL,NULL,'Care packages for all cadets · Estimated $35–$50 per box','Cadet Support','planning',NULL,NULL,NULL,70,1),
('Taste of Home Event',NULL,NULL,NULL,NULL,'March 2027 · Details coming soon','Social','planning',NULL,NULL,NULL,80,1);
