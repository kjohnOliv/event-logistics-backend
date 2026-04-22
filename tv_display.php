<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';
include 'functions.php';

date_default_timezone_set('Asia/Manila');

$serving = getCurrentServing($conn);
$priorityServing = getCurrentServingByPriority($conn, 'priority');
$regularServing = getCurrentServingByPriority($conn, 'regular');
$nextWaiting = getNextWaiting($conn);
$nextPriority = getNextWaitingByPriority($conn, 'priority');
$priorityWaiting = getWaitingQueuesByPriority($conn, 'priority', 6);
$regularWaiting = getWaitingQueuesByPriority($conn, 'regular', 6);
$serviceWindows = getServiceWindows($conn);
$waitingCount = getWaitingCount($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartQueue TV Display</title>
    <meta http-equiv="refresh" content="5">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="tv-body">
    <main class="tv-shell">
        <header class="tv-header">
            <div>
                <p class="eyebrow">SmartQueue Live</p>
                <h1>Now Serving</h1>
                <span class="tv-version"><?= e(SMARTQUEUE_VERSION) ?></span>
            </div>
            <div class="tv-time-panel" aria-label="Current date and time">
                <div class="tv-date" id="tvDate"><?= e(date('l, F j, Y')) ?></div>
                <div class="tv-clock" id="tvClock"><?= e(date('h:i:s A')) ?></div>
            </div>
        </header>

        <section class="tv-board-grid tv-board-grid-priority">
            <div class="tv-serving-card tv-priority-serving">
                <?php if ($priorityServing): ?>
                    <span class="tv-label">Priority Serving</span>
                    <strong><?= e(queueTicket($priorityServing)) ?></strong>
                    <h2><?= e($priorityServing['name']) ?></h2>
                    <p><?= e($priorityServing['service_name'] ?? 'Service') ?> / Fast priority lane</p>
                <?php else: ?>
                    <span class="tv-label">Priority Serving</span>
                    <strong>---</strong>
                    <h2>No priority serving</h2>
                    <p>Priority customers will appear here when called.</p>
                <?php endif; ?>
            </div>

            <div class="tv-serving-card">
                <?php if ($regularServing): ?>
                    <span class="tv-label">Regular Serving</span>
                    <strong><?= e(queueTicket($regularServing)) ?></strong>
                    <h2><?= e($regularServing['name']) ?></h2>
                    <p><?= e($regularServing['service_name'] ?? 'Service') ?> / Regular lane</p>
                <?php else: ?>
                    <span class="tv-label">Regular Serving</span>
                    <strong>---</strong>
                    <h2>No regular serving</h2>
                    <p>Regular tickets will appear here when called.</p>
                <?php endif; ?>
            </div>

            <div class="tv-next-card">
                <span class="tv-label">Next Priority</span>
                <?php if ($nextPriority): ?>
                    <strong><?= e(queueTicket($nextPriority)) ?></strong>
                    <h2><?= e($nextPriority['name']) ?></h2>
                    <p><?= e($nextPriority['service_name'] ?? 'Service') ?></p>
                    <div class="tv-priority-badge hot">Priority Express</div>
                <?php else: ?>
                    <strong>---</strong>
                    <h2>No priority waiting</h2>
                    <p>Total waiting: <?= e($waitingCount) ?></p>
                <?php endif; ?>
            </div>
        </section>

        <section class="tv-window-strip">
            <?php if (count($serviceWindows) > 0): ?>
                <?php foreach ($serviceWindows as $window): ?>
                    <article class="tv-window-card">
                        <h3><?= e($window['service_name']) ?></h3>
                        <div class="tv-window-pair">
                            <div>
                                <span>Serving</span>
                                <strong><?= $window['serving'] ? e(queueTicket($window['serving'])) : '---' ?></strong>
                            </div>
                            <div>
                                <span>Next</span>
                                <strong><?= $window['next'] ? e(queueTicket($window['next'])) : '---' ?></strong>
                            </div>
                        </div>
                        <small><?= e($window['waiting_total']) ?> waiting</small>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <section class="tv-lanes-grid">
            <div class="tv-waiting-card tv-priority-lane">
                <div class="tv-section-title">
                    <h2>Priority Waiting</h2>
                    <span><?= count($priorityWaiting) ?> shown</span>
                </div>
                <?php if (count($priorityWaiting) > 0): ?>
                    <div class="tv-list">
                        <?php foreach ($priorityWaiting as $row): ?>
                            <div class="tv-list-row">
                                <strong><?= e(queueTicket($row)) ?></strong>
                                <span><?= e($row['name']) ?></span>
                                <small><?= e($row['service_name'] ?? 'Service') ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="tv-empty small">No priority queue</div>
                <?php endif; ?>
            </div>

            <div class="tv-waiting-card tv-regular-lane">
                <div class="tv-section-title">
                    <h2>Regular Waiting</h2>
                    <span><?= count($regularWaiting) ?> shown</span>
                </div>
                <?php if (count($regularWaiting) > 0): ?>
                    <div class="tv-list">
                        <?php foreach ($regularWaiting as $row): ?>
                            <div class="tv-list-row">
                                <strong><?= e(queueTicket($row)) ?></strong>
                                <span><?= e($row['name']) ?></span>
                                <small><?= e($row['service_name'] ?? 'Service') ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="tv-empty small">No regular queue</div>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <script>
        (function () {
            const clock = document.getElementById('tvClock');
            const date = document.getElementById('tvDate');
            const timezone = 'Asia/Manila';

            function updateTvTime() {
                const now = new Date();
                clock.textContent = new Intl.DateTimeFormat('en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: true,
                    timeZone: timezone
                }).format(now);
                date.textContent = new Intl.DateTimeFormat('en-US', {
                    weekday: 'long',
                    month: 'long',
                    day: 'numeric',
                    year: 'numeric',
                    timeZone: timezone
                }).format(now);
            }

            updateTvTime();
            setInterval(updateTvTime, 1000);
        })();
    </script>
</body>
</html>



