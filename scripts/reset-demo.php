<?php
/**
 * Reset to clean state: remove all personal data, keep demo project.
 * Run: php scripts/reset-demo.php
 */

require_once __DIR__ . '/../config.php';

$dirs = [UPLOADS_DIR, CACHE_DIR];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
        if ($path->isFile()) {
            unlink($path->getPathname());
        } elseif ($path->isDir()) {
            rmdir($path->getPathname());
        }
    }
}

if (file_exists(DB_PATH)) {
    unlink(DB_PATH);
}

echo "Reset complete. All personal data removed.\n";
echo "Fresh database with demo project will be created on next request.\n";
