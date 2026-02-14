<?php
/**
 * Projects: list projects with count badges, create new
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/url.php';

$db = DB::get();
$stmt = $db->query('
    SELECT p.*, COUNT(r.id) as report_count
    FROM projects p
    LEFT JOIN reports r ON r.project_id = p.id
    GROUP BY p.id
    ORDER BY p.created_at DESC
');
$projects = $stmt->fetchAll();

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0d9488">
    <title>Field Reports</title>
    <link rel="stylesheet" href="<?= url_asset('assets/css/app.css') ?>">
</head>
<body class="dashboard">
    <header>
        <div class="header-brand">
            <h1>Field Reports</h1>
            <p class="header-tagline">Collect photos and data from the field</p>
        </div>
        <a href="<?= url_index('new=1') ?>" class="btn btn-primary">+ New Project</a>
    </header>

    <main>
        <?php if (isset($_GET['new'])): ?>
        <section class="card create-form">
            <a href="<?= url_index() ?>" class="back-link">← Back to projects</a>
            <h2>Create a new project</h2>
            <p class="create-intro">Set up option groups so field workers can select choices when submitting reports.</p>
            <form id="createProjectForm">
                <label for="name">Project name</label>
                <input type="text" id="name" name="name" required placeholder="e.g. Demo Project" autofocus>
                <span class="hint">A short, descriptive name for this collection project</span>

                <label for="slug">URL slug</label>
                <input type="text" id="slug" name="slug" placeholder="e.g. demo-project">
                <span class="hint">Used in links: /collect/<strong>your-slug</strong>. Lowercase letters, numbers, hyphens only. Leave empty to auto-generate from name.</span>

                <fieldset class="option-groups-fieldset">
                    <legend>Option groups</legend>
                    <p class="fieldset-hint">Each group becomes a set of choices field workers select from (e.g. "Category" with choices: A, B, C).</p>
                    <div id="optionGroups">
                        <div class="option-group">
                            <label>Option group 1</label>
                            <input type="text" class="group-label" placeholder="Label (e.g. Category)">
                            <input type="text" class="group-choices" placeholder="Choices (comma-separated, e.g. A, B, C)">
                        </div>
                    </div>
                    <button type="button" class="btn btn-secondary" id="addOptionGroup">+ Add another group</button>
                </fieldset>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Create project</button>
                    <a href="<?= url_index() ?>" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
            <div id="createError" class="error" hidden></div>
        </section>
        <?php else: ?>
        <?php if (empty($projects)): ?>
        <section class="welcome-card">
            <div class="welcome-icon" aria-hidden="true">📋</div>
            <h2>Welcome to Field Reports</h2>
            <p>Create a project to start collecting photos and data from the field. Share a link with your team—they can submit reports from any device.</p>
            <ol class="welcome-steps">
                <li><strong>Create a project</strong> with custom option groups (e.g. "Category": A, B, C)</li>
                <li><strong>Share the collection link</strong> with field workers</li>
                <li><strong>Export reports</strong> as ZIP or PDF when ready</li>
            </ol>
            <a href="<?= url_index('new=1') ?>" class="btn btn-primary btn-lg">Create your first project</a>
        </section>
        <?php else: ?>
        <section class="projects-section">
            <h2 class="section-title">Your projects</h2>
            <div class="projects-grid">
                <?php foreach ($projects as $p): ?>
                <a href="<?= url_project($p['slug']) ?>" class="project-card">
                    <h3><?= h($p['name']) ?></h3>
                    <span class="badge"><?= (int) $p['report_count'] ?> <?= (int) $p['report_count'] === 1 ? 'report' : 'reports' ?></span>
                    <span class="project-card-cta">View project →</span>
                </a>
                <?php endforeach; ?>
            </div>
            <a href="<?= url_index('new=1') ?>" class="btn btn-secondary add-project">+ Add another project</a>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </main>

    <script>window.BASE_URL = <?= json_encode(rtrim(base_url(), '/')) ?>;</script>
    <script src="<?= url_asset('assets/js/dashboard.js') ?>"></script>
</body>
</html>
