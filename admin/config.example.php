<?php
// Secret-free example. Production should set these values in the hosting
// environment; config.php is intentionally excluded from Git.
define('DB_HOST', getenv('USAFA_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('USAFA_DB_NAME') ?: 'database_name');
define('DB_USER', getenv('USAFA_DB_USER') ?: 'database_user');
define('DB_PASS', getenv('USAFA_DB_PASS') ?: '');
define('GEMINI_API_KEY', getenv('USAFA_GEMINI_API_KEY') ?: '');

// Portal login codes (2FA) are on by default. Emergency switch only — e.g. the
// mail relay is down and nobody can log in. Set back to true (or delete the
// line) as soon as mail works again.
// define('LOGIN_2FA_ENABLED', false);
