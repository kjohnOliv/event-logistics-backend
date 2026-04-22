<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';
include 'functions.php';

requireRole('admin');

$totalQueues = (int) (($conn->query('SELECT COUNT(*) AS total FROM queue')->fetch_assoc()['total'] ?? 0));
$waitingQueues = (int) (($conn->query("SELECT COUNT(*) AS total FROM queue WHERE status = 'waiting' AND archived_at IS NULL")->fetch_assoc()['total'] ?? 0));
$servingQueues = (int) (($conn->query("SELECT COUNT(*) AS total FROM queue WHERE status = 'serving' AND archived_at IS NULL")->fetch_assoc()['total'] ?? 0));
$doneQueues = (int) (($conn->query("SELECT COUNT(*) AS total FROM queue WHERE status = 'done'")->fetch_assoc()['total'] ?? 0));
$cancelledQueues = (int) (($conn->query("SELECT COUNT(*) AS total FROM queue WHERE status = 'cancelled'")->fetch_assoc()['total'] ?? 0));
$priorityCount = (int) (($conn->query("SELECT COUNT(*) AS total FROM queue WHERE priority_type = 'priority'")->fetch_assoc()['total'] ?? 0));
$regularCount = (int) (($conn->query("SELECT COUNT(*) AS total FROM queue WHERE priority_type = 'regular'")->fetch_assoc()['total'] ?? 0));
$todayCount = (int) (($conn->query('SELECT COUNT(*) AS total FROM queue WHERE DATE(created_at) = CURDATE()')->fetch_assoc()['total'] ?? 0));

$mostUsedServiceResult = $conn->query("SELECT s.service_name, COUNT(q.id) AS total FROM queue q LEFT JOIN services s ON q.service_id = s.id GROUP BY q.service_id, s.service_name ORDER BY total DESC LIMIT 1");
$mostUsedService = 'N/A';
if ($mostUsedServiceResult && $mostUsedServiceResult->num_rows > 0) {
    $row = $mostUsedServiceResult->fetch_assoc();
    $mostUsedService = $row['service_name'] ?? 'N/A';
}

$avgActualResult = $conn->query("SELECT ROUND(AVG(TIMESTAMPDIFF(MINUTE, served_at, completed_at)), 1) AS average_minutes FROM queue WHERE served_at IS NOT NULL AND completed_at IS NOT NULL AND status = 'done'");
$avgActual = $avgActualResult ? ($avgActualResult->fetch_assoc()['average_minutes'] ?? null) : null;

$serviceBreakdown = $conn->query("SELECT s.service_name, s.status, COUNT(q.id) AS total FROM services s LEFT JOIN queue q ON q.service_id = s.id GROUP BY s.id, s.service_name, s.status ORDER BY s.service_name ASC");
$recentLogs = $conn->query("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - SmartQueue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
<nav class="navbar app-nav px-3">
    <a class="navbar-brand" href="index.php">SmartQueue Reports</a>
    <div class="ms-auto nav-user">Admin: <?= e($_SESSION['full_name']) ?> <a href="logout.php">Logout</a></div>
</nav>

<main class="container-fluid py-4 px-lg-5">
    <section class="hero-panel mb-4">
        <div>
            <p class="eyebrow">Analytics</p>
            <h1>Reports Dashboard</h1>
            <p>Queue volume, service demand, and recent audit activity in one place.</p>
        </div>
        <div class="hero-actions">
            <a href="admin_dashboard.php" class="btn btn-brand">Back to Control</a>
            <a href="index.php" class="btn btn-ghost">Home</a>
        </div>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-lg-2 col-md-4 col-6"><div class="metric-card"><span>Total</span><strong><?= $totalQueues ?></strong></div></div>
        <div class="col-lg-2 col-md-4 col-6"><div class="metric-card"><span>Today</span><strong><?= $todayCount ?></strong></div></div>
        <div class="col-lg-2 col-md-4 col-6"><div class="metric-card"><span>Waiting</span><strong><?= $waitingQueues ?></strong></div></div>
        <div class="col-lg-2 col-md-4 col-6"><div class="metric-card"><span>Serving</span><strong><?= $servingQueues ?></strong></div></div>
        <div class="col-lg-2 col-md-4 col-6"><div class="metric-card"><span>Done</span><strong><?= $doneQueues ?></strong></div></div>
        <div class="col-lg-2 col-md-4 col-6"><div class="metric-card"><span>Cancelled</span><strong><?= $cancelledQueues ?></strong></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <section class="panel-card h-100">
                <p class="eyebrow">Snapshot</p>
                <h2>Highlights</h2>
                <div class="insight-list">
                    <div><span>Most used service</span><strong><?= e($mostUsedService) ?></strong></div>
                    <div><span>Priority queues</span><strong><?= $priorityCount ?></strong></div>
                    <div><span>Regular queues</span><strong><?= $regularCount ?></strong></div>
                    <div><span>Average actual service time</span><strong><?= $avgActual !== null ? e($avgActual) . 'm' : 'N/A' ?></strong></div>
                </div>
            </section>
        </div>

        <div class="col-lg-8">
            <section class="panel-card">
                <p class="eyebrow">Services</p>
                <h2>Service Breakdown</h2>
                <div class="table-responsive">
                    <table class="table app-table align-middle">
                        <thead><tr><th>Service</th><th>Status</th><th>Total Queues</th></tr></thead>
                        <tbody>
                            <?php if ($serviceBreakdown && $serviceBreakdown->num_rows > 0): ?>
                                <?php while ($row = $serviceBreakdown->fetch_assoc()): ?>
                                    <tr>
                                        <td><?= e($row['service_name']) ?></td>
                                        <td><span class="status-pill <?= $row['status'] === 'active' ? 'badge-done' : 'badge-muted' ?>"><?= e(ucfirst($row['status'])) ?></span></td>
                                        <td><?= e($row['total']) ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center text-muted py-4">No report data available.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <section class="panel-card mt-4">
        <p class="eyebrow">Audit Trail</p>
        <h2>Recent Activity</h2>
        <div class="table-responsive">
            <table class="table app-table align-middle">
                <thead><tr><th>User</th><th>Action</th><th>Description</th><th>Date</th></tr></thead>
                <tbody>
                    <?php if ($recentLogs && $recentLogs->num_rows > 0): ?>
                        <?php while ($log = $recentLogs->fetch_assoc()): ?>
                            <tr>
                                <td><?= e($log['full_name'] ?? 'System') ?></td>
                                <td><?= e($log['action']) ?></td>
                                <td><?= e($log['description']) ?></td>
                                <td><?= e($log['created_at']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No activity yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>

