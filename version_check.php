<?php
include 'config.php';
include 'functions.php';

$checks = [
    'version' => SMARTQUEUE_VERSION,
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
    'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? __FILE__,
    'project_dir' => __DIR__,
    'style_last_modified' => file_exists(__DIR__ . '/style.css') ? date('Y-m-d H:i:s', filemtime(__DIR__ . '/style.css')) : 'missing',
    'admin_last_modified' => file_exists(__DIR__ . '/admin_dashboard.php') ? date('Y-m-d H:i:s', filemtime(__DIR__ . '/admin_dashboard.php')) : 'missing',
    'opcache_enabled' => function_exists('opcache_get_status') ? (opcache_get_status(false)['opcache_enabled'] ?? false ? 'yes' : 'no') : 'not available',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartQueue Version Check</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
<main class="container py-5">
    <section class="panel-card">
        <p class="eyebrow">Diagnostics</p>
        <h1>SmartQueue Version Check</h1>
        <p class="text-muted">If this page does not show the professional UI version, your browser is opening a different folder or Apache needs a restart.</p>
        <div class="table-responsive mt-4">
            <table class="table app-table">
                <tbody>
                    <?php foreach ($checks as $label => $value): ?>
                        <tr>
                            <th><?= e($label) ?></th>
                            <td><?= e($value) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-3">
            <a class="btn btn-brand" href="admin_dashboard.php?v=<?= e(time()) ?>">Open Admin Fresh</a>
            <a class="btn btn-ghost" href="tv_display.php?v=<?= e(time()) ?>">Open TV Fresh</a>
            <a class="btn btn-ghost" href="get_number.php?v=<?= e(time()) ?>">Open Ticket Desk Fresh</a>
        </div>
    </section>
</main>
</body>
</html>
