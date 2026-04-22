<?php
include 'config.php';
include 'functions.php';

requireRole('operator');
header('Location: admin_dashboard.php');
exit();
?>

