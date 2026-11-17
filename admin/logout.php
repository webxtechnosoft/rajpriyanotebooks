<?php
require_once __DIR__ . '/../includes/functions.php';
unset($_SESSION['admin_id']);
unset($_SESSION['admin_name']);
set_flash('success', 'Admin logged out successfully.');
header("Location: ../login.php");
exit();
