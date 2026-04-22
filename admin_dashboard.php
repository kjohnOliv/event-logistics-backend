<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';
include 'functions.php';

requireRole(['admin', 'operator']);

$message = '';
$messageType = 'info';
$userId = (int) $_SESSION['user_id'];
$isAdminUser = isAdmin();
$flash = consumeFlash();
if ($flash) {
    $message = $flash['message'];
    $messageType = $flash['type'];
}

function finishAdminAction($type, $message, $anchor = 'queue-workspace') {
    $allowedAnchors = ['queue-workspace', 'priority-workspace', 'regular-workspace', 'service-windows', 'waiting-lanes', 'admin-settings'];
    if (!in_array($anchor, $allowedAnchors, true)) {
        $anchor = 'queue-workspace';
    }

    flash($type, $message);
    header('Location: admin_dashboard.php#' . $anchor);
    exit();
}

function callQueue($conn, $queueId, $operatorId) {
    $stmt = $conn->prepare("UPDATE queue SET status = 'serving', served_at = NOW(), assigned_operator_id = ? WHERE id = ? AND status = 'waiting' AND archived_at IS NULL");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ii', $operatorId, $queueId);
    $stmt->execute();
    return $stmt->affected_rows > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'call_priority') {
        $priorityServing = getCurrentServingByPriority($conn, 'priority');

        if ($priorityServing) {
            finishAdminAction('warning', 'A priority queue is already being served. Mark it done before calling the next priority ticket.', 'priority-workspace');
        } else {
            $nextPriority = getNextWaitingByPriority($conn, 'priority');

            if ($nextPriority && callQueue($conn, (int) $nextPriority['id'], $userId)) {
                logAction($conn, $userId, 'Call Priority', 'Called priority queue ID ' . $nextPriority['id'] . '.');
                finishAdminAction('success', 'Next priority queue has been called to the express lane.', 'priority-workspace');
            } else {
                finishAdminAction('info', 'No priority queue is waiting.', 'priority-workspace');
            }
        }
    }

    if ($action === 'mark_priority_done') {
        $priorityServing = getCurrentServingByPriority($conn, 'priority');

        if ($priorityServing) {
            $queueId = (int) $priorityServing['id'];
            $stmt = $conn->prepare("UPDATE queue SET status = 'done', completed_at = NOW() WHERE id = ? AND status = 'serving' AND priority_type = 'priority' AND archived_at IS NULL");
            $stmt->bind_param('i', $queueId);
            $stmt->execute();
            logAction($conn, $userId, 'Mark Priority Done', 'Marked priority queue ID ' . $queueId . ' as done.');
            finishAdminAction($stmt->affected_rows > 0 ? 'success' : 'warning', $stmt->affected_rows > 0 ? 'Priority serving queue marked as done.' : 'Priority queue could not be completed.', 'priority-workspace');
        } else {
            finishAdminAction('info', 'No priority queue is currently serving.', 'priority-workspace');
        }
    }

    if ($action === 'call_next') {
        $next = $conn->query("SELECT q.id FROM queue q WHERE q.status = 'waiting' AND q.priority_type = 'regular' AND q.archived_at IS NULL AND NOT EXISTS (SELECT 1 FROM queue active WHERE active.service_id = q.service_id AND active.status = 'serving' AND active.priority_type = 'regular' AND active.archived_at IS NULL) ORDER BY q.id ASC LIMIT 1");

        if ($next && $next->num_rows > 0) {
            $row = $next->fetch_assoc();
            if (callQueue($conn, (int) $row['id'], $userId)) {
                logAction($conn, $userId, 'Call Next', 'Called next available queue ID ' . $row['id'] . '.');
                finishAdminAction('success', 'Next available regular queue has been called.', 'regular-workspace');
            } else {
                finishAdminAction('warning', 'Queue could not be called. Please refresh and try again.', 'regular-workspace');
            }
        } else {
            finishAdminAction('info', 'No regular waiting queue can be called. Every active regular service may already be serving someone.', 'regular-workspace');
        }
    }

    if ($action === 'call_next_service') {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $servingStmt = $conn->prepare("SELECT id FROM queue WHERE service_id = ? AND status = 'serving' AND archived_at IS NULL LIMIT 1");
        $servingStmt->bind_param('i', $serviceId);
        $servingStmt->execute();
        $serviceServing = $servingStmt->get_result()->fetch_assoc();

        if ($serviceServing) {
            finishAdminAction('warning', 'This service window is already serving a queue.', 'service-windows');
        } else {
            $nextStmt = $conn->prepare("SELECT q.*, s.service_name FROM queue q LEFT JOIN services s ON q.service_id = s.id WHERE q.service_id = ? AND q.status = 'waiting' AND q.priority_type = 'regular' AND q.archived_at IS NULL ORDER BY q.id ASC LIMIT 1");
            $nextStmt->bind_param('i', $serviceId);
            $nextStmt->execute();
            $next = $nextStmt->get_result()->fetch_assoc();
            if ($next && callQueue($conn, (int) $next['id'], $userId)) {
                logAction($conn, $userId, 'Call Service Window', 'Called queue ID ' . $next['id'] . ' for service ID ' . $serviceId . '.');
                finishAdminAction('success', 'Next regular queue for this service has been called.', 'service-windows');
            } else {
                finishAdminAction('info', 'No regular waiting queue for this service. Use Priority Express for priority tickets.', 'service-windows');
            }
        }
    }

    if ($action === 'mark_done') {
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $returnAnchor = clean($_POST['return_anchor'] ?? 'regular-workspace');

        if ($queueId > 0) {
            $stmt = $conn->prepare("UPDATE queue SET status = 'done', completed_at = NOW() WHERE id = ? AND status = 'serving' AND archived_at IS NULL");
            $stmt->bind_param('i', $queueId);
        } else {
            $stmt = $conn->prepare("UPDATE queue SET status = 'done', completed_at = NOW() WHERE status = 'serving' AND priority_type = 'regular' AND archived_at IS NULL ORDER BY served_at ASC, id ASC LIMIT 1");
        }

        $stmt->execute();
        logAction($conn, $userId, 'Mark Done', 'Marked a serving queue as done.');
        finishAdminAction($stmt->affected_rows > 0 ? 'success' : 'info', $stmt->affected_rows > 0 ? 'Serving queue marked as done.' : 'No active serving queue found.', $returnAnchor);
    }

    if ($action === 'cancel_queue') {
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE queue SET status = 'cancelled', completed_at = NOW() WHERE id = ? AND status IN ('waiting', 'serving') AND archived_at IS NULL");
        $stmt->bind_param('i', $queueId);
        $stmt->execute();
        logAction($conn, $userId, 'Cancel Queue', 'Cancelled queue ID ' . $queueId . '.');
        finishAdminAction($stmt->affected_rows > 0 ? 'success' : 'warning', $stmt->affected_rows > 0 ? 'Queue cancelled.' : 'Queue could not be cancelled.', 'waiting-lanes');
    }

    if ($action === 'archive_done' && $isAdminUser) {
        $conn->query("UPDATE queue SET archived_at = NOW() WHERE status IN ('done', 'cancelled') AND archived_at IS NULL");
        logAction($conn, $userId, 'Archive Queues', 'Archived completed queue records.');
        finishAdminAction('success', 'Completed and cancelled queues were archived.', 'queue-workspace');
    }

    if ($action === 'reset_system' && $isAdminUser) {
        try {
            $archivedRows = resetQueueSystem($conn, 'Admin Manual Reset');
            logAction($conn, $userId, 'Manual Queue Reset', 'Manually reset SmartQueue and archived ' . $archivedRows . ' active records.');
            finishAdminAction('success', 'System reset complete. Queue numbers will start again at R-01 and P-01.', 'admin-settings');
        } catch (Throwable $e) {
            finishAdminAction('danger', 'System reset failed: ' . $e->getMessage(), 'admin-settings');
        }
    }

    if ($action === 'add_service' && $isAdminUser) {
        $serviceName = clean($_POST['service_name'] ?? '');
        if ($serviceName === '') {
            finishAdminAction('danger', 'Service name is required.', 'admin-settings');
        } else {
            $stmt = $conn->prepare("INSERT INTO services (service_name, status) VALUES (?, 'active')");
            $stmt->bind_param('s', $serviceName);
            $stmt->execute();
            finishAdminAction($stmt->affected_rows > 0 ? 'success' : 'warning', $stmt->affected_rows > 0 ? 'Service added.' : 'Service could not be added. It may already exist.', 'admin-settings');
        }
    }

    if ($action === 'toggle_service' && $isAdminUser) {
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE services SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
        $stmt->bind_param('i', $serviceId);
        $stmt->execute();
        finishAdminAction('success', 'Service status updated.', 'admin-settings');
    }

    if ($action === 'update_settings' && $isAdminUser) {
        $avgServiceTime = max(1, (int) ($_POST['avg_service_time'] ?? 5));
        $queueOpenTime = clean($_POST['queue_open_time'] ?? substr(SMARTQUEUE_DEFAULT_OPEN_TIME, 0, 5));
        $queueCutoffTime = clean($_POST['queue_cutoff_time'] ?? substr(SMARTQUEUE_DEFAULT_CUTOFF_TIME, 0, 5));
        $dailyResetTime = clean($_POST['daily_reset_time'] ?? SMARTQUEUE_DEFAULT_RESET_TIME);

        if (!preg_match('/^\d{2}:\d{2}$/', $queueOpenTime)) {
            $queueOpenTime = '06:30';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $queueCutoffTime)) {
            $queueCutoffTime = '17:00';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $dailyResetTime)) {
            $dailyResetTime = '18:00';
        }

        $queueOpenTime .= ':00';
        $queueCutoffTime .= ':00';
        $dailyResetTime .= ':00';
        $stmt = $conn->prepare('UPDATE settings SET avg_service_time = ?, queue_open_time = ?, queue_cutoff_time = ?, daily_reset_time = ? WHERE id = 1');
        $stmt->bind_param('isss', $avgServiceTime, $queueOpenTime, $queueCutoffTime, $dailyResetTime);
        $stmt->execute();
        finishAdminAction('success', 'Settings updated.', 'admin-settings');
    }
}

