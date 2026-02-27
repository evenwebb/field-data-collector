<?php
/**
 * Export API: ZIP or PDF with optional date range. Share link (token) when share=1.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/export.php';
require_once __DIR__ . '/../lib/url.php';

$format = $_GET['format'] ?? 'zip';
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$idsParam = $_GET['ids'] ?? null;
$share = isset($_GET['share']);
$token = $_GET['token'] ?? null;
$project = null;

if ($token) {
    $db = DB::get();
    $stmt = $db->prepare('SELECT et.*, p.slug FROM export_tokens et JOIN projects p ON p.id = et.project_id WHERE et.token = ? AND et.expires_at > datetime("now")');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonError('Link expired or invalid', 404);
    }
    $stmt = $db->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$row['project_id']]);
    $project = $stmt->fetch();
    if (!$project) {
        jsonError('Project not found', 404);
    }
    $format = $row['format'];
    $from = $row['from_date'] ?: null;
    $to = $row['to_date'] ?: null;
    $idsParam = $row['selected_ids'] ?? null;
} else {
    $project = requireProjectSlug();
}

if (!in_array($format, ['zip', 'pdf', 'jpg'])) {
    jsonError('Invalid format');
}

$from = Validate::sanitizeDate($from);
$to = Validate::sanitizeDate($to);

$reports = getReportsForExport((int) $project['id'], $project['slug'], $from, $to);

$selectedIds = [];
if (is_string($idsParam) && trim($idsParam) !== '') {
    $idValues = [];
    $decoded = json_decode($idsParam, true);
    if (is_array($decoded)) {
        $idValues = $decoded;
    } else {
        $idValues = explode(',', $idsParam);
    }
    foreach ($idValues as $rawId) {
        $id = (int) trim((string) $rawId);
        if ($id > 0) {
            $selectedIds[] = $id;
        }
    }
    $selectedIds = array_values(array_unique($selectedIds));
    if (empty($selectedIds)) {
        jsonError('Invalid report selection');
    }
}

if (!empty($selectedIds)) {
    $selectedMap = array_flip($selectedIds);
    $reports = array_values(array_filter($reports, static function ($report) use ($selectedMap) {
        return isset($selectedMap[(int) ($report['id'] ?? 0)]);
    }));
}

if (empty($reports)) {
    jsonError('No reports to export');
}

if ($share && !$token) {
    $db = DB::get();
    $shareToken = bin2hex(random_bytes(16));
    $expires = date('Y-m-d H:i:s', strtotime('+' . EXPORT_TOKEN_EXPIRY_HOURS . ' hours'));
    $selectedIdsJson = !empty($selectedIds) ? json_encode($selectedIds) : null;
    $stmt = $db->prepare('INSERT INTO export_tokens (token, project_id, format, from_date, to_date, selected_ids, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$shareToken, $project['id'], $format, $from, $to, $selectedIdsJson, $expires]);
    $shareUrl = base_url() . '/api/export.php?token=' . urlencode($shareToken);
    jsonResponse(['url' => $shareUrl]);
}

try {
    if ($format === 'zip') {
        $path = createZipExport($project, $reports, $from, $to);
        $contentType = 'application/zip';
        $filename = $project['slug'] . '-export.zip';
    } elseif ($format === 'jpg') {
        $result = createJpgExport($project, $reports, $from, $to);
        $path = $result['path'];
        $contentType = $result['content_type'];
        $filename = $result['filename'];
    } else {
        $path = createPdfExport($project, $reports, $from, $to);
        $contentType = 'application/pdf';
        $filename = $project['slug'] . '-export.pdf';
    }
} catch (Exception $e) {
    error_log('Export failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $showError = DEBUG;
    jsonError($showError ? $e->getMessage() : 'Export failed', 500);
}

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
@unlink($path);
exit;
