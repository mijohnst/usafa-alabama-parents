-- Add gallery photo limit setting
INSERT INTO site_settings (setting_key, setting_label, setting_value, setting_type)
VALUES ('gallery_max_photos', 'Max photos in gallery', '20', 'number')
ON DUPLICATE KEY UPDATE setting_label = VALUES(setting_label), setting_type = VALUES(setting_type);
