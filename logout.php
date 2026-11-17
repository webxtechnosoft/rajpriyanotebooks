<?php
require_once __DIR__ . '/includes/functions.php';
unset($_SESSION['customer_id']);
unset($_SESSION['customer_name']);
set_flash('success', 'You have been logged out.');
header("Location: login.php");
exit();
