<?php
include 'config.php';
include 'functions.php';

$message = 'Invalid verification request.';
$messageType = 'danger';

if (isset($_GET['token'])) {
    $token = clean($_GET['token']);

    $stmt = $conn->prepare('SELECT id FROM users WHERE verification_token = ? AND is_verified = 0 LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        $userId = (int) $user['id'];

        $update = $conn->prepare('UPDATE users SET is_verified = 1, verification_token = NULL WHERE id = ?');
        $update->bind_param('i', $userId);

        if ($update->execute()) {
            $message = 'Your email has been verified successfully. You may now log in.';
            $messageType = 'success';
            logAction($conn, $userId, 'Email Verified', 'User verified their email address.');
        } else {
            $message = 'Verification failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - SmartQueue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="auth-page">
    <main class="auth-shell single">
        <section class="auth-card panel-card text-center">
            <p class="eyebrow">SmartQueue</p>
            <h1>Email Verification</h1>
            <div class="alert alert-<?= e($messageType) ?> mt-3"><?= e($message) ?></div>
            <a href="login.php" class="btn btn-brand">Go to Login</a>
        </section>
    </main>
</body>
</html>

