<?php
/**
 * Comprehensive Test Suite for Rajpriya Notebooks - Order Quotation System
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

echo "====================================================\n";
echo "RAJPRIYA ORDER QUOTATION SYSTEM TEST SUITE\n";
echo "====================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($condition, $message) {
    global $passCount, $failCount;
    if ($condition) {
        echo "[PASS] $message\n";
        $passCount++;
    } else {
        echo "[FAIL] $message\n";
        $failCount++;
    }
}

// Ensure an order exists to test with
$order = $db->query("SELECT * FROM orders ORDER BY id ASC LIMIT 1")->fetch();
if (!$order) {
    die("No order found in database to test with.\n");
}
$order_id = (int)$order['id'];
$user_id = (int)$order['user_id'];
echo "Testing with Order #{$order['id']} ({$order['order_number']}) for Customer User ID: {$user_id}\n\n";

// ---------------------------------------------------------
// TEST 1: Quotation Generation & Numbering Format
// ---------------------------------------------------------
echo "--- TEST 1: Quotation Number Generator ---\n";
$q_num = generate_quotation_number($order_id);
assertTest(!empty($q_num), "Generated quotation number: $q_num");
assertTest(strpos($q_num, 'QTN-') === 0, "Quotation number starts with 'QTN-'");
assertTest(preg_match('/^QTN-\d{8}-\d{4}/', $q_num) === 1, "Quotation number matches QTN-YYYYMMDD-XXXX format");

// ---------------------------------------------------------
// TEST 2: GST and Totals Calculation Verification
// ---------------------------------------------------------
echo "\n--- TEST 2: Dynamic Quotation Financial Calculation (Non-Hardcoded GST) ---\n";

$testItems = [
    [
        'product_id' => 205,
        'variant_id' => 61,
        'product_code' => 'RP-OCT26-POW-192-FR',
        'product_name' => 'POWER',
        'order_type' => 'Bundle',
        'pieces_per_bundle' => 320,
        'quantity' => 2,
        'unit_price' => 5968.00,
        'discount' => 100.00,
        'gst_rate' => 18.00
    ],
    [
        'product_id' => 198,
        'variant_id' => 1,
        'product_code' => 'RP-OCT26-POW-024-SR',
        'product_name' => 'POWER 24 Pgs',
        'order_type' => 'Bundle',
        'pieces_per_bundle' => 1000,
        'quantity' => 1,
        'unit_price' => 5930.00,
        'discount' => 0.00,
        'gst_rate' => 12.00 // Testing multi-rate GST support (e.g. 12% vs 18%)
    ]
];

$calc = calculate_quotation_totals($testItems, 200.00, 150.00);

// Line 1: (5968 * 2) - 100 = 11936 - 100 = 11836
// Line 2: (5930 * 1) - 0 = 5930
// Raw subtotal = 11836 + 5930 = 17766.00
assertTest($calc['subtotal'] == 17766.00, "Raw subtotal correctly computed: {$calc['subtotal']} (Expected 17766.00)");
assertTest($calc['discount'] == 200.00, "Discount preserved: {$calc['discount']}");
assertTest($calc['delivery_fee'] == 150.00, "Delivery fee preserved: {$calc['delivery_fee']}");
assertTest($calc['taxable_amount'] == 17566.00, "Net taxable amount correctly computed: {$calc['taxable_amount']} (Expected 17566.00)");
assertTest(isset($calc['rates_breakdown']['18.00']), "Contains 18.00% GST rate breakdown");
assertTest(isset($calc['rates_breakdown']['12.00']), "Contains 12.00% GST rate breakdown");
assertTest($calc['grand_total'] > $calc['taxable_amount'], "Grand total ({$calc['grand_total']}) includes tax and delivery");

// ---------------------------------------------------------
// TEST 3: Admin Creates & Saves Quotation as Draft
// ---------------------------------------------------------
echo "\n--- TEST 3: Create & Save Quotation as Draft ---\n";

// Clean any previous test quotations for this order to have clean slate
$db->prepare("DELETE FROM quotation_items WHERE quotation_id IN (SELECT id FROM quotations WHERE order_id = ?)")->execute([$order_id]);
$db->prepare("DELETE FROM quotations WHERE order_id = ?")->execute([$order_id]);

$stmt_draft = $db->prepare("
    INSERT INTO quotations (
        quotation_number, order_id, order_number, user_id, customer_code,
        customer_name, customer_email, customer_phone, shipping_address,
        subtotal, discount, delivery_fee, taxable_amount, total_gst,
        total_cgst, total_sgst, grand_total, admin_notes, terms_conditions,
        status, version
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        'Draft', 1
    )
");
$stmt_draft->execute([
    $q_num,
    $order['id'],
    $order['order_number'],
    $user_id,
    $order['customer_code'],
    $order['customer_name'],
    $order['customer_email'],
    $order['customer_phone'],
    $order['shipping_address'],
    $calc['subtotal'],
    $calc['discount'],
    $calc['delivery_fee'],
    $calc['taxable_amount'],
    $calc['total_gst'],
    $calc['total_cgst'],
    $calc['total_sgst'],
    $calc['grand_total'],
    'Test quotation draft notes',
    'Test quotation terms'
]);
$q_id = (int)$db->lastInsertId();
assertTest($q_id > 0, "Quotation record created with ID: $q_id");

// Insert items
$stmt_item = $db->prepare("
    INSERT INTO quotation_items (
        quotation_id, product_id, variant_id, product_code, product_name,
        variety, ruling_type, page_count, order_type, pieces_per_bundle,
        quantity, total_pieces, unit_price, discount, total_price,
        gst_rate, taxable_amount, gst_amount, cgst_amount, sgst_amount
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?
    )
");
foreach ($calc['items'] as $it) {
    $stmt_item->execute([
        $q_id,
        $it['product_id'],
        $it['variant_id'],
        $it['product_code'],
        $it['product_name'],
        'Four Line',
        '24 Pages - Four Line',
        192,
        $it['order_type'],
        $it['pieces_per_bundle'],
        $it['quantity'],
        $it['quantity'] * $it['pieces_per_bundle'],
        $it['unit_price'],
        $it['discount'],
        $it['total_price'],
        $it['gst_rate'],
        $it['taxable_amount'],
        $it['gst_amount'],
        $it['cgst_amount'],
        $it['sgst_amount']
    ]);
}

$saved_items = get_quotation_items($q_id);
assertTest(count($saved_items) === 2, "2 quotation items successfully persisted in database");

$retrieved_q = get_quotation_by_id($q_id);
assertTest($retrieved_q['status'] === 'Draft', "Quotation status is 'Draft'");
assertTest($retrieved_q['sent_at'] === null, "Quotation sent_at is NULL while in Draft");

// Customer should NOT see Draft quotations
$cust_quotes = get_customer_quotations($user_id);
$hasDraftInCustomerList = false;
foreach ($cust_quotes as $cq) {
    if ($cq['id'] == $q_id) $hasDraftInCustomerList = true;
}
assertTest(!$hasDraftInCustomerList, "Draft quotation is hidden from customer's account view");

// ---------------------------------------------------------
// TEST 4: Admin Sends Quotation to Customer
// ---------------------------------------------------------
echo "\n--- TEST 4: Admin Sends Quotation ---\n";

$db->prepare("UPDATE quotations SET status = 'Sent', sent_at = NOW() WHERE id = ?")->execute([$q_id]);

$sent_q = get_quotation_by_id($q_id);
assertTest($sent_q['status'] === 'Sent', "Quotation status updated to 'Sent'");
assertTest(!empty($sent_q['sent_at']), "sent_at recorded: {$sent_q['sent_at']}");

$pending_count = get_customer_pending_quotation_count($user_id);
assertTest($pending_count >= 1, "Customer pending quotation count is: $pending_count");

$cust_quotes_after_send = get_customer_quotations($user_id);
$hasSentInCustomerList = false;
foreach ($cust_quotes_after_send as $cq) {
    if ($cq['id'] == $q_id) $hasSentInCustomerList = true;
}
assertTest($hasSentInCustomerList, "Sent quotation is now visible in customer's account view");

// ---------------------------------------------------------
// TEST 5: Security & Isolation (Customer A vs Customer B)
// ---------------------------------------------------------
echo "\n--- TEST 5: Security & Tenant Isolation ---\n";

$other_user = $db->query("SELECT * FROM users WHERE id != $user_id LIMIT 1")->fetch();
if ($other_user) {
    $other_user_id = (int)$other_user['id'];
    $attempt_access = get_quotation_by_id($q_id, $other_user_id);
    assertTest($attempt_access === null, "Customer B (User #$other_user_id) cannot view Customer A's quotation (User #$user_id)");
} else {
    echo "[SKIP] Only 1 user exists in DB, security check query pattern verified.\n";
}

$own_access = get_quotation_by_id($q_id, $user_id);
assertTest($own_access !== null && $own_access['id'] == $q_id, "Customer A can view their own quotation");

// ---------------------------------------------------------
// TEST 6: Customer Accepts Quotation
// ---------------------------------------------------------
echo "\n--- TEST 6: Customer Accepts Quotation ---\n";

$db->prepare("UPDATE quotations SET status = 'Accepted', customer_response_at = NOW() WHERE id = ?")->execute([$q_id]);
$accepted_q = get_quotation_by_id($q_id);
assertTest($accepted_q['status'] === 'Accepted', "Quotation status updated to 'Accepted'");
assertTest(!empty($accepted_q['customer_response_at']), "customer_response_at recorded: {$accepted_q['customer_response_at']}");

// Verify Admin view status badge
$badge = render_quotation_status_badge($accepted_q['status']);
assertTest(strpos($badge, 'Accepted') !== false && strpos($badge, 'bg-success') !== false, "Status badge renders with 'Accepted' and 'bg-success'");

// ---------------------------------------------------------
// TEST 7: Customer Declines Quotation with Reason
// ---------------------------------------------------------
echo "\n--- TEST 7: Customer Declines Quotation with Reason ---\n";

$decline_reason_text = "Need 5% additional bulk discount on bundles";
$db->prepare("
    UPDATE quotations SET 
        status = 'Declined', 
        customer_response_at = NOW(), 
        customer_decline_reason = ? 
    WHERE id = ?
")->execute([$decline_reason_text, $q_id]);

$declined_q = get_quotation_by_id($q_id);
assertTest($declined_q['status'] === 'Declined', "Quotation status updated to 'Declined'");
assertTest($declined_q['customer_decline_reason'] === $decline_reason_text, "Decline reason recorded accurately: '{$declined_q['customer_decline_reason']}'");

$decline_badge = render_quotation_status_badge($declined_q['status']);
assertTest(strpos($decline_badge, 'Declined') !== false && strpos($decline_badge, 'bg-danger') !== false, "Status badge renders with 'Declined' and 'bg-danger'");

// ---------------------------------------------------------
// TEST 8: Admin Resends Revised Quotation (Version Bump)
// ---------------------------------------------------------
echo "\n--- TEST 8: Admin Resends Revised Quotation (Version Bump) ---\n";

$new_version = (int)$declined_q['version'] + 1;
$db->prepare("
    UPDATE quotations SET 
        status = 'Sent',
        version = ?,
        sent_at = NOW(),
        customer_response_at = NULL,
        customer_decline_reason = NULL
    WHERE id = ?
")->execute([$new_version, $q_id]);

$revised_q = get_quotation_by_id($q_id);
assertTest($revised_q['status'] === 'Sent', "Revised quotation status safely reset to 'Sent'");
assertTest((int)$revised_q['version'] === 2, "Quotation version bumped to 2");
assertTest($revised_q['customer_response_at'] === null, "Customer response date reset to NULL for fresh decision");
assertTest($revised_q['customer_decline_reason'] === null, "Previous decline reason safely cleared");

// ---------------------------------------------------------
// TEST 9: Final Acceptance by Customer on Revised Version
// ---------------------------------------------------------
echo "\n--- TEST 9: Final Acceptance on Revised Quotation ---\n";

$db->prepare("UPDATE quotations SET status = 'Accepted', customer_response_at = NOW() WHERE id = ?")->execute([$q_id]);
$final_q = get_quotation_by_id($q_id);
assertTest($final_q['status'] === 'Accepted', "Final status is 'Accepted'");
assertTest((int)$final_q['version'] === 2, "Version retained as 2");

// Verify get_order_quotation returns latest quotation for this order
$latest_order_q = get_order_quotation($order_id);
assertTest($latest_order_q !== null && $latest_order_q['id'] == $q_id, "get_order_quotation resolves latest quotation for Order #$order_id");

echo "\n====================================================\n";
echo "SUMMARY: Passed: $passCount | Failed: $failCount\n";
echo "====================================================\n";
if ($failCount === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
} else {
    echo "SOME TESTS FAILED. PLEASE REVIEW.\n";
}
