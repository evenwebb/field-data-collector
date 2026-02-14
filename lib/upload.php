<?php
/**
 * Safe file upload handling with validation and random filenames
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/validate.php';

function saveUpload(array $file): array {
    $validated = Validate::upload($file);
    if (!$validated['valid']) {
        return ['success' => false, 'error' => implode(', ', Validate::getErrors())];
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $validated['ext'];
    $path = UPLOADS_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $path)) {
        return ['success' => false, 'error' => 'Failed to save file'];
    }

    $exifJson = null;
    if (!empty($validated['exif'])) {
        $exifJson = json_encode($validated['exif']);
    }

    return [
        'success' => true,
        'path' => $filename,
        'exif_json' => $exifJson,
    ];
}
