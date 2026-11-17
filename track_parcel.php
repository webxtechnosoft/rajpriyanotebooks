<?php
/**
 * Parcel Tracking Router (Backwards Compatibility with Existing QR Codes & Printed Stickers)
 * Seamlessly routes to dispatch-details.php when verification token is present, or track_order.php.
 */

$token = $_GET['token'] ?? '';
if (!empty($token)) {
    require_once __DIR__ . '/dispatch-details.php';
    exit();
}

require_once __DIR__ . '/track_order.php';

