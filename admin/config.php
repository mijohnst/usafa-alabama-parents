<?php
// ─── Database credentials ──────────────────────────────────────────────────
// Create a MySQL database in cPanel → MySQL Databases, then fill in below.
define('DB_HOST', 'localhost');
define('DB_NAME', '');   // e.g. alabkmgg_members
define('DB_USER', '');   // e.g. alabkmgg_admin
define('DB_PASS', '');   // the password you set for that MySQL user

// ─── Admin login (full access) ─────────────────────────────────────────────
// Run setup.php once to generate your password hash, paste it here, then delete setup.php.
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', '');

// ─── Viewer login (read-only) ───────────────────────────────────────────────
// Run setup.php to generate this hash as well.
define('VIEWER_USERNAME', 'viewer');
define('VIEWER_PASSWORD_HASH', '');
