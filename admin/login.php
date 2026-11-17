<?php
require_once __DIR__ . '/../includes/functions.php';

if (is_admin_logged_in()) {
    header("Location: index.php");
    exit();
}

// Redirect to unified login page
header("Location: ../login.php");
exit();
