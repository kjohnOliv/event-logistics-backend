<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

const SMARTQUEUE_MAIL_HOST = 'smtp.gmail.com';
const SMARTQUEUE_MAIL_USERNAME = 'YOUR_GMAIL@gmail.com';
const SMARTQUEUE_MAIL_PASSWORD = 'YOUR_APP_PASSWORD';
const SMARTQUEUE_MAIL_FROM = 'YOUR_GMAIL@gmail.com';
const SMARTQUEUE_BASE_URL = 'http://localhost/smartqueue';

function mailerIsConfigured() {
    return SMARTQUEUE_MAIL_USERNAME !== 'YOUR_GMAIL@gmail.com'
        && SMARTQUEUE_MAIL_PASSWORD !== 'YOUR_APP_PASSWORD'
        && SMARTQUEUE_MAIL_FROM !== 'YOUR_GMAIL@gmail.com';
}

function sendVerificationEmail($toEmail, $toName, $token) {
    if (!mailerIsConfigured()) {
        return ['success' => false, 'message' => 'Mailer credentials are not configured.'];
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = SMARTQUEUE_MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMARTQUEUE_MAIL_USERNAME;
        $mail->Password = SMARTQUEUE_MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom(SMARTQUEUE_MAIL_FROM, 'SmartQueue');
        $mail->addAddress($toEmail, $toName);

        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $verifyLink = SMARTQUEUE_BASE_URL . '/verify.php?token=' . urlencode($token);
        $safeLink = htmlspecialchars($verifyLink, ENT_QUOTES, 'UTF-8');

        $mail->isHTML(true);
        $mail->Subject = 'Verify Your SmartQueue Account';
        $mail->Body = "
            <h3>Hello, {$safeName}!</h3>
            <p>Thank you for signing up for SmartQueue.</p>
            <p>Please verify your account using the button below:</p>
            <p><a href='{$safeLink}' style='display:inline-block;padding:12px 18px;background:#0f766e;color:#ffffff;text-decoration:none;border-radius:8px;'>Verify My Account</a></p>
            <p>If you did not create this account, you may ignore this email.</p>
        ";
        $mail->AltBody = "Hello {$toName}, verify your SmartQueue account here: {$verifyLink}";

        $mail->send();
        return ['success' => true, 'message' => 'Email sent successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $mail->ErrorInfo];
    }
}
?>

