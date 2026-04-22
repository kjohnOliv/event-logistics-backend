<?php
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

const SMARTQUEUE_VERSION = '2026.04.15-cutoff-reset-v12';
const SMARTQUEUE_DEFAULT_OPEN_TIME = '06:30:00';
const SMARTQUEUE_DEFAULT_CUTOFF_TIME = '17:00:00';
const SMARTQUEUE_DEFAULT_RESET_TIME = '18:00:00';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function clean($data) {
    return trim((string) $data);
}

function asset($path) {
    return $path . '?v=' . rawurlencode(SMARTQUEUE_VERSION);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

function hasRole($roles) {
    $roles = (array) $roles;
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true);
}

function requireRole($roles) {
    requireLogin();

    if (!hasRole($roles)) {
        header('Location: index.php');
        exit();
    }
}

function isAdmin() {
    return hasRole('admin');
}

function isOperator() {
    return hasRole('operator');
}

function redirectByRole() {
    $role = $_SESSION['role'] ?? 'user';

    if ($role === 'admin' || $role === 'operator') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: user_dashboard.php');
    }

    exit();
}

function flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function consumeFlash() {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function logAction($conn, $user_id, $action, $description) {
    $stmt = $conn->prepare('INSERT INTO audit_logs (user_id, action, description) VALUES (?, ?, ?)');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('iss', $user_id, $action, $description);
    return $stmt->execute();
}

function settingsColumnExists($conn, $column) {
    $stmt = $conn->prepare("SHOW COLUMNS FROM settings LIKE ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $column);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function ensureDailyResetSchema($conn) {
    $conn->query("INSERT IGNORE INTO settings (id, avg_service_time, last_regular_number, last_priority_number) VALUES (1, 5, 0, 0)");

    if (!settingsColumnExists($conn, 'queue_open_time')) {
        $conn->query("ALTER TABLE settings ADD COLUMN queue_open_time TIME NOT NULL DEFAULT '06:30:00' AFTER last_priority_number");
    }

    if (!settingsColumnExists($conn, 'queue_cutoff_time')) {
        $conn->query("ALTER TABLE settings ADD COLUMN queue_cutoff_time TIME NOT NULL DEFAULT '17:00:00' AFTER queue_open_time");
    }

    if (!settingsColumnExists($conn, 'daily_reset_time')) {
        $conn->query("ALTER TABLE settings ADD COLUMN daily_reset_time TIME NOT NULL DEFAULT '18:00:00' AFTER queue_cutoff_time");
    }

    if (!settingsColumnExists($conn, 'last_daily_reset_date')) {
        $conn->query("ALTER TABLE settings ADD COLUMN last_daily_reset_date DATE NULL AFTER daily_reset_time");
    }
}

function getQueueResetInfo($conn) {
    ensureDailyResetSchema($conn);

    $result = $conn->query("SELECT queue_open_time, queue_cutoff_time, daily_reset_time, last_daily_reset_date FROM settings WHERE id = 1 LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        return [
            'queue_open_time' => SMARTQUEUE_DEFAULT_OPEN_TIME,
            'queue_cutoff_time' => SMARTQUEUE_DEFAULT_CUTOFF_TIME,
            'daily_reset_time' => SMARTQUEUE_DEFAULT_RESET_TIME,
            'last_daily_reset_date' => null,
        ];
    }

    $row = $result->fetch_assoc();
    return [
        'queue_open_time' => $row['queue_open_time'] ?: SMARTQUEUE_DEFAULT_OPEN_TIME,
        'queue_cutoff_time' => $row['queue_cutoff_time'] ?: SMARTQUEUE_DEFAULT_CUTOFF_TIME,
        'daily_reset_time' => $row['daily_reset_time'] ?: SMARTQUEUE_DEFAULT_RESET_TIME,
        'last_daily_reset_date' => $row['last_daily_reset_date'] ?? null,
    ];
}

function getTodayTime($time, $timezone = null) {
    $timezone = $timezone ?: new DateTimeZone('Asia/Manila');
    $today = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . $time, $timezone);

    if (!$parsed) {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' 00:00:00', $timezone);
    }

    return $parsed;
}

