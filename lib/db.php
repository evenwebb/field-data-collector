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
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (project_id) REFERENCES projects(id)
            )
        ");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_reports_project ON reports(project_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_reports_created ON reports(created_at)");

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
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (project_id) REFERENCES projects(id)
            )
        ");

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
}
