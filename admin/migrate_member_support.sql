-- Member-support features: volunteer opportunity sign-ups, event RSVP,
-- member photo submissions, committee interest. Run once in phpMyAdmin.

CREATE TABLE IF NOT EXISTS volunteer_opportunities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  description TEXT,
  event_date DATE NULL,
  location VARCHAR(150),
  spots_needed INT NOT NULL DEFAULT 1,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS volunteer_signups (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opportunity_id INT NOT NULL,
  user_id INT NOT NULL,
  signed_up_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_signup (opportunity_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_rsvps (
  id INT AUTO_INCREMENT PRIMARY KEY,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  guest_count INT NOT NULL DEFAULT 0,
  status ENUM('attending','not_attending') NOT NULL DEFAULT 'attending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rsvp (event_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS photo_submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  album_id INT NULL,
  filename VARCHAR(255) NOT NULL,
  caption VARCHAR(255),
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  reviewed_at TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS committee_interest (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  committee VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_committee (user_id, committee)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
