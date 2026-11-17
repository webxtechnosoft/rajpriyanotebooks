<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

set_flash('info', 'Coupon management is currently disabled.');
header('Location: index.php');
exit();