function getQueueOperatingStatus($conn) {
    $schedule = getQueueResetInfo($conn);
    $timezone = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $timezone);
    $openAt = getTodayTime($schedule['queue_open_time'], $timezone);
    $cutoffAt = getTodayTime($schedule['queue_cutoff_time'], $timezone);
    $resetAt = getTodayTime($schedule['daily_reset_time'], $timezone);
    $isOpen = $now >= $openAt && $now < $cutoffAt;

    if ($isOpen) {
        $message = 'Queue ticketing is open until ' . $cutoffAt->format('g:i A') . '.';
    } elseif ($now < $openAt) {
        $message = 'Queue ticketing opens today at ' . $openAt->format('g:i A') . '.';
    } elseif ($now < $resetAt) {
        $message = 'Queue ticketing cut off at ' . $cutoffAt->format('g:i A') . '. Serving can continue, but new tickets open tomorrow at ' . $openAt->format('g:i A') . '.';
    } else {
        $message = 'Queue ticketing is closed. The system resets at ' . $resetAt->format('g:i A') . ' and opens tomorrow at ' . $openAt->format('g:i A') . '.';
    }

    return [
        'is_open' => $isOpen,
        'message' => $message,
        'open_time' => $schedule['queue_open_time'],
        'cutoff_time' => $schedule['queue_cutoff_time'],
        'reset_time' => $schedule['daily_reset_time'],
        'open_label' => $openAt->format('g:i A'),
        'cutoff_label' => $cutoffAt->format('g:i A'),
        'reset_label' => $resetAt->format('g:i A'),
    ];
}

function resetQueueSystem($conn, $reason = 'Manual Reset') {
    ensureDailyResetSchema($conn);

    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $conn->begin_transaction();

    try {
        $conn->query("UPDATE queue SET status = IF(status IN ('waiting', 'serving'), 'cancelled', status), completed_at = COALESCE(completed_at, NOW()), archived_at = NOW() WHERE archived_at IS NULL");
        $archivedRows = $conn->affected_rows;
        $stmt = $conn->prepare("UPDATE settings SET last_regular_number = 0, last_priority_number = 0, last_daily_reset_date = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1");
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $conn->commit();

        return max(0, (int) $archivedRows);
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function autoResetQueueIfDue($conn) {
    $resetInfo = getQueueResetInfo($conn);
    $timezone = new DateTimeZone('Asia/Manila');
    $now = new DateTimeImmutable('now', $timezone);
    $today = $now->format('Y-m-d');
    $resetTime = $resetInfo['daily_reset_time'] ?: SMARTQUEUE_DEFAULT_RESET_TIME;
    $resetAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . $resetTime, $timezone);

    if (!$resetAt) {
        $resetAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $today . ' ' . SMARTQUEUE_DEFAULT_RESET_TIME, $timezone);
    }

    if ($now >= $resetAt && $resetInfo['last_daily_reset_date'] !== $today) {
        $archivedRows = resetQueueSystem($conn, 'Daily 5 PM Reset');
        logAction($conn, null, 'Daily Queue Reset', 'Automatic 6 PM queue reset archived ' . $archivedRows . ' active records and restarted numbers for tomorrow at R-01/P-01.');
    }
}

function getSetting($conn, $key, $default = null) {
    $allowed = ['avg_service_time', 'queue_open_time', 'queue_cutoff_time', 'daily_reset_time', 'last_daily_reset_date'];
    if (!in_array($key, $allowed, true)) {
        return $default;
    }

    $result = $conn->query("SELECT {$key} FROM settings WHERE id = 1 LIMIT 1");
    if (!$result || $result->num_rows === 0) {
        return $default;
    }

    $row = $result->fetch_assoc();
    return $row[$key] ?? $default;
}

