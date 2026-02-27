<?php
/**
 * Safe file upload handling with validation and random filenames
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/validate.php';

function orientImageForUpload(\GdImage $img, ?int $orientation): \GdImage {
    if (!$orientation || $orientation < 2 || $orientation > 8) {
        return $img;
    }
    $rotated = null;
    switch ($orientation) {
        case 2:
            imageflip($img, IMG_FLIP_HORIZONTAL);
            return $img;
        case 3:
            $rotated = imagerotate($img, 180, 0);
            break;
        case 4:
            imageflip($img, IMG_FLIP_VERTICAL);
            return $img;
        case 5:
            $rotated = imagerotate($img, -90, 0);
            if ($rotated) {
                imageflip($rotated, IMG_FLIP_HORIZONTAL);
            }
            break;
        case 6:
            $rotated = imagerotate($img, -90, 0);
            break;
        case 7:
            $rotated = imagerotate($img, 90, 0);
            if ($rotated) {
                imageflip($rotated, IMG_FLIP_HORIZONTAL);
            }
            break;
        case 8:
            $rotated = imagerotate($img, 90, 0);
            break;
    }
    if ($rotated instanceof \GdImage) {
        imagedestroy($img);
        return $rotated;
    }
    return $img;
}

function downscaleUploadedImage(string $path, string $mime, ?array $exif = null): bool {
    $size = @getimagesize($path);
    if (!$size || empty($size[0]) || empty($size[1])) {
        return true;
    }
    $srcW = (int) $size[0];
    $srcH = (int) $size[1];
    if ($srcW <= 0 || $srcH <= 0) {
        return true;
    }

    $orientation = isset($exif['Orientation']) ? (int) $exif['Orientation'] : null;
    $needsResize = max($srcW, $srcH) > MAX_IMAGE_DIMENSION;
    $needsNormalize = $orientation && $orientation >= 2 && $orientation <= 8;
    if (!$needsResize && !$needsNormalize) {
        return true;
    }

    if ($mime === 'image/jpeg') {
        $src = @imagecreatefromjpeg($path);
    } elseif ($mime === 'image/png') {
        $src = @imagecreatefrompng($path);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($path);
    } else {
        return true;
    }
    if (!$src) {
        return true;
    }

    $src = orientImageForUpload($src, $orientation);
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $scale = min(1.0, MAX_IMAGE_DIMENSION / max($srcW, $srcH));
    $dstW = max(1, (int) round($srcW * $scale));
    $dstH = max(1, (int) round($srcH * $scale));

    $dst = imagecreatetruecolor($dstW, $dstH);
    if (!$dst) {
        imagedestroy($src);
        return true;
    }

    if ($mime === 'image/png' || $mime === 'image/webp') {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $transparent);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    $saved = false;
    if ($mime === 'image/jpeg') {
        $saved = imagejpeg($dst, $path, 88);
    } elseif ($mime === 'image/png') {
        $saved = imagepng($dst, $path, 6);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        $saved = imagewebp($dst, $path, 85);
    }

    imagedestroy($dst);
    imagedestroy($src);
    return (bool) $saved;
}

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

    downscaleUploadedImage($path, $validated['mime'] ?? '', $validated['exif'] ?? null);

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
