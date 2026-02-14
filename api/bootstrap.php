<?php
/**
 * Shared API bootstrap: JSON headers, error handling, optional slug validation
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/validate.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    jsonResponse(['error' => $message], $code);
}

function getProjectBySlug(string $slug): ?array {
    $db = DB::get();
    $stmt = $db->prepare('SELECT * FROM projects WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function requireProjectSlug(): array {
    $slug = $_GET['p'] ?? $_GET['project'] ?? null;
    if (!$slug || !is_string($slug)) {
        jsonError('Project slug required', 400);
    }
    if (!Validate::slug($slug)) {
        jsonError(implode(', ', Validate::getErrors()), 400);
    }
    $project = getProjectBySlug($slug);
    if (!$project) {
        jsonError('Project not found', 404);
    }
    return $project;
}

function getJsonInput(): array {
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return [];
    }
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}
