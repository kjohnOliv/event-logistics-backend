<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';
include 'functions.php';

if (isLoggedIn()) {
    redirectByRole();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $message = 'Please enter your email and password.';
    } else {
        $stmt = $conn->prepare('SELECT id, full_name, email, password, role, status, is_verified FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ((int) $user['is_verified'] !== 1) {
                $message = 'Please verify your email before logging in.';
            } elseif ($user['status'] !== 'active') {
                $message = 'Your account is inactive. Please contact an administrator.';
            } elseif (password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                logAction($conn, (int) $user['id'], 'User Login', $user['full_name'] . ' logged in.');
                redirectByRole();
            } else {
                $message = 'Incorrect email or password.';
            }
        } else {
            $message = 'Incorrect email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In - SmartQueue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-hero">
            <p class="eyebrow">SmartQueue</p>
            <h1>Move lines faster, calmer, and with fewer surprises.</h1>
            <p>Track waiting clients, call the next number, and keep your lobby display updated in real time.</p>
        </section>

        <section class="auth-card panel-card">
            <h2>Welcome back</h2>
            <p class="text-muted mb-4">Log in to continue to your queue dashboard.</p>

            <?php if ($message !== ''): ?>
                <div class="alert alert-danger"><?= e($message) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control form-control-lg" required value="<?= e($_POST['email'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control form-control-lg" required>
                </div>

                <button type="submit" class="btn btn-brand w-100 btn-lg">Log In</button>
            </form>

            <p class="text-center mt-4 mb-0">
                No account yet? <a href="signup.php">Create one</a>
            </p>
        </section>
    </main>
</body>
</html>

