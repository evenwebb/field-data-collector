<?php
/**
 * Reports API: List (with filters), PATCH (comment, selections, note), bulk delete
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../lib/geocode.php';

$project = requireProjectSlug();
$projectId = (int) $project['id'];
$db = DB::get();

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    $reportId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $withPhotos = isset($_GET['with_photos']) ? $_GET['with_photos'] !== '0' : false;
    $sort = $_GET['sort'] ?? 'newest';
    $filterOption = $_GET['filter_option'] ?? null;
    $filterValue = $_GET['filter_value'] ?? null;
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    $search = $_GET['search'] ?? null;

    $sql = 'SELECT r.*,
            (SELECT photo_path FROM report_photos WHERE report_id = r.id ORDER BY sort_order LIMIT 1) as primary_photo,
            (SELECT COUNT(*) FROM report_photos WHERE report_id = r.id) as photo_count
            FROM reports r WHERE r.project_id = ?';
    $params = [$projectId];

    if ($filterOption && $filterValue && is_string($filterOption) && is_string($filterValue)) {
        $optionGroups = json_decode($project['option_groups'], true);
        $validLabels = array_column($optionGroups, 'label');
        if (in_array($filterOption, $validLabels) && preg_match('/^[a-zA-Z0-9_\x80-\xff-]+$/u', $filterOption)) {
            $key = '$."' . str_replace(['\\', '"'], ['\\\\', '\\"'], $filterOption) . '"';
            $sql .= ' AND json_extract(r.selections, ?) = ?';
            $params[] = $key;
            $params[] = $filterValue;
        }
    }
    $from = Validate::sanitizeDate($from);
    $to = Validate::sanitizeDate($to);
    if ($from) {
        $sql .= ' AND date(r.created_at) >= ?';
        $params[] = $from;
    }
    if ($to) {
        $sql .= ' AND date(r.created_at) <= ?';
        $params[] = $to;
    }
    if ($search) {
        $sql .= ' AND r.note LIKE ?';
        $params[] = '%' . $search . '%';
    }

    if ($reportId > 0) {
        $sql .= ' AND r.id = ?';
        $params[] = $reportId;
    }
    $orderBy = 'r.created_at DESC';
    if ($sort === 'oldest') {
        $orderBy = 'r.created_at ASC';
    } elseif ($sort === 'reviewed') {
        $orderBy = 'r.reviewed_at IS NULL, r.reviewed_at DESC, r.created_at DESC';
    } elseif ($sort === 'unreviewed') {
        $orderBy = 'r.reviewed_at IS NOT NULL, r.created_at DESC';
    }
    $sql .= ' ORDER BY ' . $orderBy;

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();

    $photosByReport = [];
    if ($withPhotos) {
        $reportIds = array_column($reports, 'id');
        if (!empty($reportIds)) {
            $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
            $stmt2 = $db->prepare("SELECT * FROM report_photos WHERE report_id IN ($placeholders) ORDER BY report_id, sort_order");
            $stmt2->execute($reportIds);
            foreach ($stmt2->fetchAll() as $p) {
                $photosByReport[$p['report_id']][] = $p;
            }
        }
    }

    $roadCache = [];
    foreach ($reports as &$r) {
        $r['selections'] = json_decode($r['selections'], true);
        $r['photo_count'] = isset($r['photo_count']) ? (int) $r['photo_count'] : 0;
        if ($withPhotos) {
            $r['photos'] = $photosByReport[$r['id']] ?? [];
        }
        if ($r['lat'] !== null && $r['lng'] !== null) {
            $key = round((float)$r['lat'], 5) . ',' . round((float)$r['lng'], 5);
            $geo = $roadCache[$key] ??= getGeocodeData((float) $r['lat'], (float) $r['lng']);
            $r['road_name'] = $geo ? ($geo['road'] ?? null) : null;
            $r['address'] = $geo ? ($geo['address'] ?? null) : null;
        } else {
            $r['road_name'] = null;
            $r['address'] = null;
        }
    }

    if ($reportId > 0) {
        $report = $reports[0] ?? null;
        if (!$report) {
            jsonError('Report not found', 404);
        }
        jsonResponse(['report' => $report]);
    }

    $reportCount = count($reports);
    $photoCount = 0;
    foreach ($reports as $r) {
        $photoCount += (int) ($r['photo_count'] ?? 0);
    }
    jsonResponse([
        'reports' => $reports,
        'meta' => [
            'report_count' => $reportCount,
            'photo_count' => $photoCount,
        ],
    ]);
}

if ($method === 'PATCH') {
    $input = getJsonInput();
    $action = $input['action'] ?? null;

    if ($action === 'bulk_delete') {
        $ids = $input['ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            jsonError('IDs required');
        }
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            jsonError('Invalid IDs');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->beginTransaction();
        try {
            $stmtPhotos = $db->prepare("DELETE FROM report_photos WHERE report_id IN (SELECT id FROM reports WHERE project_id = ? AND id IN ($placeholders))");
            $stmtPhotos->execute([$projectId, ...$ids]);
            $stmt = $db->prepare("DELETE FROM reports WHERE id IN ($placeholders) AND project_id = ?");
            $stmt->execute([...$ids, $projectId]);
            $db->commit();
            jsonResponse(['success' => true, 'deleted' => $stmt->rowCount()]);
        } catch (Throwable $e) {
            $db->rollBack();
            jsonError('Delete failed', 500);
        }
    }

    if ($action === 'bulk_comment') {
        $ids = $input['ids'] ?? [];
        $comment = isset($input['comment']) ? (string) $input['comment'] : '';
        if (!Validate::comment($comment)) {
            jsonError(implode(', ', Validate::getErrors()));
        }
        if (!is_array($ids) || empty($ids)) {
            jsonError('IDs required');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (empty($ids)) {
            jsonError('Invalid IDs');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE reports SET comment = ? WHERE project_id = ? AND id IN ($placeholders)");
        $stmt->execute([$comment, $projectId, ...$ids]);
        jsonResponse(['success' => true, 'updated' => $stmt->rowCount()]);
    }

    if ($action === 'bulk_mark_reviewed') {
        $ids = $input['ids'] ?? [];
        $reviewed = !isset($input['reviewed']) || (bool) $input['reviewed'];
        if (!is_array($ids) || empty($ids)) {
            jsonError('IDs required');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
        if (empty($ids)) {
            jsonError('Invalid IDs');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($reviewed) {
            $stmt = $db->prepare("UPDATE reports SET reviewed_at = datetime('now') WHERE project_id = ? AND id IN ($placeholders)");
            $stmt->execute([$projectId, ...$ids]);
        } else {
            $stmt = $db->prepare("UPDATE reports SET reviewed_at = NULL WHERE project_id = ? AND id IN ($placeholders)");
            $stmt->execute([$projectId, ...$ids]);
        }
        jsonResponse(['success' => true, 'updated' => $stmt->rowCount()]);
    }

    // Single report update: comment, selections, note
    $id = (int) ($input['id'] ?? 0);
    if (!$id) jsonError('Report ID required');
    $stmt = $db->prepare('SELECT id FROM reports WHERE id = ? AND project_id = ?');
    $stmt->execute([$id, $projectId]);
    if (!$stmt->fetch()) jsonError('Report not found', 404);

    $optionGroups = json_decode($project['option_groups'], true);
    $updates = [];
    $params = [];
    if (array_key_exists('comment', $input)) {
        $comment = is_string($input['comment']) ? $input['comment'] : '';
        if (!Validate::comment($comment)) {
            jsonError(implode(', ', Validate::getErrors()));
        }
        $updates[] = 'comment = ?';
        $params[] = $comment;
    }
    if (isset($input['selections']) && is_array($input['selections'])) {
        $selections = [];
        foreach ($optionGroups as $g) {
            $label = $g['label'];
            $val = $input['selections'][$label] ?? null;
            if ($val !== null && in_array($val, $g['choices'] ?? [])) {
                $selections[$label] = $val;
            }
        }
        if (!empty($selections)) {
            $updates[] = 'selections = ?';
            $params[] = json_encode($selections);
        }
    }
    if (array_key_exists('note', $input)) {
        $note = is_string($input['note']) ? $input['note'] : null;
        if (!Validate::note($note)) {
            jsonError(implode(', ', Validate::getErrors()));
        }
        $updates[] = 'note = ?';
        $params[] = $note;
    }
    if (empty($updates)) jsonError('Nothing to update');
    $params[] = $id;
    $params[] = $projectId;
    $stmt = $db->prepare('UPDATE reports SET ' . implode(', ', $updates) . ' WHERE id = ? AND project_id = ?');
    $stmt->execute($params);
    jsonResponse(['success' => true]);
}

jsonError('Method not allowed', 405);
