<?php
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'smartqueue_db';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = new mysqli($host, $user, $password, $database);

if ($conn->connect_error) {
    http_response_code(500);
    die('SmartQueue cannot connect to the database. Please check config.php and import database.sql.');
}

$conn->set_charset('utf8mb4');