$currentServing = getCurrentServing($conn);
$priorityServing = getCurrentServingByPriority($conn, 'priority');
$regularServing = getCurrentServingByPriority($conn, 'regular');
$nextWaiting = getNextWaiting($conn);
$nextPriority = getNextWaitingByPriority($conn, 'priority');
$nextRegular = getNextWaitingByPriority($conn, 'regular');
$counts = getQueueCounts($conn);
$avgServiceTime = (int) getSetting($conn, 'avg_service_time', 5);
$resetInfo = getQueueResetInfo($conn);
$operatingStatus = getQueueOperatingStatus($conn);
$priorityQueues = getWaitingQueuesByPriority($conn, 'priority');
$regularQueues = getWaitingQueuesByPriority($conn, 'regular');
$serviceWindows = getServiceWindows($conn);
$services = $conn->query('SELECT id, service_name, status FROM services ORDER BY service_name ASC');

function renderWaitingTable($rows, $emptyMessage) {
    ?>
    <div class="table-responsive">
        <table class="table align-middle app-table queue-lane-table">
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Name</th>
                    <th>Service</th>
                    <th>Created</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($rows) > 0): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><strong><?= e(queueTicket($row)) ?></strong></td>
                            <td><?= e($row['name']) ?></td>
                            <td><?= e($row['service_name'] ?? 'N/A') ?></td>
                            <td><?= e($row['created_at']) ?></td>
                            <td>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="queue_id" value="<?= e($row['id']) ?>">
                                    <button name="action" value="cancel_queue" class="btn btn-sm btn-outline-danger">Cancel</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="5" class="text-center text-muted py-4"><?= e($emptyMessage) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queue Control - SmartQueue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body>
