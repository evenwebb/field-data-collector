<?php
/**
 * Export logic: ZIP and PDF generation
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/map_image.php';
require_once __DIR__ . '/geocode.php';
require_once __DIR__ . '/exif_embed.php';

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

function getExportFontPath(): ?string {
    $paths = [
        FONTS_DIR . '/DejaVuSans.ttf',
        FONTS_DIR . '/Roboto-Regular.ttf',
        '/Library/Fonts/Arial Unicode.ttf',
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) return $p;
    }
    return null;
}

function drawExportText($canvas, int $x, int $y, string $text, $color, ?string $fontPath = null, int $size = 0): void {
    $text = preg_replace('/[^\x20-\x7E]/', '', $text);
    if ($fontPath && $size > 0 && function_exists('imagettftext')) {
        imagettftext($canvas, $size, 0, $x, $y + $size, $color, $fontPath, $text);
    } else {
        imagestring($canvas, 5, $x, $y, $text, $color);
    }
}

function wrapExportText(string $text, int $maxChars): array {
    $lines = [];
    foreach (explode("\n", $text) as $para) {
        foreach (str_split($para, $maxChars) ?: [$para] as $chunk) {
            $lines[] = $chunk;
        }
    }
    return $lines;
}

function getReportsForExport(int $projectId, string $slug, ?string $from, ?string $to): array {
    $db = DB::get();
    $sql = 'SELECT r.* FROM reports r WHERE r.project_id = ?';
    $params = [$projectId];
    if ($from) {
        $sql .= ' AND date(r.created_at) >= ?';
        $params[] = $from;
    }
    if ($to) {
        $sql .= ' AND date(r.created_at) <= ?';
        $params[] = $to;
    }
    $sql .= ' ORDER BY r.created_at';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
    foreach ($reports as &$r) {
        $r['selections'] = json_decode($r['selections'], true);
        $stmt2 = $db->prepare('SELECT * FROM report_photos WHERE report_id = ? ORDER BY sort_order');
        $stmt2->execute([$r['id']]);
        $r['photos'] = $stmt2->fetchAll();
    }
    return $reports;
}

function createZipExport(array $project, array $reports, ?string $from, ?string $to): string {
    $slug = $project['slug'];
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
    if (!is_writable(CACHE_DIR)) {
        throw new Exception('Cache directory is not writable: ' . CACHE_DIR);
    }
    $zipPath = CACHE_DIR . '/' . $slug . '-' . uniqid() . '.zip';
    $zip = new ZipArchive();
    if (!$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
        throw new Exception('Could not create ZIP file. Check cache directory permissions.');
    }
    $tempDir = CACHE_DIR . '/export-' . uniqid();
    if (!mkdir($tempDir, 0755, true)) {
        $zip->close();
        @unlink($zipPath);
        throw new Exception('Could not create temporary export directory.');
    }

    foreach ($reports as $report) {
        $lat = $report['lat'] ? (float) $report['lat'] : null;
        $lng = $report['lng'] ? (float) $report['lng'] : null;
        $roadName = getRoadName($lat, $lng);
        $address = getAddress($lat, $lng);
        $metadata = array_merge($report['selections'] ?? [], [
            'road' => $roadName ?? '',
            'address' => $address ?? '',
            'note' => $report['note'] ?? '',
            'date' => $report['created_at'] ?? '',
        ]);

        foreach ($report['photos'] ?? [] as $i => $photo) {
            $srcPath = UPLOADS_DIR . '/' . $photo['photo_path'];
            if (!file_exists($srcPath)) continue;
            $destPath = $tempDir . '/' . $photo['photo_path'];
            if (!copy($srcPath, $destPath)) {
                throw new Exception('Could not copy photo: ' . $photo['photo_path']);
            }
            compositeMapOntoImage($destPath, $lat, $lng);
            embedExifIntoImage($destPath, $metadata);
            $zipName = $slug . '-' . $report['id'] . '-' . ($i + 1) . '.jpg';
            $zip->addFile($destPath, $zipName);
        }
    }

    $zip->close();
    foreach (glob($tempDir . '/*') as $f) unlink($f);
    rmdir($tempDir);
    return $zipPath;
}

function createPdfExport(array $project, array $reports, ?string $from, ?string $to): string {
    if (!class_exists('TCPDF')) {
        throw new Exception('TCPDF not installed. Run: composer install');
    }
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->SetCreator('Field Reports');
    $pdf->SetTitle($project['name']);
    $pdf->SetAutoPageBreak(true, 15);
    $pdf->SetFont('helvetica', '', 10);

    foreach ($reports as $report) {
        $photos = $report['photos'] ?? [];
        $selections = $report['selections'] ?? [];
        $selText = implode(' | ', array_map(fn($k, $v) => "$k: $v", array_keys($selections), $selections));
        $note = $report['note'] ?? '';
        $date = $report['created_at'] ?? '';
        $comment = $report['comment'] ?? '';
        $lat = $report['lat'] ? (float) $report['lat'] : null;
        $lng = $report['lng'] ? (float) $report['lng'] : null;
        $coords = ($lat !== null && $lng !== null) ? round($lat, 5) . ', ' . round($lng, 5) : '';
        $address = getAddress($lat, $lng);
        $location = $address ? $address . ($coords ? ' (' . $coords . ')' : '') : ($coords ?: 'Not recorded');
        $roadName = getRoadName($lat, $lng);

        foreach ($photos as $i => $photo) {
            $pdf->AddPage();
            $imgPath = UPLOADS_DIR . '/' . $photo['photo_path'];
            if (file_exists($imgPath)) {
                $pdf->Image($imgPath, 15, 15, 180, 0, '', '', '', false, 300);
            }
            $y = $pdf->GetY() + 10;
            $pdf->SetXY(15, $y);
            $html = '<div style="border:1px solid #ccc; padding:10px; background:#f9f9f9;">';
            if ($roadName) $html .= '<b>Road:</b> ' . htmlspecialchars($roadName) . '<br>';
            $html .= '<b>Selections:</b> ' . htmlspecialchars($selText) . '<br>';
            $html .= '<b>Note:</b> ' . htmlspecialchars($note) . '<br>';
            $html .= '<b>Date:</b> ' . htmlspecialchars($date) . '<br>';
            $html .= '<b>Location:</b> ' . htmlspecialchars($location) . '<br>';
            if ($comment) $html .= '<b>Comment:</b> ' . htmlspecialchars($comment);
            $html .= '</div>';
            $pdf->writeHTML($html, true, false, true, false, '');
        }
        if (empty($photos)) {
            $pdf->AddPage();
            $roadLine = $roadName ? "Road: $roadName\n" : '';
            $pdf->Write(0, "Report #{$report['id']}: $selText\n$roadLine$note\nDate: $date\nLocation: $location");
        }
    }

    $pdfPath = CACHE_DIR . '/' . $project['slug'] . '-' . uniqid() . '.pdf';
    $pdf->Output($pdfPath, 'F');
    return $pdfPath;
}

/**
 * Fix EXIF orientation on a GD image resource
 */
