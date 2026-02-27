<?php
/**
 * Submit report: photo(s), selections, note, lat/lng
 */

require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

function uploadErrorMessage(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'File too large';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload was interrupted';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server upload temp directory is missing';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server could not write uploaded file';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload blocked by server extension';
        case UPLOAD_ERR_NO_FILE:
            return 'No file uploaded';
        case UPLOAD_ERR_OK:
        default:
            return 'Upload failed';
    }
}

function parseNullableFloat($value): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (float) $value;
}

function exifPartToFloat($part): ?float {
    if (is_numeric($part)) {
        return (float) $part;
    }
    if (!is_string($part) || $part === '') {
        return null;
    }
    if (strpos($part, '/') !== false) {
        [$num, $den] = array_pad(explode('/', $part, 2), 2, null);
        if (is_numeric($num) && is_numeric($den) && (float) $den != 0.0) {
            return ((float) $num) / ((float) $den);
        }
        return null;
    }
    return is_numeric($part) ? (float) $part : null;
}

function exifGpsToDecimal($coords, ?string $ref): ?float {
    if (!is_array($coords) || count($coords) < 3) {
        return null;
    }
    $deg = exifPartToFloat($coords[0] ?? null);
    $min = exifPartToFloat($coords[1] ?? null);
    $sec = exifPartToFloat($coords[2] ?? null);
    if ($deg === null || $min === null || $sec === null) {
        return null;
    }
    $value = $deg + ($min / 60.0) + ($sec / 3600.0);
    $ref = strtoupper((string) $ref);
    if ($ref === 'S' || $ref === 'W') {
        $value *= -1;
    }
    return $value;
}

function extractLatLngFromExifJson(?string $exifJson): ?array {
    if (!$exifJson) {
        return null;
    }
    $exif = json_decode($exifJson, true);
    if (!is_array($exif)) {
        return null;
    }

    $lat = exifGpsToDecimal($exif['GPSLatitude'] ?? null, $exif['GPSLatitudeRef'] ?? null);
    $lng = exifGpsToDecimal($exif['GPSLongitude'] ?? null, $exif['GPSLongitudeRef'] ?? null);
    if ($lat === null || $lng === null) {
        return null;
    }
    return ['lat' => $lat, 'lng' => $lng];
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
$lat = parseNullableFloat($input['lat'] ?? null);
$lng = parseNullableFloat($input['lng'] ?? null);

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
$uploadErrors = [];
$uploads = [];

if (!empty($_FILES['photos'])) {
    $files = $_FILES['photos'];
    if (is_array($files['name'])) {
        for ($i = 0; $i < count($files['name']); $i++) {
            $uploads[] = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }
    } else {
        $uploads[] = $files;
    }
} elseif (!empty($_FILES['photo'])) {
    $uploads[] = $_FILES['photo'];
}

$hadAttemptedUpload = false;
foreach ($uploads as $file) {
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        continue;
    }

    $hadAttemptedUpload = true;
    if ($error !== UPLOAD_ERR_OK) {
        $uploadErrors[] = uploadErrorMessage($error);
        continue;
    }

    $result = saveUpload($file);
    if ($result['success']) {
        $photos[] = ['path' => $result['path'], 'exif_json' => $result['exif_json'] ?? null];
    } else {
        $uploadErrors[] = $result['error'] ?? 'Upload failed';
    }
}

if (empty($photos)) {
    if ($hadAttemptedUpload && !empty($uploadErrors)) {
        jsonError('Photo upload failed: ' . $uploadErrors[0]);
    }
    jsonError('At least one photo required');
}
if (count($photos) > MAX_PHOTOS_PER_REPORT) {
    jsonError('Maximum ' . MAX_PHOTOS_PER_REPORT . ' photos per report');
}

// Prefer GPS coordinates embedded in the first uploaded photo.
foreach ($photos as $photo) {
    $gps = extractLatLngFromExifJson($photo['exif_json'] ?? null);
    if ($gps) {
        $lat = $gps['lat'];
        $lng = $gps['lng'];
        break;
    }
}

$db = DB::get();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO reports (project_id, selections, note, lat, lng) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
        $project['id'],
        json_encode($selections),
        $note ?: null,
        $lat,
        $lng,
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
