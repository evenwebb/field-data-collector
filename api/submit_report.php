<?php
/**
 * Submit report: photo(s), selections, note, lat/lng
 */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

$project = requireProjectSlug();

// Rate limiting (check before processing)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$hourKey = date('Y-m-d-H');
$db = DB::get();
$stmt = $db->prepare('SELECT count FROM rate_limit WHERE ip = ? AND hour_key = ?');
$stmt->execute([$ip, $hourKey]);
$row = $stmt->fetch();
$count = $row ? (int) ($row['count'] ?? 0) : 0;
if ($count >= RATE_LIMIT_REPORTS_PER_HOUR) {
    jsonError('Rate limit exceeded. Try again later.', 429);
}

// Get input from JSON or form-data
$input = getJsonInput();
if (empty($input) && !empty($_POST)) {
    $input = $_POST;
}
$selections = is_array($input['selections'] ?? null) ? $input['selections'] : (json_decode($input['selections'] ?? '{}', true) ?? []);

$note = $input['note'] ?? null;
$lat = isset($input['lat']) ? (float) $input['lat'] : null;
$lng = isset($input['lng']) ? (float) $input['lng'] : null;

$optionGroups = json_decode($project['option_groups'], true);
if (!Validate::selections($selections, $optionGroups)) {
    jsonError(implode(', ', Validate::getErrors()));
}
if (!Validate::note($note)) {
    jsonError(implode(', ', Validate::getErrors()));
}

// Handle file uploads
require_once __DIR__ . '/../lib/upload.php';

$photos = [];
if (!empty($_FILES['photos'])) {
    $files = $_FILES['photos'];
    if (is_array($files['name'])) {
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $file = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];
                $result = saveUpload($file);
                if ($result['success']) {
                    $photos[] = ['path' => $result['path'], 'exif_json' => $result['exif_json'] ?? null];
                }
            }
        }
    }
} elseif (!empty($_FILES['photo'])) {
    $result = saveUpload($_FILES['photo']);
    if ($result['success']) {
        $photos[] = ['path' => $result['path'], 'exif_json' => $result['exif_json'] ?? null];
    }
}

if (empty($photos)) {
    jsonError('At least one photo required');
}
if (count($photos) > MAX_PHOTOS_PER_REPORT) {
    jsonError('Maximum ' . MAX_PHOTOS_PER_REPORT . ' photos per report');
}

$db = DB::get();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO reports (project_id, selections, note, lat, lng) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $project['id'],
        json_encode($selections),
        $note ?: null,
        $lat ?: null,
        $lng ?: null,
    ]);
    $reportId = (int) $db->lastInsertId();

    $stmt = $db->prepare('INSERT INTO report_photos (report_id, photo_path, sort_order, exif_json) VALUES (?, ?, ?, ?)');
    foreach ($photos as $i => $p) {
        $stmt->execute([$reportId, $p['path'], $i, $p['exif_json']]);
    }

    $db->commit();
    $stmt = $db->prepare('INSERT INTO rate_limit (ip, hour_key, count) VALUES (?, ?, 1) ON CONFLICT(ip, hour_key) DO UPDATE SET count = count + 1');
    $stmt->execute([$ip, $hourKey]);
} catch (Exception $e) {
    $db->rollBack();
    if (DEBUG) {
        jsonError($e->getMessage(), 500);
    }
    jsonError('Failed to save report', 500);
}

jsonResponse([
    'success' => true,
    'report_id' => $reportId,
], 201);