<nav class="navbar app-nav px-3">
    <a class="navbar-brand" href="index.php">SmartQueue</a>
    <div class="ms-auto nav-user">
        <span class="version-badge"><?= e(SMARTQUEUE_VERSION) ?></span>
        <?= e(ucfirst($_SESSION['role'])) ?>: <?= e($_SESSION['full_name']) ?>
        <a href="logout.php">Logout</a>
    </div>
</nav>

<main class="container-fluid admin-shell py-4 px-lg-5" id="queue-workspace">
    <section class="hero-panel admin-hero mb-4">
        <div>
            <p class="eyebrow">Queue Control</p>
            <h1><?= $isAdminUser ? 'Admin Dashboard' : 'Operator Dashboard' ?></h1>
            <p>Separate priority and regular queues, call by service window, and keep the TV display synchronized.</p>
        </div>
        <div class="hero-actions">
            <a href="tv_display.php" class="btn btn-ghost" target="_blank">Open TV Display</a>
            <?php if ($isAdminUser): ?><a href="reports.php" class="btn btn-brand">Reports</a><?php endif; ?>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <section class="admin-metrics-row mb-4" aria-label="Queue summary">
        <div class="metric-card"><span>Waiting</span><strong><?= $counts['waiting'] ?></strong></div>
        <div class="metric-card"><span>Serving</span><strong><?= $counts['serving'] ?></strong></div>
        <div class="metric-card"><span>Done</span><strong><?= $counts['done'] ?></strong></div>
        <div class="metric-card"><span>Cancelled</span><strong><?= $counts['cancelled'] ?></strong></div>
    </section>

    <section class="panel-card compact-control-strip mb-4" id="priority-workspace">
        <div class="compact-control-status">
            <span>Priority</span>
            <strong><?= $priorityServing ? e(queueTicket($priorityServing)) : '---' ?></strong>
            <small><?= $priorityServing ? e($priorityServing['name']) : ($nextPriority ? 'Next: ' . queueTicket($nextPriority) : 'No priority waiting') ?></small>
        </div>
        <form method="POST" class="compact-control-actions">
            <button type="submit" name="action" value="call_priority" class="btn btn-priority">Call Priority</button>
            <button type="submit" name="action" value="mark_priority_done" class="btn btn-success">Mark Priority Done</button>
            <?php if ($isAdminUser): ?>
                <button type="submit" name="action" value="archive_done" class="btn btn-outline-secondary">Archive Done/Cancelled</button>
            <?php endif; ?>
        </form>
        <div class="compact-control-status" id="regular-workspace">
            <span>Regular</span>
            <strong><?= $regularServing ? e(queueTicket($regularServing)) : '---' ?></strong>
            <small><?= $regularServing ? e($regularServing['name']) : ($nextRegular ? 'Next: ' . queueTicket($nextRegular) : 'No regular waiting') ?></small>
        </div>
        <form method="POST" class="compact-control-actions">
            <?php if ($regularServing): ?>
                <input type="hidden" name="queue_id" value="<?= e($regularServing['id']) ?>">
            <?php endif; ?>
            <input type="hidden" name="return_anchor" value="priority-workspace">
            <button type="submit" name="action" value="call_next" class="btn btn-brand">Call Next Available</button>
            <button type="submit" name="action" value="mark_done" class="btn btn-success">Mark Regular Done</button>
        </form>
    </section>

    <section class="panel-card mb-4" id="service-windows">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <p class="eyebrow mb-1">Service Windows</p>
                <h2 class="mb-0">Window Table Per Service</h2>
            </div>
            <a href="index.php" class="btn btn-ghost">Home</a>
        </div>

        <div class="service-window-grid">
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
                                <?php if ($window['serving']): ?>
                                    <strong><?= e(queueTicket($window['serving'])) ?></strong>
                                    <small><?= e($window['serving']['name']) ?></small>
                                <?php else: ?>
                                    <strong>---</strong>
                                    <small>No active ticket</small>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span>Next</span>
                                <?php if ($window['next']): ?>
                                    <strong><?= e(queueTicket($window['next'])) ?></strong>
                                    <small><?= e(priorityLabel($window['next']['priority_type'])) ?></small>
                                <?php else: ?>
                                    <strong>---</strong>
                                    <small>No waiting ticket</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form method="POST" class="window-actions">
                            <input type="hidden" name="service_id" value="<?= e($window['id']) ?>">
                            <?php if ($window['serving']): ?>
                                <input type="hidden" name="queue_id" value="<?= e($window['serving']['id']) ?>">
                                <input type="hidden" name="return_anchor" value="service-windows">
                                <button name="action" value="mark_done" class="btn btn-success w-100">Mark Done</button>
                            <?php else: ?>
                                <button name="action" value="call_next_service" class="btn btn-brand w-100">Call Next</button>
                            <?php endif; ?>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-muted mb-0">No active services yet. Add services below to create service windows.</p>
            <?php endif; ?>
        </div>
    </section>

    <div class="row g-4 align-items-stretch" id="waiting-lanes">
        <div class="col-xl-6">
            <section class="panel-card queue-lane priority-lane">
                <div class="lane-title">
                    <div>
                        <p class="eyebrow mb-1">Priority Lane</p>
                        <h2>Priority Waiting</h2>
                    </div>
                    <span><?= count($priorityQueues) ?></span>
                </div>
                <?php renderWaitingTable($priorityQueues, 'No priority customers waiting.'); ?>
            </section>
        </div>

        <div class="col-xl-6">
            <section class="panel-card queue-lane regular-lane">
                <div class="lane-title">
                    <div>
                        <p class="eyebrow mb-1">Regular Lane</p>
                        <h2>Regular Waiting</h2>
                    </div>
                    <span><?= count($regularQueues) ?></span>
                </div>
                <?php renderWaitingTable($regularQueues, 'No regular customers waiting.'); ?>
            </section>
        </div>
    </div>

    <?php if ($isAdminUser): ?>
        <div class="row g-4 mt-4" id="admin-settings">
            <div class="col-lg-7">
                <section class="panel-card">
                    <p class="eyebrow">Services</p>
                    <h2>Manage Services</h2>
                    <form method="POST" class="row g-2 mb-3">
                        <div class="col-md"><input type="text" name="service_name" class="form-control form-control-lg" placeholder="New service name" required></div>
                        <div class="col-md-auto"><button name="action" value="add_service" class="btn btn-brand btn-lg w-100">Add Service</button></div>
                    </form>
                    <div class="table-responsive">
                        <table class="table app-table align-middle">
                            <thead><tr><th>Service</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <?php if ($services && $services->num_rows > 0): ?>
                                    <?php while ($service = $services->fetch_assoc()): ?>
                                        <tr>
                                            <td><?= e($service['service_name']) ?></td>
                                            <td><span class="status-pill <?= $service['status'] === 'active' ? 'badge-done' : 'badge-muted' ?>"><?= e(ucfirst($service['status'])) ?></span></td>
                                            <td class="text-end">
                                                <form method="POST" class="m-0">
                                                    <input type="hidden" name="service_id" value="<?= e($service['id']) ?>">
                                                    <button name="action" value="toggle_service" class="btn btn-sm btn-outline-secondary">Toggle</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="text-muted">No services yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="col-lg-5">
                <section class="panel-card">
                    <p class="eyebrow">Settings</p>
                    <h2>Waiting Time</h2>
                    <form method="POST">
                        <label class="form-label">Average service time per customer (minutes)</label>
                        <input type="number" min="1" name="avg_service_time" class="form-control form-control-lg mb-3" value="<?= e($avgServiceTime) ?>">
                        <label class="form-label">Queue starts tomorrow / daily open time</label>
                        <input type="time" name="queue_open_time" class="form-control form-control-lg mb-3" value="<?= e(substr($resetInfo['queue_open_time'], 0, 5)) ?>">
                        <label class="form-label">Queue number cutoff time</label>
                        <input type="time" name="queue_cutoff_time" class="form-control form-control-lg mb-3" value="<?= e(substr($resetInfo['queue_cutoff_time'], 0, 5)) ?>">
                        <label class="form-label">Daily system reset time</label>
                        <input type="time" name="daily_reset_time" class="form-control form-control-lg mb-3" value="<?= e(substr($resetInfo['daily_reset_time'], 0, 5)) ?>">
                        <p class="text-muted small"><?= e($operatingStatus['message']) ?> Reset clears the board and restarts numbers from R-01 and P-01.</p>
                        <button name="action" value="update_settings" class="btn btn-brand w-100">Save Settings</button>
                    </form>
                </section>

                <section class="panel-card mt-4 reset-system-card">
                    <p class="eyebrow">Daily Reset</p>
                    <h2>Reset System</h2>
                    <p class="text-muted">Last reset: <?= e($resetInfo['last_daily_reset_date'] ?: 'Not yet today') ?></p>
                    <form method="POST" onsubmit="return confirm('Reset SmartQueue now? This clears the active queue board and restarts numbers at R-01 and P-01.');">
                        <button name="action" value="reset_system" class="btn btn-outline-danger w-100">Reset System Now</button>
                    </form>
                </section>
            </div>
        </div>
    <?php endif; ?>
</main>
</body>
</html>



