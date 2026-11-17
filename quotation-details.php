<?php
require_once __DIR__ . '/includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['view']) ? (int)$_GET['view'] : 0);
if ($id > 0) {
    header("Location: quotations.php?view=" . $id);
    exit();
}
header("Location: quotations.php");
exit();
