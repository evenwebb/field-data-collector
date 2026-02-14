<?php
/**
 * Mobile collection form - shared link target
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/validate.php';
require_once __DIR__ . '/lib/url.php';

$slug = $_GET['p'] ?? '';
if (!$slug || !Validate::slug($slug)) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body><h1>Project not found</h1></body></html>';
    exit;
}

$db = DB::get();
$stmt = $db->prepare('SELECT * FROM projects WHERE slug = ?');
$stmt->execute([$slug]);
$project = $stmt->fetch();
if (!$project) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Not Found</title></head><body><h1>Project not found</h1></body></html>';
    exit;
}

$optionGroups = json_decode($project['option_groups'], true);

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
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title>Submit report - <?= h($project['name']) ?></title>
    <link rel="stylesheet" href="<?= url_asset('assets/css/app.css') ?>">
</head>
<body class="collect">
    <main>
        <h1><?= h($project['name']) ?></h1>

        <form id="reportForm">
            <input type="hidden" name="project" value="<?= h($slug) ?>">

            <section class="section">
                <label for="photos">Photos (required)</label>
                <input type="file" id="photos" name="photos[]" accept="image/*" capture="environment" multiple required>
                <input type="hidden" name="lat" id="lat">
                <input type="hidden" name="lng" id="lng">
                <p class="hint">Take a photo or choose from gallery. Up to <?= MAX_PHOTOS_PER_REPORT ?> photos.</p>
                <div id="photoPreview"></div>
            </section>

            <?php foreach ($optionGroups as $i => $group): ?>
            <section class="section">
                <label><?= h($group['label']) ?></label>
                <div class="option-buttons">
                    <?php foreach ($group['choices'] as $choice): ?>
                    <label class="option-btn">
                        <input type="radio" name="selections[<?= h($group['label']) ?>]" value="<?= h($choice) ?>" required>
                        <span><?= h($choice) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endforeach; ?>

            <section class="section">
                <label for="note">Note (optional)</label>
                <textarea id="note" name="note" rows="3" placeholder="Additional details..."></textarea>
            </section>

            <button type="submit" class="btn btn-primary btn-block" id="submitBtn">Submit Report</button>
        </form>

        <div id="success" class="success" hidden>
            <p>Report submitted successfully!</p>
            <button type="button" class="btn btn-primary" id="addAnother">Add Another</button>
        </div>

        <div id="error" class="error" hidden></div>
    </main>

    <script>window.BASE_URL = <?= json_encode(rtrim(base_url(), '/')) ?>;</script>
    <script src="<?= url_asset('assets/js/collect.js') ?>"></script>
</body>
</html>
