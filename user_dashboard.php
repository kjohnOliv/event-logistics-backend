<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';
include 'functions.php';

requireLogin();

$userId = (int) $_SESSION['user_id'];
$flash = consumeFlash();
$activeQueue = getUserActiveQueue($conn, $userId);
$recentQueues = getUserRecentQueues($conn, $userId, 6);
$currentServing = getCurrentServing($conn);
$estimatedTime = getEstimatedTime($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard - SmartQueue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
<nav class="navbar app-nav px-3">
    <a class="navbar-brand" href="index.php">SmartQueue</a>
    <div class="ms-auto nav-user"><?= e($_SESSION['full_name']) ?> <a href="logout.php">Logout</a></div>
</nav>

<main class="container py-4">
    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <section class="hero-panel mb-4">
        <div>
            <p class="eyebrow">My Dashboard</p>
            <h1>Hello, <?= e($_SESSION['full_name']) ?></h1>
            <p>Track your ticket and watch the current serving number.</p>
        </div>
        <div class="hero-actions">
            <a href="get_number.php" class="btn btn-brand">Get Queue Number</a>
            <a href="index.php" class="btn btn-ghost">Home</a>
        </div>
    </section>

    <div class="row g-4">
        <div class="col-lg-6">
            <section class="panel-card h-100">
                <p class="eyebrow">Your Active Ticket</p>
                <?php if ($activeQueue): ?>
                    <div class="ticket-large">
                        <span>Queue Number</span>
                        <strong><?= e(queueTicket($activeQueue)) ?></strong>
                        <small><?= e($activeQueue['service_name'] ?? 'Service') ?></small>
                    </div>
                    <p><strong>Status:</strong> <span class="status-pill <?= e(statusBadgeClass($activeQueue['status'])) ?>"><?= e(ucfirst($activeQueue['status'])) ?></span></p>
                    <p><strong>Priority:</strong> <?= e(priorityLabel($activeQueue['priority_type'])) ?></p>
                <?php else: ?>
                    <p class="text-muted">No active queue yet. Grab a number whenever you are ready.</p>
                    <a href="get_number.php" class="btn btn-brand">Get Queue Number</a>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="panel-card h-100 text-center">
                <p class="eyebrow">Lobby Status</p>
                <div class="display-number compact"><?= $currentServing ? e(queueTicket($currentServing)) : '---' ?></div>
                <h3><?= $currentServing ? e($currentServing['name']) : 'No queue serving' ?></h3>
                <p class="text-muted">Estimated waiting time: <strong><?= e($estimatedTime) ?> minutes</strong></p>
            </section>
        </div>
    </div>

    <section class="panel-card mt-4">
        <p class="eyebrow">History</p>
        <h2>Recent Queue Numbers</h2>
        <div class="table-responsive">
            <table class="table app-table align-middle">
                <thead><tr><th>No.</th><th>Service</th><th>Priority</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                    <?php if (count($recentQueues) > 0): ?>
                        <?php foreach ($recentQueues as $queue): ?>
                            <tr>
                                <td><strong><?= e(queueTicket($queue)) ?></strong></td>
                                <td><?= e($queue['service_name'] ?? 'N/A') ?></td>
                                <td><?= e(priorityLabel($queue['priority_type'])) ?></td>
                                <td><span class="status-pill <?= e(statusBadgeClass($queue['status'])) ?>"><?= e(ucfirst($queue['status'])) ?></span></td>
                                <td><?= e($queue['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No queue history yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>



