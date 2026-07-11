-- "2031" and "Prep School" represent the same cohort right now — that
-- class hasn't matriculated to the Academy yet, so anyone tagged 2031 is
-- really a Prep School cadet. One-time cleanup; run once in phpMyAdmin.
-- (When that class actually starts at the Academy, re-add '2031' to
-- CLASS_YEARS/CLASS_YEAR_LIST in admin/auth.php and move them forward.)

UPDATE members SET class_year = 'Prep School' WHERE class_year = '2031';
