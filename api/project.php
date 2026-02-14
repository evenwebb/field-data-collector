<?php
/**
 * Project API: Create (POST) and Update (PATCH)
 */

require_once __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'POST') {
    // Create project
    $input = getJsonInput();
    $name = trim($input['name'] ?? '');
    $optionGroups = $input['option_groups'] ?? [];

    if (empty($name)) {
        jsonError('Project name required');
    }
    if (strlen($name) > 200) {
        jsonError('Project name too long');
    }
    if (!Validate::optionGroups($optionGroups)) {
        jsonError(implode(', ', Validate::getErrors()));
    }

    $slug = isset($input['slug']) ? trim($input['slug']) : '';
    if ($slug !== '') {
        if (!Validate::slug($slug)) {
            jsonError(implode(', ', Validate::getErrors()));
        }
    } else {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
        $slug = trim($slug, '-');
        if (empty($slug)) {
            $slug = 'project-' . time();
        }
    }

    $db = DB::get();
    $attempts = 0;
    $baseSlug = $slug;
    while (true) {
        $stmt = $db->prepare('SELECT id FROM projects WHERE slug = ?');
        $stmt->execute([$slug]);
        if (!$stmt->fetch()) {
            break;
        }
        $attempts++;
        $slug = $baseSlug . '-' . $attempts;
        if ($attempts > 100) {
            jsonError('Could not generate unique slug', 500);
        }
    }

    $optionGroupsJson = json_encode($optionGroups);
    $stmt = $db->prepare('INSERT INTO projects (name, slug, option_groups) VALUES (?, ?, ?)');
    $stmt->execute([$name, $slug, $optionGroupsJson]);

    jsonResponse([
        'success' => true,
        'id' => (int) $db->lastInsertId(),
        'slug' => $slug,
        'name' => $name,
    ], 201);
}

if ($method === 'PATCH') {
    // Update project
    $project = requireProjectSlug();
    $input = getJsonInput();

    $name = isset($input['name']) ? trim($input['name']) : $project['name'];
    $optionGroups = $input['option_groups'] ?? json_decode($project['option_groups'], true);
    $newSlug = isset($input['slug']) ? trim($input['slug']) : $project['slug'];

    if (empty($name)) {
        jsonError('Project name required');
    }
    if (strlen($name) > 200) {
        jsonError('Project name too long');
    }
    if (!Validate::optionGroups($optionGroups)) {
        jsonError(implode(', ', Validate::getErrors()));
    }
    if (!Validate::slug($newSlug)) {
        jsonError(implode(', ', Validate::getErrors()));
    }

    $db = DB::get();
    if ($newSlug !== $project['slug']) {
        $stmt = $db->prepare('SELECT id FROM projects WHERE slug = ? AND id != ?');
        $stmt->execute([$newSlug, $project['id']]);
        if ($stmt->fetch()) {
            jsonError('That URL slug is already in use by another project');
        }
    }

    $stmt = $db->prepare('UPDATE projects SET name = ?, slug = ?, option_groups = ? WHERE id = ?');
    $stmt->execute([$name, $newSlug, json_encode($optionGroups), $project['id']]);

    jsonResponse([
        'success' => true,
        'slug' => $newSlug,
        'name' => $name,
    ]);
}

jsonError('Method not allowed', 405);
