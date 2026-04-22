<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';
include 'functions.php';

$currentServing = getCurrentServing($conn);
$nextWaiting = getNextWaiting($conn);
$waitingCount = getWaitingCount($conn);
$estimatedTime = getEstimatedTime($conn);
$counts = getQueueCounts($conn);
$role = $_SESSION['role'] ?? 'user';
$isStaff = isLoggedIn() && in_array($role, ['admin', 'operator'], true);
$userQueue = (isLoggedIn() && !$isStaff) ? getUserActiveQueue($conn, (int) $_SESSION['user_id']) : null;
$priorityCount = count(getWaitingQueuesByPriority($conn, 'priority', 100));
$regularCount = count(getWaitingQueuesByPriority($conn, 'regular', 100));
$serviceWindows = getServiceWindows($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartQueue Home</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
<nav class="navbar navbar-expand-lg app-nav px-3">
    <a class="navbar-brand" href="index.php">SmartQueue</a>
    <div class="ms-auto nav-user">
        <span class="version-badge"><?= e(SMARTQUEUE_VERSION) ?></span>
        <?php if (isLoggedIn()): ?>
            <?= e($_SESSION['full_name']) ?>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="login.php">Admin/User Login</a>
        <?php endif; ?>
    </div>
</nav>

<main class="container-fluid py-4 px-lg-5">
    <section class="hero-panel mb-4">
        <div>
            <p class="eyebrow">Live Queue Dashboard</p>
            <h1>Queue flow, window by window.</h1>
            <p>See who is serving, who is next, and how priority and regular lines are moving.</p>
        </div>
        <div class="hero-actions">
            <a href="get_number.php" class="btn btn-brand">Get Queue Number</a>
            <?php if (isLoggedIn() && ($role === 'admin' || $role === 'operator')): ?>
                <a href="admin_dashboard.php" class="btn btn-ghost">Manage Queue</a>
            <?php elseif (isLoggedIn()): ?>
                <a href="user_dashboard.php" class="btn btn-ghost">My Dashboard</a>
            <?php else: ?>
                <a href="login.php" class="btn btn-ghost">Staff Login</a>
            <?php endif; ?>
        </div>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-xl-5">
            <section class="panel-card now-serving-card text-center h-100">
                <p class="eyebrow">Now Serving</p>
                <div class="queue-number display-number">
                    <?= $currentServing ? e(queueTicket($currentServing)) : '---' ?>
                </div>
                <h3><?= $currentServing ? e($currentServing['name']) : 'No active queue' ?></h3>
                <p class="text-muted mb-0"><?= $currentServing ? e($currentServing['service_name'] ?? 'General Service') . ' / ' . e(priorityLabel($currentServing['priority_type'])) : 'The next queue will appear here once called.' ?></p>
            </section>
        </div>

        <div class="col-xl-4">
            <section class="panel-card next-ticket-card h-100">
                <p class="eyebrow">Next Waiting</p>
                <?php if ($nextWaiting): ?>
                    <div class="next-ticket-number"><?= e(queueTicket($nextWaiting)) ?></div>
                    <h3><?= e($nextWaiting['name']) ?></h3>
                    <p class="mb-2"><?= e($nextWaiting['service_name'] ?? 'Service') ?></p>
                    <span class="priority-chip <?= $nextWaiting['priority_type'] === 'priority' ? 'priority-chip-hot' : '' ?>"><?= e(priorityLabel($nextWaiting['priority_type'])) ?></span>
                <?php else: ?>
                    <div class="next-ticket-number">---</div>
                    <h3>No waiting ticket</h3>
                    <p class="text-muted mb-0">The next person or number will appear here.</p>
                <?php endif; ?>
            </section>
        </div>

        <div class="col-xl-3">
            <section class="panel-card h-100">
                <p class="eyebrow"><?= $isStaff ? 'Staff Control' : (isLoggedIn() ? 'Your Ticket' : 'Public Kiosk') ?></p>
                <?php if ($isStaff): ?>
                    <p class="text-muted">Admin and operator accounts are for queue control only. Public tickets stay separate from staff names.</p>
                    <a href="admin_dashboard.php" class="btn btn-brand w-100 mb-2">Open Control</a>
                    <a href="get_number.php" class="btn btn-ghost w-100">Public Ticket Kiosk</a>
                <?php elseif (isLoggedIn() && $userQueue): ?>
                    <div class="ticket-mini">
                        <span><?= e(queueTicket($userQueue)) ?></span>
                        <strong><?= e($userQueue['service_name'] ?? 'Service') ?></strong>
                    </div>
                    <p class="mb-2"><strong>Status:</strong> <span class="status-pill <?= e(statusBadgeClass($userQueue['status'])) ?>"><?= e(ucfirst($userQueue['status'])) ?></span></p>
                    <p class="mb-0"><strong>Priority:</strong> <?= e(priorityLabel($userQueue['priority_type'])) ?></p>
                <?php elseif (isLoggedIn()): ?>
                    <p class="text-muted">You do not have an active queue yet.</p>
                    <a href="get_number.php" class="btn btn-brand w-100">Get a Number</a>
                <?php else: ?>
                    <p class="text-muted">No login needed. Choose a service and generate a queue ticket freely.</p>
                    <a href="get_number.php" class="btn btn-brand w-100">Get Queue Number</a>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-2 col-6"><div class="metric-card"><span>Waiting</span><strong><?= $waitingCount ?></strong></div></div>
        <div class="col-md-2 col-6"><div class="metric-card"><span>Priority</span><strong><?= $priorityCount ?></strong></div></div>
        <div class="col-md-2 col-6"><div class="metric-card"><span>Regular</span><strong><?= $regularCount ?></strong></div></div>
        <div class="col-md-2 col-6"><div class="metric-card"><span>Serving</span><strong><?= $counts['serving'] ?></strong></div></div>
        <div class="col-md-2 col-6"><div class="metric-card"><span>Done</span><strong><?= $counts['done'] ?></strong></div></div>
        <div class="col-md-2 col-6"><div class="metric-card"><span>Est. Wait</span><strong><?= $estimatedTime ?>m</strong></div></div>
    </div>

    <section class="panel-card">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <p class="eyebrow mb-1">Service Windows</p>
                <h2 class="mb-0">Serving and Next Per Service</h2>
            </div>
            <?php if (isLoggedIn() && ($role === 'admin' || $role === 'operator')): ?>
                <a href="admin_dashboard.php" class="btn btn-brand">Open Control</a>
            <?php endif; ?>
        </div>

        <div class="service-window-grid dashboard-window-grid">
            <?php if (count($serviceWindows) > 0): ?>
                <?php foreach ($serviceWindows as $window): ?>
                    <article class="service-window-card">
                        <div class="service-window-head">
                            <div>
                                <span>Window</span>
                                <h3><?= e($window['service_name']) ?></h3>
                            </div>
                            <strong><?= e($window['waiting_total']) ?></strong>
                        </div>
                        <div class="window-slots">
                            <div>
                                <span>Serving</span>
                                <strong><?= $window['serving'] ? e(queueTicket($window['serving'])) : '---' ?></strong>
                                <small><?= $window['serving'] ? e($window['serving']['name']) : 'No active ticket' ?></small>
                            </div>
                            <div>
                                <span>Next</span>
                                <strong><?= $window['next'] ? e(queueTicket($window['next'])) : '---' ?></strong>
                                <small><?= $window['next'] ? e(priorityLabel($window['next']['priority_type'])) : 'No waiting ticket' ?></small>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted mb-0">No active services yet.</p>
            <?php endif; ?>
        </div>
    </section>
</main>
</body>
</html>



