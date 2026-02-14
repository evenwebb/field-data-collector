<?php
/**
 * Project page: reports list, filters, map view, export, share link
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/validate.php';
require_once __DIR__ . '/lib/url.php';

$slug = $_GET['p'] ?? '';
if (!$slug || !Validate::slug($slug)) {
    header('Location: ' . url_index());
    exit;
}

$db = DB::get();
$stmt = $db->prepare('SELECT * FROM projects WHERE slug = ?');
$stmt->execute([$slug]);
$project = $stmt->fetch();
if (!$project) {
    header('Location: ' . url_index());
    exit;
}

$optionGroups = json_decode($project['option_groups'], true);
$view = $_GET['view'] ?? 'list';

$pdfExportAvailable = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $pdfExportAvailable = class_exists('TCPDF');
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$collectUrl = url_collect($slug);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0d9488">
    <title><?= h($project['name']) ?> - Field Reports</title>
    <link rel="stylesheet" href="<?= url_asset('assets/css/app.css') ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
</head>
<body class="project-dashboard">
    <header>
        <a href="<?= url_index() ?>" class="back">← Back to projects</a>
        <h1><?= h($project['name']) ?></h1>
    </header>

    <main>
        <nav class="tabs">
            <a href="<?= url_project($slug) ?>" class="<?= $view === 'list' ? 'active' : '' ?>">Reports</a>
            <a href="<?= url_project($slug, 'map') ?>" class="<?= $view === 'map' ? 'active' : '' ?>">Map</a>
        </nav>

        <section class="share-link card highlight">
            <label>Collection link</label>
            <p class="hint">Share this link for field data collection</p>
            <div class="copy-row">
                <input type="text" id="collectUrl" value="<?= h($collectUrl) ?>" readonly>
                <button type="button" class="btn btn-primary" id="copyLink">Copy</button>
            </div>
        </section>

        <?php if ($view === 'list'): ?>
        <section class="reports-header">
            <span class="report-count" id="reportCount">–</span>
        </section>
        <section class="filters card collapsible">
            <button type="button" class="collapsible-trigger" aria-expanded="false" aria-controls="filtersContent">
                <h3>Filters</h3>
                <span class="collapsible-icon" aria-hidden="true">▼</span>
            </button>
            <div id="filtersContent" class="collapsible-content" hidden>
            <div class="filter-row">
                <select id="filterOption">
                    <option value="">-- Filter by option --</option>
                    <?php foreach ($optionGroups as $g): ?>
                    <option value="<?= h($g['label']) ?>"><?= h($g['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="filterValue" disabled>
                    <option value="">-- Value --</option>
                </select>
            </div>
            <div class="filter-row">
                <input type="date" id="filterFrom" placeholder="From">
                <input type="date" id="filterTo" placeholder="To">
            </div>
            <div class="filter-row">
                <input type="text" id="filterSearch" placeholder="Search notes...">
                <button type="button" class="btn btn-primary" id="applyFilters">Apply</button>
                <button type="button" class="btn btn-secondary" id="clearFilters" title="Clear all filters">Clear</button>
            </div>
            </div>
        </section>

        <section class="bulk-actions" id="bulkActions" hidden>
            <span id="selectedCount">0 selected</span>
            <button type="button" class="btn btn-danger" id="bulkDelete">Delete</button>
        </section>

        <section class="reports-list" id="reportsList">
            <p class="loading">Loading reports...</p>
        </section>
        <?php endif; ?>

        <?php if ($view === 'map'): ?>
        <section class="map-container card">
            <div id="map" style="height: 400px;"></div>
        </section>
        <?php endif; ?>

        <section class="export card collapsible">
            <button type="button" class="collapsible-trigger" aria-expanded="false" aria-controls="exportContent">
                <h3>Export</h3>
                <span class="collapsible-icon" aria-hidden="true">▼</span>
            </button>
            <div id="exportContent" class="collapsible-content" hidden>
            <p class="hint">Optional: limit by date range. Leave empty for all reports.</p>
            <div class="export-row">
                <input type="date" id="exportFrom" aria-label="From date">
                <input type="date" id="exportTo" aria-label="To date">
            </div>
            <div class="export-buttons">
                <button type="button" class="btn btn-primary" id="exportZip">Export ZIP</button>
                <button type="button" class="btn btn-primary" id="exportJpg">Export JPG</button>
                <?php if ($pdfExportAvailable): ?>
                <button type="button" class="btn btn-primary" id="exportPdf">Export PDF</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" id="shareExport">Get share link</button>
            </div>
            </div>
        </section>

        <section class="edit-project card collapsible">
            <button type="button" class="collapsible-trigger" aria-expanded="<?= isset($_GET['edit']) ? 'true' : 'false' ?>" aria-controls="editContent">
                <h3>Edit project</h3>
                <span class="collapsible-icon" aria-hidden="true">▼</span>
            </button>
            <div id="editContent" class="collapsible-content" <?= isset($_GET['edit']) ? '' : 'hidden' ?>>
            <?php if (isset($_GET['edit'])): ?>
            <form id="editProjectForm">
                <label for="editName">Project name</label>
                <input type="text" id="editName" value="<?= h($project['name']) ?>">
                <label for="editSlug">URL slug</label>
                <input type="text" id="editSlug" value="<?= h($project['slug']) ?>" placeholder="e.g. demo-project">
                <span class="hint">Used in links: /collect/<strong>your-slug</strong>. Lowercase letters, numbers, hyphens only.</span>
                <div id="slugChangeWarning" class="slug-warning" hidden>
                    <strong>Warning:</strong> Changing the URL slug will break existing collection links. Anyone with the old link will get an error. Share the new link after saving.
                </div>
                <div id="editOptionGroups"></div>
                <button type="button" class="btn btn-secondary" id="addEditGroup">Add option group</button>
                <div class="form-actions" style="margin-top:1rem">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <a href="<?= url_project($slug) ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            <?php else: ?>
            <a href="<?= url_project($slug, 'edit') ?>" class="btn btn-secondary">Edit options</a>
            <?php endif; ?>
            </div>
        </section>

        <div class="report-detail-modal" id="reportDetailModal" hidden>
            <div class="modal-backdrop"></div>
            <div class="modal-content">
                <button type="button" class="modal-close" aria-label="Close">&times;</button>
                <div class="modal-body">
                    <div class="report-detail-view" id="reportDetailView">
                        <div class="report-detail-photos" id="reportDetailPhotos"></div>
                        <div class="report-detail-info" id="reportDetailInfo"></div>
                        <div class="report-detail-map" id="reportDetailMap" hidden></div>
                        <div class="report-detail-actions">
                            <button type="button" class="btn btn-primary" id="reportDetailEditBtn">Edit</button>
                        </div>
                    </div>
                    <div class="report-detail-edit" id="reportDetailEdit" hidden>
                        <form id="reportEditForm">
                            <div id="reportEditFields"></div>
                            <label for="reportEditNote">Note</label>
                            <textarea id="reportEditNote" rows="3"></textarea>
                            <label for="reportEditComment">Comment</label>
                            <textarea id="reportEditComment" rows="2" placeholder="Internal note"></textarea>
                            <div class="form-actions">
                                <button type="submit" class="btn btn-primary">Save</button>
                                <button type="button" class="btn btn-secondary" id="reportEditCancel">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        window.PROJECT_SLUG = <?= json_encode($slug) ?>;
        window.OPTION_GROUPS = <?= json_encode($optionGroups) ?>;
        window.PROJECT_NAME = <?= json_encode($project['name']) ?>;
        window.BASE_URL = <?= json_encode(rtrim(base_url(), '/')) ?>;
        window.API_BASE = window.BASE_URL + '/api';
    </script>
    <script src="<?= url_asset('assets/js/dashboard.js') ?>"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <script src="<?= url_asset('assets/js/project.js') ?>"></script>
    <?php if ($view === 'map'): ?>
    <script src="<?= url_asset('assets/js/map.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