function applyExifOrientation(\GdImage $img, string $path): \GdImage {
    $exif = @exif_read_data($path);
    if (empty($exif['Orientation'])) return $img;
    $rotated = null;
    switch ((int) $exif['Orientation']) {
        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
        case 3:
            $rotated = imagerotate($img, 180, 0);
            if ($rotated) return $rotated;
            break;
        case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
        case 5:
            $rotated = imagerotate($img, -90, 0);
            if ($rotated) { $img = $rotated; imageflip($img, IMG_FLIP_HORIZONTAL); }
            break;
        case 6:
            $rotated = imagerotate($img, -90, 0);
            if ($rotated) return $rotated;
            break;
        case 7:
            $rotated = imagerotate($img, 90, 0);
            if ($rotated) { $img = $rotated; imageflip($img, IMG_FLIP_HORIZONTAL); }
            break;
        case 8:
            $rotated = imagerotate($img, 90, 0);
            if ($rotated) return $rotated;
            break;
    }
    return $img;
}

/** @return array{path: string, content_type: string, filename: string} */
function createJpgExport(array $project, array $reports, ?string $from, ?string $to): array {
    $slug = $project['slug'];
    if (!is_dir(CACHE_DIR)) mkdir(CACHE_DIR, 0755, true);
    if (!is_writable(CACHE_DIR)) {
        throw new Exception('Cache directory is not writable: ' . CACHE_DIR);
    }
    $singleReport = count($reports) === 1;
    $zip = null;
    $zipPath = null;
    if (!$singleReport) {
        $zipPath = CACHE_DIR . '/' . $slug . '-jpg-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        if (!$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new Exception('Could not create ZIP file.');
        }
    }
    $tempDir = CACHE_DIR . '/export-jpg-' . uniqid();
    if (!mkdir($tempDir, 0755, true)) {
        if ($zip) { $zip->close(); @unlink($zipPath); }
        throw new Exception('Could not create temporary directory.');
    }

    $pageW = 794;
    $pageH = 1123;
    $padding = 24;
    $headerHeight = 52;
    $mapH = 320;
    $projectName = $project['name'] ?? $slug;
    $fontPath = getExportFontPath();
    $fontSizeSmall = $fontPath ? 12 : 0;
    $fontSizeInfo = $fontPath ? 16 : 0;
    $fontSizeHeader = $fontPath ? 16 : 0;
    $lineHeight = $fontPath ? 26 : 22;
    $labelWidth = $fontPath ? 110 : 0;

    $colors = [
        'header_bg' => [13, 148, 136],
        'header_text' => [255, 255, 255],
        'info_bg' => [255, 255, 255],
        'info_border' => [231, 229, 228],
        'section_bg' => [250, 250, 249],
        'text' => [28, 25, 23],
        'text_muted' => [87, 83, 78],
        'accent' => [13, 148, 136],
    ];

    foreach ($reports as $report) {
        $photos = $report['photos'] ?? [];
        $selections = $report['selections'] ?? [];
        $selText = implode(' | ', array_map(fn($k, $v) => "$k: $v", array_keys($selections), $selections));
        $note = $report['note'] ?? '';
        $date = $report['created_at'] ?? '';
        $lat = $report['lat'] ? (float) $report['lat'] : null;
        $lng = $report['lng'] ? (float) $report['lng'] : null;
        $roadName = getRoadName($lat, $lng);
        $address = getAddress($lat, $lng);

        $infoBlocks = [
            ['Selections', $selText],
            ['Road', $roadName],
            ['Note', $note ?: '(none)'],
            ['Date', $date],
            ['Address', $address ?: '(not recorded)'],
        ];
        $infoBlocks = array_filter($infoBlocks, fn($b) => $b[1] !== null && $b[1] !== '');
        $charsPerLine = $fontPath ? 58 : max(40, (int) (($pageW - $padding * 2) / 10));
        $infoRows = [];
        foreach ($infoBlocks as [$label, $value]) {
            $valueLines = wrapExportText($value, $charsPerLine);
            foreach ($valueLines as $i => $v) {
                $infoRows[] = ['label' => $i === 0 ? $label : '', 'value' => $v];
            }
        }

        $photoParts = [];
        $totalPhotoHeight = 0;

        foreach ($photos as $photo) {
            $srcPath = UPLOADS_DIR . '/' . $photo['photo_path'];
            if (!file_exists($srcPath)) continue;
            $src = @imagecreatefromstring(file_get_contents($srcPath));
            if (!$src) continue;
            $src = applyExifOrientation($src, $srcPath);
            $sw = imagesx($src);
            $sh = imagesy($src);
            $dw = $pageW;
            $dh = (int) ($sh * $dw / $sw);
            $part = imagecreatetruecolor($dw, $dh);
            if (!$part) continue;
            imagecopyresampled($part, $src, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
            $photoParts[] = $part;
            $totalPhotoHeight += $dh;
        }

        if (empty($photoParts)) {
            $totalPhotoHeight = 180;
            $empty = imagecreatetruecolor($pageW, $totalPhotoHeight);
            $bg = imagecolorallocate($empty, 250, 250, 249);
            imagefill($empty, 0, 0, $bg);
            $border = imagecolorallocate($empty, 231, 229, 228);
            imagerectangle($empty, 0, 0, $pageW - 1, $totalPhotoHeight - 1, $border);
            $text = imagecolorallocate($empty, 168, 162, 158);
            imagestring($empty, 5, (int)(($pageW - strlen('No photo') * 8) / 2), (int)(($totalPhotoHeight - 13) / 2), 'No photo', $text);
            $photoParts[] = $empty;
        }

        $mapImg = null;
        if ($lat !== null && $lng !== null) {
            $mapImg = createMapOverlayLarge($lat, $lng, $pageW, $mapH);
        }

        $mapHeightActual = $mapImg ? $mapH : 0;
        $infoBlockHeight = count($infoRows) * $lineHeight + $padding * 2;
        $infoY = $pageH - $infoBlockHeight;
        $availableForPhotoAndMap = $infoY - $headerHeight;
        $photoScale = 1.0;
        if ($totalPhotoHeight + $mapHeightActual > $availableForPhotoAndMap) {
            $availableForPhoto = max(1, $availableForPhotoAndMap - $mapHeightActual);
            $photoScale = min(1.0, $availableForPhoto / $totalPhotoHeight);
        }

        $canvas = imagecreatetruecolor($pageW, $pageH);
        if (!$canvas) {
            foreach ($photoParts as $p) { /* gc */ }
            continue;
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);

        $headerBg = imagecolorallocate($canvas, $colors['header_bg'][0], $colors['header_bg'][1], $colors['header_bg'][2]);
        $headerText = imagecolorallocate($canvas, $colors['header_text'][0], $colors['header_text'][1], $colors['header_text'][2]);
        imagefilledrectangle($canvas, 0, 0, $pageW - 1, $headerHeight - 1, $headerBg);
        $title = $projectName . ' - Report #' . $report['id'];
        drawExportText($canvas, $padding, (int)(($headerHeight - ($fontPath ? 18 : 13)) / 2), $title, $headerText, $fontPath, $fontSizeHeader ?: 12);

        $y = $headerHeight;
        $photoBorder = imagecolorallocate($canvas, $colors['info_border'][0], $colors['info_border'][1], $colors['info_border'][2]);
        foreach ($photoParts as $p) {
            $ph = (int)(imagesy($p) * $photoScale);
            $pw = (int)(imagesx($p) * $photoScale);
            $px = (int)(($pageW - $pw) / 2);
            if ($photoScale < 1.0) {
                $scaled = imagecreatetruecolor($pw, $ph);
                if ($scaled) {
                    imagecopyresampled($scaled, $p, 0, 0, 0, 0, $pw, $ph, imagesx($p), imagesy($p));
                    imagecopy($canvas, $scaled, $px, $y, 0, 0, $pw, $ph);
                }
            } else {
                imagecopy($canvas, $p, $px, $y, 0, 0, $pw, $ph);
            }
            imagerectangle($canvas, $px, $y, $px + $pw - 1, $y + $ph - 1, $photoBorder);
            $y += $ph;
        }

        if ($mapImg) {
            imagecopy($canvas, $mapImg, 0, $y, 0, 0, $pageW, $mapH);
            $borderColor = imagecolorallocate($canvas, $colors['info_border'][0], $colors['info_border'][1], $colors['info_border'][2]);
            imagerectangle($canvas, 0, $y, $pageW - 1, $y + $mapH - 1, $borderColor);
            $y += $mapH;
        }
        $infoBgColor = imagecolorallocate($canvas, $colors['info_bg'][0], $colors['info_bg'][1], $colors['info_bg'][2]);
        $infoBorderColor = imagecolorallocate($canvas, $colors['info_border'][0], $colors['info_border'][1], $colors['info_border'][2]);
        $textColor = imagecolorallocate($canvas, $colors['text'][0], $colors['text'][1], $colors['text'][2]);
        $accentColor = imagecolorallocate($canvas, $colors['accent'][0], $colors['accent'][1], $colors['accent'][2]);
        imagefilledrectangle($canvas, 0, $infoY, $pageW - 1, $pageH - 1, $infoBgColor);
        imagerectangle($canvas, 0, $infoY, $pageW - 1, $pageH - 1, $infoBorderColor);
        $ty = $infoY + $padding + 6;
        foreach ($infoRows as $row) {
            if ($fontPath && $labelWidth && $row['label'] !== '') {
                drawExportText($canvas, $padding, $ty - 2, $row['label'] . ':', $accentColor, $fontPath, $fontSizeInfo);
                drawExportText($canvas, $padding + $labelWidth, $ty - 2, $row['value'], $textColor, $fontPath, $fontSizeInfo);
            } else {
                $line = ($row['label'] ? $row['label'] . ': ' : '') . $row['value'];
                drawExportText($canvas, $padding, $ty, $line, $textColor, $fontPath, $fontSizeInfo ?: 14);
            }
            $ty += $lineHeight;
        }

        $jpgPath = $tempDir . '/' . $slug . '-' . $report['id'] . '.jpg';
        imagejpeg($canvas, $jpgPath, 90);
        if ($zip) {
            $zip->addFile($jpgPath, $slug . '-' . $report['id'] . '.jpg');
        }
    }

    if ($zip) {
        $zip->close();
    }
    $singleJpg = null;
    foreach (glob($tempDir . '/*') as $f) {
        if ($singleReport) {
            $singleJpg = $f;
        } else {
            @unlink($f);
        }
    }

    if ($singleReport && $singleJpg) {
        $destPath = CACHE_DIR . '/' . $slug . '-' . $reports[0]['id'] . '-' . uniqid() . '.jpg';
        rename($singleJpg, $destPath);
        @rmdir($tempDir);
        return ['path' => $destPath, 'content_type' => 'image/jpeg', 'filename' => $slug . '-' . $reports[0]['id'] . '.jpg'];
    }
    @rmdir($tempDir);
    return ['path' => $zipPath, 'content_type' => 'application/zip', 'filename' => $slug . '-jpg-export.zip'];
}
