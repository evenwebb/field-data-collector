<?php
/**
 * SQLite database connection and migrations
 */

require_once __DIR__ . '/../config.php';

class DB {
    private static ?PDO $instance = null;

    public static function get(): PDO {
        if (self::$instance === null) {
            self::ensureDirectories();
            self::$instance = new PDO(
                'sqlite:' . DB_PATH,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            self::$instance->exec('PRAGMA foreign_keys = ON');
            self::migrate();
            self::maybeCleanupCache();
        }
        return self::$instance;
    }

    private static function ensureDirectories(): void {
        foreach ([DATA_DIR, UPLOADS_DIR, CACHE_DIR, THUMBNAILS_DIR] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private static function migrate(): void {
        $db = self::$instance;
        $db->exec("
            CREATE TABLE IF NOT EXISTS projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                option_groups TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_projects_slug ON projects(slug)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS reports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                project_id INTEGER NOT NULL,
                selections TEXT NOT NULL,
                note TEXT,
                lat REAL,
                lng REAL,
                comment TEXT,
                reviewed_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (project_id) REFERENCES projects(id)
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_reports_project ON reports(project_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_reports_created ON reports(created_at)");
        $reportCols = $db->query("PRAGMA table_info(reports)")->fetchAll();
        $hasReviewedAt = false;
        foreach ($reportCols as $col) {
            if (($col['name'] ?? '') === 'reviewed_at') {
                $hasReviewedAt = true;
                break;
            }
        }
        if (!$hasReviewedAt) {
            $db->exec('ALTER TABLE reports ADD COLUMN reviewed_at TEXT');
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS report_photos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                report_id INTEGER NOT NULL,
                photo_path TEXT NOT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                exif_json TEXT,
                FOREIGN KEY (report_id) REFERENCES reports(id)
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_report_photos_report ON report_photos(report_id)");

        $db->exec("
            CREATE TABLE IF NOT EXISTS export_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT NOT NULL UNIQUE,
                project_id INTEGER NOT NULL,
                format TEXT NOT NULL,
                from_date TEXT,
                to_date TEXT,
                selected_ids TEXT,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (project_id) REFERENCES projects(id)
            )
        ");
        $cols = $db->query("PRAGMA table_info(export_tokens)")->fetchAll();
        $hasSelectedIds = false;
        foreach ($cols as $col) {
            if (($col['name'] ?? '') === 'selected_ids') {
                $hasSelectedIds = true;
                break;
            }
        }
        if (!$hasSelectedIds) {
            $db->exec('ALTER TABLE export_tokens ADD COLUMN selected_ids TEXT');
        }

        // Rate limiting table
        $db->exec("
            CREATE TABLE IF NOT EXISTS rate_limit (
                ip TEXT NOT NULL,
                hour_key TEXT NOT NULL,
                count INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (ip, hour_key)
            )
        ");

        self::seedDemoIfEmpty($db);
    }

    private static function seedDemoIfEmpty(PDO $db): void {
        $stmt = $db->query('SELECT COUNT(*) FROM projects');
        if ((int) $stmt->fetchColumn() > 0) return;

        $optionGroups = json_encode([
            ['label' => 'Category', 'choices' => ['A', 'B', 'C']],
            ['label' => 'Status', 'choices' => ['Pending', 'Done', 'Other']],
        ]);
        $db->prepare('INSERT INTO projects (name, slug, option_groups) VALUES (?, ?, ?)')
            ->execute(['Demo Project', 'demo-project', $optionGroups]);
    }

    private static function maybeCleanupCache(): void {
        try {
            // Run roughly 1 out of 25 requests to avoid constant filesystem scans.
            if (random_int(1, 25) !== 1) {
                return;
            }
            self::cleanupFilesOlderThan(CACHE_DIR . '/tiles', CACHE_TTL_TILES_HOURS);
            self::cleanupFilesOlderThan(CACHE_DIR . '/geocode', CACHE_TTL_GEOCODE_HOURS);
            self::cleanupExportArtifacts();
        } catch (Throwable $e) {
            // Keep request path resilient even if cleanup fails.
        }
    }

    private static function cleanupFilesOlderThan(string $dir, int $hours): void {
        if (!is_dir($dir)) {
            return;
        }
        $cutoff = time() - max(1, $hours) * 3600;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $path = $item->getPathname();
            if ($item->isFile()) {
                if ((int) @filemtime($path) < $cutoff) {
                    @unlink($path);
                }
                continue;
            }
            if ($item->isDir()) {
                @rmdir($path);
            }
        }
    }

    private static function cleanupExportArtifacts(): void {
        if (!is_dir(CACHE_DIR)) {
            return;
        }
        $cutoffFiles = time() - max(1, CACHE_TTL_EXPORT_HOURS) * 3600;
        foreach (glob(CACHE_DIR . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['zip', 'pdf', 'jpg'], true)) {
                continue;
            }
            if ((int) @filemtime($path) < $cutoffFiles) {
                @unlink($path);
            }
        }

        $cutoffTemp = time() - max(1, CACHE_TTL_EXPORT_TEMP_HOURS) * 3600;
        foreach (glob(CACHE_DIR . '/export-*') ?: [] as $path) {
            if ((int) @filemtime($path) >= $cutoffTemp) {
                continue;
            }
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                self::removeDirectory($path);
            }
        }
    }

    private static function removeDirectory(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
