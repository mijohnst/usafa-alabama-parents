-- Run in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `sponsors`
  ADD COLUMN `location`             VARCHAR(200)  NOT NULL DEFAULT '' AFTER `description`,
  ADD COLUMN `contribution_type`    VARCHAR(200)  NOT NULL DEFAULT '' AFTER `location`,
  ADD COLUMN `contribution_amount`  VARCHAR(100)  NOT NULL DEFAULT '' AFTER `contribution_type`,
  ADD COLUMN `contribution_purpose` VARCHAR(300)  NOT NULL DEFAULT '' AFTER `contribution_amount`,
  ADD COLUMN `about_text`           TEXT                   AFTER `contribution_purpose`,
  ADD COLUMN `gratitude_quote`      TEXT                   AFTER `about_text`,
  ADD COLUMN `visit_link_text`      VARCHAR(100)  NOT NULL DEFAULT '' AFTER `gratitude_quote`;

-- Seed current sponsors
INSERT INTO `sponsors` (name,level,location,contribution_type,contribution_amount,contribution_purpose,about_text,gratitude_quote,website_url,visit_link_text,logo_filename,sort_order,active) VALUES

('Holtz Leather Co.','gold','Huntsville, Alabama','2026 Graduate Sponsor','Generous Gift','Supporting families, cadets, and our community mission',
'Holtz Leather Co. is a Huntsville, Alabama-based company that handcrafts fine leather goods with a commitment to quality and traditional craftsmanship. Every wallet, journal, briefcase, belt, and travel accessory is made to order by skilled artisans—no shortcuts, no mass production, just honest American craftsmanship.\n\nProudly made in the U.S.A., Holtz Leather offers personalized gifts and corporate gifting solutions, combining timeless leather artistry with modern personalization. Their story is one of resilience and determination, building a family business from humble beginnings into a nationally recognized brand.',
'We are honored to have Holtz Leather Co. stand alongside our mission. Their dedication to American craftsmanship and community reflects the same values we instill in our cadets—integrity, excellence, and service. Thank you for your generous support of Alabama''s future Air Force leaders.',
'https://www.holtzleather.com/','Learn More About Holtz Leather','holtz_leather_logo.avif',10,1),

('Yulista Holding, LLC','gold','Huntsville, Alabama','2026 June Cadet Sendoff Sponsor','Generous Gift','Supporting families, cadets, and our community mission',
'Yulista is an Alaska Native Corporation and trusted aerospace and defense partner, headquartered right here in Huntsville, Alabama. With a dedicated team of over 800 professionals, Yulista delivers comprehensive aviation maintenance, engineering, logistics, and mission support services to military and civilian customers worldwide.\n\nTheir expertise spans aviation, aerospace, maritime, ground systems, and cyber/IT domains. Beyond defense contracting, Yulista''s mission is deeply rooted in serving people—as an Alaska Native Corporation, their success helps sustain the way of life for Central Yup''ik and Athabascan communities in the Calista Region of Alaska.',
'The Alabama USAFA Parents Club extends our deepest gratitude to Yulista for their outstanding support of our mission. Their commitment to service—both in defense of our nation and in support of our local community—exemplifies the values we hold dear. We are honored to partner with an organization that shares our dedication to developing future Air Force leaders.',
'https://yulista.com','Learn More About Yulista','yulista-logo.png',20,1),

('An Anonymous Parent','individual','','Outstanding Individual Sponsor','$500','Distinguished Contribution from an anonymous parent',
'We are deeply grateful for this generous donation supporting our cadets and families. This contribution exemplifies a commitment to the club''s mission and will directly support care packages, recognition programs, and family support initiatives across Alabama.',
'This generous support makes a meaningful impact on our club and the families we serve. We are deeply grateful for this contribution.',
NULL,'',NULL,30,1);
