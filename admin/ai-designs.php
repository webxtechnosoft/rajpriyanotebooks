<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

set_flash('info', 'AI Notebook Designs are currently disabled.');
header('Location: index.php');
exit();
