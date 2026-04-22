<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';
include 'functions.php';

$queueUserId = null;
$message = '';
$messageType = 'info';
$issuedTicket = null;
$operatingStatus = getQueueOperatingStatus($conn);

$servicesQuery = $conn->query("SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $priorityType = $_POST['priority_type'] ?? 'regular';
    $customerName = clean($_POST['customer_name'] ?? '');

    if (!$operatingStatus['is_open']) {
        $message = $operatingStatus['message'];
        $messageType = 'warning';
    } elseif ($customerName === '') {
        $customerName = 'Walk-in Customer';
    }

    if ($message === '' && $serviceId <= 0) {
        $message = 'Please select a service.';
        $messageType = 'danger';
    } elseif ($message === '' && !in_array($priorityType, ['regular', 'priority'], true)) {
        $message = 'Invalid priority type.';
        $messageType = 'danger';
    } elseif ($message === '') {
        $serviceStmt = $conn->prepare("SELECT service_name FROM services WHERE id = ? AND status = 'active' LIMIT 1");
        $serviceStmt->bind_param('i', $serviceId);
        $serviceStmt->execute();
        $service = $serviceStmt->get_result()->fetch_assoc();

        if (!$service) {
            $message = 'Selected service is not available.';
            $messageType = 'danger';
        } else {
            $conn->begin_transaction();

            try {
                $nextQueueNumber = getNextQueueNumber($conn, $priorityType);
                $status = 'waiting';

                $stmt = $conn->prepare("INSERT INTO queue (user_id, service_id, name, queue_number, priority_type, status, created_at, archived_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NULL)");
                $stmt->bind_param('iisiss', $queueUserId, $serviceId, $customerName, $nextQueueNumber, $priorityType, $status);
                $stmt->execute();
                $conn->commit();

                $ticket = formatQueueNumber($nextQueueNumber, $priorityType);
                logAction($conn, isLoggedIn() ? (int) $_SESSION['user_id'] : null, 'Public Queue Created', $customerName . ' received queue ' . $ticket . ' from the public kiosk.');
                $issuedTicket = [
                    'queue_number' => $nextQueueNumber,
                    'priority_type' => $priorityType,
                    'name' => $customerName,
                    'service_name' => $service['service_name'],
                    'status' => $status,
                ];
                $message = 'Queue ticket generated successfully.';
                $messageType = 'success';
            } catch (Throwable $e) {
                $conn->rollback();
                $message = 'Could not create queue number: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    $servicesQuery = $conn->query("SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Get Queue Number - SmartQueue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="kiosk-page">
<nav class="navbar app-nav px-3">
    <a class="navbar-brand" href="index.php">SmartQueue</a>
    <div class="ms-auto nav-user">
        <span class="version-badge"><?= e(SMARTQUEUE_VERSION) ?></span>
        <?php if (isLoggedIn()): ?>
            <?= e($_SESSION['full_name']) ?> <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="login.php">Admin/User Login</a>
        <?php endif; ?>
    </div>
</nav>

<main class="container-fluid kiosk-shell py-4 px-lg-5">
    <section class="kiosk-hero panel-card">
        <div>
            <p class="eyebrow">Public Ticket Kiosk</p>
            <h1>Pick a service. Get your number. Wait comfortably.</h1>
            <p class="text-muted mb-0">Ticketing opens at <?= e($operatingStatus['open_label']) ?>, cuts off at <?= e($operatingStatus['cutoff_label']) ?>, and resets at <?= e($operatingStatus['reset_label']) ?>.</p>
        </div>
    </section>

    <div class="row g-4 mt-1 align-items-stretch">
        <div class="col-xl-7">
            <section class="panel-card h-100">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <p class="eyebrow">Ticket Desk</p>
                        <h2 class="mb-0">Get Queue Number</h2>
                    </div>
                    <a href="index.php" class="btn btn-ghost">View Queue Board</a>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
                <?php endif; ?>
                <div class="alert alert-<?= $operatingStatus['is_open'] ? 'success' : 'warning' ?>">
                    <?= e($operatingStatus['message']) ?>
                </div>

                <form method="POST" class="kiosk-form">
                    <div>
                        <label class="form-label">Customer Name</label>
                        <input type="text" name="customer_name" class="form-control form-control-lg" placeholder="Type customer name or leave blank for Walk-in Customer" value="<?= e($_POST['customer_name'] ?? '') ?>">
                    </div>

                    <div>
                        <label class="form-label">Select Service</label>
                        <select name="service_id" class="form-select form-select-lg" required>
                            <option value="">Choose a service</option>
                            <?php if ($servicesQuery && $servicesQuery->num_rows > 0): ?>
                                <?php while ($service = $servicesQuery->fetch_assoc()): ?>
                                    <option value="<?= e($service['id']) ?>" <?= ((int) ($_POST['service_id'] ?? 0) === (int) $service['id']) ? 'selected' : '' ?>><?= e($service['service_name']) ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Queue Lane</label>
                        <div class="ticket-type-grid">
                            <label class="ticket-type-card">
                                <input type="radio" name="priority_type" value="regular" <?= (($_POST['priority_type'] ?? 'regular') === 'regular') ? 'checked' : '' ?>>
                                <span>Regular</span>
                                <strong>R-01 to R-99</strong>
                                <small>Standard customer queue</small>
                            </label>
                            <label class="ticket-type-card priority">
                                <input type="radio" name="priority_type" value="priority" <?= (($_POST['priority_type'] ?? '') === 'priority') ? 'checked' : '' ?>>
                                <span>Priority</span>
                                <strong>P-01 to P-99</strong>
                                <small>Senior, PWD, pregnant, urgent assistance</small>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-brand btn-lg w-100" <?= $operatingStatus['is_open'] ? '' : 'disabled' ?>>Generate Queue Number</button>
                </form>
            </section>
        </div>

        <div class="col-xl-5">
            <section class="panel-card h-100 ticket-preview-card">
                <p class="eyebrow">Latest Ticket</p>
                <?php if ($issuedTicket): ?>
                    <div class="ticket-large mb-3">
                        <span><?= e(priorityLabel($issuedTicket['priority_type'])) ?> Ticket</span>
                        <strong><?= e(queueTicket($issuedTicket)) ?></strong>
                        <small><?= e($issuedTicket['service_name']) ?></small>
                    </div>
                    <h3><?= e($issuedTicket['name']) ?></h3>
                    <p class="text-muted">Please wait until your number appears on the TV display.</p>
                <?php else: ?>
                    <div class="ticket-placeholder">
                        <strong>R-01 / P-01</strong>
                        <span>Your generated ticket will appear here.</span>
                    </div>
                    <p class="text-muted mb-0">Regular and Priority each have their own 1-99 sequence.</p>
                <?php endif; ?>
            </section>
        </div>
    </div>
</main>
</body>
</html>
