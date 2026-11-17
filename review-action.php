<?php
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
    $customer_name = sanitize($_POST['customer_name']);
    $rating = (int)$_POST['rating'];
    $review_text = sanitize($_POST['review_text']);
    $user_id = is_customer_logged_in() ? $_SESSION['customer_id'] : null;

    if ($product_id > 0 && !empty($customer_name) && !empty($review_text)) {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO reviews (product_id, user_id, customer_name, rating, review_text, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$product_id, $user_id, $customer_name, $rating, $review_text]);
        set_flash('success', 'Thank you! Your review has been submitted for approval.');
    } else {
        set_flash('error', 'Please fill in all required review fields.');
    }
    $redirect = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'reviews.php';
    header("Location: " . $redirect);
    exit();
}
header("Location: index.php");
exit();
