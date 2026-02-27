<?php
/**
 * Field Reports Configuration
 * Paths, limits, and flags. No magic numbers in code.
 */

// Base path (directory containing config.php)
define('BASE_PATH', __DIR__);

// Directory paths
define('DATA_DIR', BASE_PATH . '/data');
define('UPLOADS_DIR', BASE_PATH . '/uploads');
define('CACHE_DIR', BASE_PATH . '/cache');
define('THUMBNAILS_DIR', CACHE_DIR . '/thumbnails');

// Database
define('DB_PATH', DATA_DIR . '/audit.db');

// Limits
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);  // 10MB
define('MAX_PHOTOS_PER_REPORT', 3);
define('RATE_LIMIT_REPORTS_PER_HOUR', 30);
define('MAX_IMAGE_DIMENSION', 2048);  // Resize larger images
define('THUMBNAIL_WIDTH', 150);
define('CACHE_TTL_TILES_HOURS', 24 * 30);      // 30 days
define('CACHE_TTL_GEOCODE_HOURS', 24 * 30);    // 30 days
define('CACHE_TTL_EXPORT_HOURS', 24);          // 1 day
define('CACHE_TTL_EXPORT_TEMP_HOURS', 6);      // 6 hours

// Export
define('EXPORT_TOKEN_EXPIRY_HOURS', 1);
define('FONTS_DIR', BASE_PATH . '/assets/fonts');
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: '');

// Debug (set false in production)
define('DEBUG', false);

// Allowed MIME types for uploads
define('ALLOWED_MIME_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

// Allowed extensions
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp']);

function apply_security_headers(): void {
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://unpkg.com 'unsafe-inline'; style-src 'self' https://unpkg.com 'unsafe-inline'; img-src 'self' data: blob: https://*.tile.openstreetmap.org; font-src 'self' data:; connect-src 'self' https://nominatim.openstreetmap.org https://*.tile.openstreetmap.org; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
}

apply_security_headers();
