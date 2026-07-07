-- Run in phpMyAdmin → alabkmgg_members → SQL tab
ALTER TABLE `events`
  ADD COLUMN `cta_deadline` DATETIME NULL AFTER `cta_note`;

-- Update Parents Weekend with ticket info
UPDATE `events` SET
  description   = 'Connect with other Alabama families at USAFA. 🏈 Football tickets: Our Parent Club has a reserved block in Section M13. Buy early — discounted pricing ends Sunday, August 30 at 11:59pm MT. Tickets delivered electronically as mobile tickets in August.',
  cta_text      = 'Buy Tickets',
  cta_url       = 'https://www.gofevo.com/event/ALPW26',
  cta_note      = 'Questions? Tickets.PW@airforceathletics.org · 719-472-1895',
  cta_deadline  = '2026-08-30 23:59:00'
WHERE title LIKE '%Parents Weekend%';