function getCurrentServing($conn) {
    $result = $conn->query("SELECT q.*, s.service_name FROM queue q LEFT JOIN services s ON q.service_id = s.id WHERE q.status = 'serving' AND q.archived_at IS NULL ORDER BY q.served_at ASC, q.id ASC LIMIT 1");
    return $result ? $result->fetch_assoc() : null;
}

function getCurrentServingByPriority($conn, $priorityType) {
    $stmt = $conn->prepare("SELECT q.*, s.service_name FROM queue q LEFT JOIN services s ON q.service_id = s.id WHERE q.status = 'serving' AND q.archived_at IS NULL AND q.priority_type = ? ORDER BY q.served_at ASC, q.id ASC LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $priorityType);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getWaitingCount($conn) {
    $result = $conn->query("SELECT COUNT(*) AS total FROM queue WHERE status = 'waiting' AND archived_at IS NULL");
    $row = $result ? $result->fetch_assoc() : ['total' => 0];
    return (int) ($row['total'] ?? 0);
}

function getNextWaiting($conn, $serviceId = null) {
    if ($serviceId !== null) {
        $stmt = $conn->prepare("SELECT q.*, s.service_name FROM queue q LEFT JOIN services s ON q.service_id = s.id WHERE q.status = 'waiting' AND q.archived_at IS NULL AND q.service_id = ? ORDER BY q.priority_type = 'priority' DESC, q.id ASC LIMIT 1");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $serviceId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    $result = $conn->query("SELECT q.*, s.service_name FROM queue q LEFT JOIN services s ON q.service_id = s.id WHERE q.status = 'waiting' AND q.archived_at IS NULL ORDER BY q.priority_type = 'priority' DESC, q.id ASC LIMIT 1");
    return $result ? $result->fetch_assoc() : null;
}

function getNextWaitingByPriority($conn, $priorityType) {
    $stmt = $conn->prepare("SELECT q.*, s.service_name FROM queue q LEFT JOIN services s ON q.service_id = s.id WHERE q.status = 'waiting' AND q.archived_at IS NULL AND q.priority_type = ? ORDER BY q.id ASC LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $priorityType);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getWaitingQueuesByPriority($conn, $priorityType, $limit = 50) {
    $limit = max(1, min(100, (int) $limit));
    $stmt = $conn->prepare("SELECT q.*, s.service_name FROM queue q LEFT JOIN services s ON q.service_id = s.id WHERE q.status = 'waiting' AND q.archived_at IS NULL AND q.priority_type = ? ORDER BY q.id ASC LIMIT {$limit}");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('s', $priorityType);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getServiceWindows($conn) {
    $services = [];
    $result = $conn->query("SELECT id, service_name, status FROM services WHERE status = 'active' ORDER BY service_name ASC");

    if (!$result) {
        return [];
    }

    while ($service = $result->fetch_assoc()) {
        $serviceId = (int) $service['id'];
        $servingStmt = $conn->prepare("SELECT q.* FROM queue q WHERE q.service_id = ? AND q.status = 'serving' AND q.archived_at IS NULL ORDER BY q.served_at ASC, q.id ASC LIMIT 1");
        $waitingStmt = $conn->prepare("SELECT COUNT(*) AS total FROM queue WHERE service_id = ? AND status = 'waiting' AND archived_at IS NULL");
        $next = getNextWaiting($conn, $serviceId);

        $serving = null;
        $waitingTotal = 0;

        if ($servingStmt) {
            $servingStmt->bind_param('i', $serviceId);
            $servingStmt->execute();
            $serving = $servingStmt->get_result()->fetch_assoc();
        }

        if ($waitingStmt) {
            $waitingStmt->bind_param('i', $serviceId);
            $waitingStmt->execute();
            $row = $waitingStmt->get_result()->fetch_assoc();
            $waitingTotal = (int) ($row['total'] ?? 0);
        }

        $services[] = [
            'id' => $serviceId,
            'service_name' => $service['service_name'],
            'serving' => $serving,
            'next' => $next,
            'waiting_total' => $waitingTotal,
        ];
    }

    return $services;
}

function getEstimatedTime($conn) {
    $avg = (int) getSetting($conn, 'avg_service_time', 5);
    return getWaitingCount($conn) * max(1, $avg);
}

function getQueueCounts($conn) {
    $counts = ['waiting' => 0, 'serving' => 0, 'done' => 0, 'cancelled' => 0];
    $result = $conn->query("SELECT status, COUNT(*) AS total FROM queue WHERE archived_at IS NULL GROUP BY status");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $counts[$row['status']] = (int) $row['total'];
        }
    }

    return $counts;
}

function getUserActiveQueue($conn, $userId) {
    $stmt = $conn->prepare("SELECT q.*, s.service_name FROM queue q LEFT JOIN services s ON q.service_id = s.id WHERE q.user_id = ? AND q.status IN ('waiting', 'serving') AND q.archived_at IS NULL ORDER BY q.id DESC LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getUserRecentQueues($conn, $userId, $limit = 5) {
    $limit = max(1, min(20, (int) $limit));
    $stmt = $conn->prepare("SELECT q.*, s.service_name FROM queue q LEFT JOIN services s ON q.service_id = s.id WHERE q.user_id = ? ORDER BY q.id DESC LIMIT {$limit}");
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function activeQueueNumberExists($conn, $priorityType, $queueNumber) {
    $stmt = $conn->prepare("SELECT id FROM queue WHERE priority_type = ? AND queue_number = ? AND status IN ('waiting', 'serving') AND archived_at IS NULL LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('si', $priorityType, $queueNumber);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

function getNextQueueNumber($conn, $priorityType) {
    $column = $priorityType === 'priority' ? 'last_priority_number' : 'last_regular_number';
    $lastNumber = 0;
    $hasSequenceColumn = false;

    $result = $conn->query("SELECT {$column} FROM settings WHERE id = 1 FOR UPDATE");
    if ($result) {
        $row = $result->fetch_assoc() ?: [$column => 0];
        $lastNumber = (int) ($row[$column] ?? 0);
        $hasSequenceColumn = true;
    } else {
        $fallback = $conn->prepare("SELECT COALESCE(MAX(queue_number), 0) AS last_number FROM queue WHERE priority_type = ?");
        if ($fallback) {
            $fallback->bind_param('s', $priorityType);
            $fallback->execute();
            $row = $fallback->get_result()->fetch_assoc();
            $lastNumber = (int) ($row['last_number'] ?? 0);
        }
    }

    for ($attempt = 1; $attempt <= 99; $attempt++) {
        $nextNumber = ($lastNumber % 99) + 1;
        if (!activeQueueNumberExists($conn, $priorityType, $nextNumber)) {
            if ($hasSequenceColumn) {
                $conn->query("UPDATE settings SET {$column} = {$nextNumber} WHERE id = 1");
            }

            return $nextNumber;
        }

        $lastNumber = $nextNumber;
    }

    throw new RuntimeException('All 99 ' . priorityLabel($priorityType) . ' queue numbers are currently active.');
}

function statusBadgeClass($status) {
    $map = [
        'waiting' => 'badge-waiting',
        'serving' => 'badge-serving',
        'done' => 'badge-done',
        'cancelled' => 'badge-cancelled',
    ];

    return $map[$status] ?? 'badge-muted';
}

function priorityLabel($priority) {
    return $priority === 'priority' ? 'Priority' : 'Regular';
}

function formatQueueNumber($number, $priorityType = null) {
    $prefix = '';
    if ($priorityType === 'priority') {
        $prefix = 'P-';
    } elseif ($priorityType === 'regular') {
        $prefix = 'R-';
    }

    return $prefix . str_pad((string) $number, 2, '0', STR_PAD_LEFT);
}

function queueTicket($queue) {
    if (!$queue) {
        return '---';
    }

    return formatQueueNumber($queue['queue_number'] ?? 0, $queue['priority_type'] ?? null);
}

if (isset($conn) && $conn instanceof mysqli) {
    autoResetQueueIfDue($conn);
}


