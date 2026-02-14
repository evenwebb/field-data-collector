<?php
/**
 * Serve thumbnails for report photos. Validates photo belongs to project.
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

$thumbDir = THUMBNAILS_DIR . '/v2';  // v2: EXIF orientation fix
if (!is_dir($thumbDir)) {
    mkdir($thumbDir, 0755, true);
}
$thumbPath = $thumbDir . '/' . $photo;

if (!file_exists($thumbPath) || filemtime($thumbPath) < filemtime($path)) {
    $img = @imagecreatefromstring(file_get_contents($path));
    if (!$img) {
        header('Content-Type: image/jpeg');
        readfile($path);
        exit;
    }
    // Fix EXIF orientation (phones often store orientation in metadata)
    $exif = @exif_read_data($path);
    if (!empty($exif['Orientation'])) {
        $rotated = null;
        switch ((int) $exif['Orientation']) {
            case 2:
                imageflip($img, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $rotated = imagerotate($img, 180, 0);
                if ($rotated) { $img = $rotated; }
                break;
            case 4:
                imageflip($img, IMG_FLIP_VERTICAL);
                break;
            case 5:
                $rotated = imagerotate($img, -90, 0);
                if ($rotated) { $img = $rotated; imageflip($img, IMG_FLIP_HORIZONTAL); }
                break;
            case 6:
                $rotated = imagerotate($img, -90, 0);
                if ($rotated) { $img = $rotated; }
                break;
            case 7:
                $rotated = imagerotate($img, 90, 0);
                if ($rotated) { $img = $rotated; imageflip($img, IMG_FLIP_HORIZONTAL); }
                break;
            case 8:
                $rotated = imagerotate($img, 90, 0);
                if ($rotated) { $img = $rotated; }
                break;
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $tw = min(THUMBNAIL_WIDTH, $w);
    $th = (int) ($h * $tw / $w);
    $thumb = imagecreatetruecolor($tw, $th);
    if ($thumb) {
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagejpeg($thumb, $thumbPath, 85);
    }
}

if (file_exists($thumbPath)) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    readfile($thumbPath);
} else {
    $ext = strtolower(pathinfo($photo, PATHINFO_EXTENSION));
    $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    header('Content-Type: ' . ($mimes[$ext] ?? 'image/jpeg'));
    header('Cache-Control: public, max-age=86400');
    readfile($path);
}
