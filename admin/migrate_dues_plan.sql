-- Migration: add membership plan tracking (annual vs 4-year)
-- Run once on the production database.

ALTER TABLE members
  ADD COLUMN membership_type        ENUM('annual','4year') NOT NULL DEFAULT 'annual' AFTER membership_year,
  ADD COLUMN membership_paid_through VARCHAR(9)             NOT NULL DEFAULT '' AFTER membership_type;

-- Backfill: existing paid annual members get paid_through = membership_year
UPDATE members
SET membership_paid_through = membership_year
WHERE membership_paid = 1 AND membership_year != '';
