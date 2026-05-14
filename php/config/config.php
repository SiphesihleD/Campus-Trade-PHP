<?php
/* ================================================================
   CAMPUS TRADE — config.php
   Database configuration for InfinityFree
   ================================================================ */

define('DB_HOST',     'sql201.infinityfree.com');
define('DB_USER',     'if0_41889082');
define('DB_PASS',     'bWLJaTSxibhQpVQ');
define('DB_NAME',     'if0_41889082_campustrade');  // update XXX once you create the DB
define('DB_PORT',     3306);
define('DB_CHARSET',  'utf8mb4');

// App settings
define('APP_NAME',    'Campus Trade');
define('APP_VERSION', '1.0.0');
define('COMMISSION',  0.15);          // 15% platform fee

// Email domains
define('STUDENT_DOMAIN', '@edu.net');
define('ADMIN_DOMAIN',   '@adminIT.net');

// Session config
define('SESSION_LIFETIME', 86400);   // 24 hours

// File upload settings
define('MAX_FILE_SIZE',    5242880); // 5MB
define('UPLOAD_DIR',       __DIR__ . '/../uploads/');
define('ALLOWED_TYPES',    ['image/jpeg','image/png','image/webp','image/gif']);

// CORS headers are set per-API-file before require_once
// Content-Type set per-API-file as needed

// Error reporting (turn OFF in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);        // Don't expose errors in JSON responses
ini_set('log_errors', 1);

// Timezone
date_default_timezone_set('Africa/Johannesburg');
