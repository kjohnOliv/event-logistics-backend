<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'config.php';
include 'functions.php';
include 'mailer.php';

if (isLoggedIn()) {
    redirectByRole();
}

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = clean($_POST['full_name'] ?? '');
    $email = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($full_name === '' || $email === '' || $password === '' || $confirm_password === '') {
        $message = 'Please fill in all fields.';
        $messageType = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Invalid email format.';
        $messageType = 'danger';
    } elseif (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
        $messageType = 'danger';
    } elseif ($password !== $confirm_password) {
        $message = 'Passwords do not match.';
        $messageType = 'danger';
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->bind_param('s', $email);
        $check->execute();
        $result = $check->get_result();

        if ($result->num_rows > 0) {
            $message = 'Email already exists.';
            $messageType = 'danger';
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $token = bin2hex(random_bytes(32));
            $emailConfigured = mailerIsConfigured();
            $isVerified = $emailConfigured ? 0 : 1;
            $tokenValue = $emailConfigured ? $token : null;

            $stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role, status, is_verified, verification_token) VALUES (?, ?, ?, 'user', 'active', ?, ?)");
            $stmt->bind_param('sssis', $full_name, $email, $hashed_password, $isVerified, $tokenValue);

            if ($stmt->execute()) {
                $new_user_id = $stmt->insert_id;
                logAction($conn, $new_user_id, 'User Registered', $full_name . ' created a new account.');

                if ($emailConfigured) {
                    $mailResult = sendVerificationEmail($email, $full_name, $token);
                    if ($mailResult['success']) {
                        $message = 'Registration successful. Please check your email to verify your account.';
                        $messageType = 'success';
                    } else {
                        $message = 'Registration saved, but the verification email could not be sent: ' . $mailResult['message'];
                        $messageType = 'warning';
                    }
                } else {
                    $message = 'Registration successful. Email sending is not configured, so your account was verified automatically for local testing.';
                    $messageType = 'success';
                }
            } else {
                $message = 'Registration failed: ' . $stmt->error;
                $messageType = 'danger';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - SmartQueue</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="auth-page">
    <main class="auth-shell">
        <section class="auth-hero">
            <p class="eyebrow">Join SmartQueue</p>
            <h1>Give every visitor a clearer place in line.</h1>
            <p>Customers get a number, staff get visibility, and everyone gets a calmer waiting room.</p>
        </section>

        <section class="auth-card panel-card">
            <h2>Create account</h2>
            <p class="text-muted mb-4">Start with a regular user account. Admins can upgrade roles in the database.</p>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= e($messageType) ?>"><?= e($message) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control form-control-lg" required value="<?= e($_POST['full_name'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control form-control-lg" required value="<?= e($_POST['email'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control form-control-lg" required minlength="8">
                </div>

                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control form-control-lg" required minlength="8">
                </div>

                <button type="submit" class="btn btn-brand w-100 btn-lg">Sign Up</button>
            </form>

            <p class="text-center mt-4 mb-0">
                Already have an account? <a href="login.php">Log in</a>
            </p>
        </section>
    </main>
</body>
</html>

