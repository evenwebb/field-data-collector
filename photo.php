<?php
/**
 * Serve full-size report photos. Validates photo belongs to project.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/validate.php';

$slug = $_GET['p'] ?? '';
$photo = $_GET['photo'] ?? '';

if (!$slug || !Validate::slug($slug)) {
    http_response_code(404);
    exit;
}
if (empty($photo) || preg_match('/[^a-zA-Z0-9._-]/', $photo)) {
    http_response_code(400);
    exit;
}

$db = DB::get();
$stmt = $db->prepare('SELECT p.id FROM projects p JOIN reports r ON r.project_id = p.id JOIN report_photos rp ON rp.report_id = r.id WHERE p.slug = ? AND rp.photo_path = ?');
$stmt->execute([$slug, $photo]);
if (!$stmt->fetch()) {
    http_response_code(404);
    exit;
}

$path = UPLOADS_DIR . '/' . $photo;
if (!file_exists($path) || !is_file($path)) {
    http_response_code(404);
    exit;
}

$ext = strtolower(pathinfo($photo, PATHINFO_EXTENSION));
$mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . ($mimes[$ext] ?? 'image/jpeg'));
header('Cache-Control: public, max-age=86400');
readfile($path);
