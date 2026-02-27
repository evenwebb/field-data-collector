<?php
/**
 * OSM tile fetch and composite map onto image
 */

require_once __DIR__ . '/../config.php';

function latLngToTile(float $lat, float $lng, int $zoom): array {
    $n = pow(2, $zoom);
    $x = (int) (($lng + 180) / 360 * $n);
    $latRad = deg2rad($lat);
    $y = (int) ((1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n);
    return [$x, $y, $zoom];
}

/** Lat/lng to global pixel position at zoom (origin top-left). */
function latLngToPixel(float $lat, float $lng, int $zoom): array {
    $n = pow(2, $zoom);
    $x = ($lng + 180) / 360 * $n * 256;
    $latRad = deg2rad($lat);
    $y = (1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n * 256;
    return [$x, $y];
}

function fetchTile(int $x, int $y, int $z): ?string {
    $url = "https://tile.openstreetmap.org/$z/$x/$y.png";
    $cacheDir = CACHE_DIR . '/tiles';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $cacheFile = $cacheDir . "/{$z}_{$x}_{$y}.png";
    if (file_exists($cacheFile)) {
        return file_get_contents($cacheFile);
    }
    $ctx = stream_context_create([
        'http' => [
            'header' => "User-Agent: FieldReports/1.0\r\n",
            'timeout' => 5,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data) {
        file_put_contents($cacheFile, $data);
    }
    return $data ?: null;
}

/** @return \GdImage|null */
function createMapOverlay(?float $lat, ?float $lng, int $width = 200, int $height = 150) {
    if ($lat === null || $lng === null) {
        return null;
    }
    list($tx, $ty, $zoom) = latLngToTile($lat, $lng, 16);
    $tileData = fetchTile($tx, $ty, $zoom);
    if (!$tileData) {
        return null;
    }
    $tile = @imagecreatefromstring($tileData);
    if (!$tile) {
        return null;
    }
    $overlay = imagecreatetruecolor($width, $height);
    if (!$overlay) {
        imagedestroy($tile);
        return null;
    }
    imagecopyresampled($overlay, $tile, 0, 0, 0, 0, $width, $height, 256, 256);
    imagedestroy($tile);
    return $overlay;
}

/** Create larger map centered on lat/lng with marker dot. @return \GdImage|null */
function createMapOverlayLarge(?float $lat, ?float $lng, int $width = 600, int $height = 400) {
    if ($lat === null || $lng === null) return null;
    $zoom = 17;
    list($px, $py) = latLngToPixel($lat, $lng, $zoom);
    $ox = (int) ($px - $width / 2);
    $oy = (int) ($py - $height / 2);
    $tileX0 = (int) floor($ox / 256);
    $tileY0 = (int) floor($oy / 256);
    $tileX1 = (int) ceil(($ox + $width) / 256);
    $tileY1 = (int) ceil(($oy + $height) / 256);
    $cols = $tileX1 - $tileX0;
    $rows = $tileY1 - $tileY0;
    $tileW = $cols * 256;
    $tileH = $rows * 256;
    $overlay = imagecreatetruecolor($tileW, $tileH);
    if (!$overlay) return null;
    $ok = false;
    for ($dy = 0; $dy < $rows; $dy++) {
        for ($dx = 0; $dx < $cols; $dx++) {
            $tileData = fetchTile($tileX0 + $dx, $tileY0 + $dy, $zoom);
            if (!$tileData) continue;
            $tile = @imagecreatefromstring($tileData);
            if (!$tile) continue;
            imagecopy($overlay, $tile, $dx * 256, $dy * 256, 0, 0, 256, 256);
            imagedestroy($tile);
            $ok = true;
        }
    }
    if (!$ok) {
        imagedestroy($overlay);
        return null;
    }
    $srcX = $ox - $tileX0 * 256;
    $srcY = $oy - $tileY0 * 256;
    if ($srcX < 0) { $srcX = 0; }
    if ($srcY < 0) { $srcY = 0; }
    $copyW = min($width, $tileW - $srcX);
    $copyH = min($height, $tileH - $srcY);
    $resized = imagecreatetruecolor($width, $height);
    if (!$resized) return $overlay;
    imagecopy($resized, $overlay, 0, 0, $srcX, $srcY, $copyW, $copyH);
    imagedestroy($overlay);
    $displayOriginX = $tileX0 * 256 + $srcX;
    $displayOriginY = $tileY0 * 256 + $srcY;
    $dotX = (int) ($px - $displayOriginX);
    $dotY = (int) ($py - $displayOriginY);
    if ($dotX >= 0 && $dotX < $width && $dotY >= 0 && $dotY < $height) {
        $white = imagecolorallocate($resized, 255, 255, 255);
        $red = imagecolorallocate($resized, 220, 38, 38);
        imagefilledellipse($resized, $dotX, $dotY, 16, 16, $white);
        imagefilledellipse($resized, $dotX, $dotY, 10, 10, $red);
    }
    return $resized;
}

function compositeMapOntoImage(string $imagePath, ?float $lat, ?float $lng): bool {
    $img = @imagecreatefromstring(file_get_contents($imagePath));
    if (!$img) {
        return false;
    }
    $overlay = createMapOverlay($lat, $lng, 200, 150);
    if (!$overlay) {
        imagedestroy($img);
        return false;
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $ox = $w - 210;
    $oy = $h - 160;
    if ($ox < 0) $ox = 10;
    if ($oy < 0) $oy = 10;
    imagecopy($img, $overlay, $ox, $oy, 0, 0, 200, 150);
    $border = imagecolorallocate($img, 255, 255, 255);
    imagerectangle($img, $ox - 1, $oy - 1, $ox + 200, $oy + 150, $border);
    $ok = imagejpeg($img, $imagePath, 90);
    imagedestroy($overlay);
    imagedestroy($img);
    return (bool) $ok;
}
