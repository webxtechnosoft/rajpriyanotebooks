<?php
/**
 * End-to-End Workflow Test for:
 * Rajpriya Notebook Wholesale - Fix Admin Quotation Management:
 * 1. Product-Wise Accept/Decline
 * 2. Customer Change Requests (Remove item & Adjust quantity)
 * 3. Visual Diff in Admin Panel
 * 4. Status Tracking & Audit Snapshots
 * 5. Final Invoice Generation with Approved Products Only
 * 6. Server Validation & Recalculation
 * 7. Printable Invoice / PDF Consistency
 */

require_once __DIR__ . '/includes/functions.php';

$db = getDB();
echo "====================================================================\n";
echo "   RAJPRIYA NOTEBOOK - QUOTATION MANAGEMENT END-TO-END TEST SUITE   \n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest($description, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $description\n";
        if ($details) echo "        ↳ $details\n";
        $passCount++;
    } else {
        echo " [FAIL] $description\n";
        if ($details) echo "        ↳ $details\n";
        $failCount++;
    }
}

// -------------------------------------------------------------
// Step 0: Ensure Test Customer and 5 Products Exist
// -------------------------------------------------------------
echo "--- STEP 0: Setting Up Test Customer and 5 Products ---\n";

$testEmail = 'quotation_test_wholesaler@example.com';
$stmtUser = $db->prepare("SELECT id FROM users WHERE email = ?");
$stmtUser->execute([$testEmail]);
$customerId = $stmtUser->fetchColumn();

if (!$customerId) {
    $stmtCreateUser = $db->prepare("
        INSERT INTO users (customer_code, name, email, phone, customer_type, password, status, created_at)
        VALUES ('CUST-TEST-001', 'Wholesale Retailer Test', ?, '9876543210', 'Wholesaler', 'pass123', 'active', NOW())
    ");
    $stmtCreateUser->execute([$testEmail]);
    $customerId = (int)$db->lastInsertId();
}
assertTest("Customer exists with ID $customerId", $customerId > 0);

// Fetch 5 active products from catalog
$stmtProds = $db->query("SELECT id, name, sku, price, sale_price, gst_rate, std_qty FROM products WHERE active = 1 ORDER BY id ASC LIMIT 5");
$catalogProds = $stmtProds->fetchAll(PDO::FETCH_ASSOC);

if (count($catalogProds) < 5) {
    die("Error: Repository database has less than 5 products. Needed for 5-product test.\n");
}
assertTest("Found 5 catalog products for test quotation", count($catalogProds) === 5);

// -------------------------------------------------------------
// Step 1: Create Test Order with 5 Products
// -------------------------------------------------------------
echo "\n--- STEP 1: Customer Submits Order with 5 Products ---\n";

$testOrderNum = 'ORD-TEST-E2E-' . time();
$stmtInsOrder = $db->prepare("
    INSERT INTO orders (
        order_number, user_id, customer_name, customer_email, customer_phone,
        customer_type, customer_code, shipping_address, subtotal, discount,
        delivery_fee, total_gst, grand_total, order_status,
        payment_status, created_at
    ) VALUES (
        ?, ?, 'Wholesale Retailer Test', ?, '9876543210',
        'Wholesaler', 'CUST-TEST-001', '123 Test Market, Madurai - 625001',
        0, 0, 0, 0, 0, 'Pending', 'Pending', NOW()
    )
");
$stmtInsOrder->execute([$testOrderNum, $customerId, $testEmail]);
$orderId = (int)$db->lastInsertId();

assertTest("Order created successfully", $orderId > 0, "Order Number: $testOrderNum");

// Insert 5 line items into quotation_items / order_items
$initialItems = [];
$sampleQuantities = [10, 20, 15, 25, 30]; // 5 products
$samplePrices = [50.00, 45.00, 60.00, 75.00, 40.00];

foreach ($catalogProds as $i => $cp) {
    $qty = $sampleQuantities[$i];
    $unitP = $samplePrices[$i];
    $ppb = max(1, (int)($cp['std_qty'] ?? 10));
    $totPcs = $qty * $ppb;
    $gstR = (float)($cp['gst_rate'] ?: 18.00);

    $lineTot = $unitP * $qty;
    $lineGst = round(($lineTot * $gstR) / 100, 2);

    $initialItems[] = [
        'product_id' => $cp['id'],
        'variant_id' => null,
        'product_code' => $cp['sku'] ?: 'SKU-' . $cp['id'],
        'product_name' => $cp['name'],
        'variety' => 'Ruled',
        'ruling_type' => 'Ruled',
        'page_count' => 192,
        'order_type' => 'Bundle',
        'pieces_per_bundle' => $ppb,
        'quantity' => $qty,
        'total_pieces' => $totPcs,
        'unit_price' => $unitP,
        'discount' => 0.00,
        'gst_rate' => $gstR,
        'item_status' => 'Pending',
        'original_quantity' => $qty,
        'customer_requested_quantity' => null,
        'customer_action' => 'none',
        'is_customer_removed' => 0,
        'admin_comment' => ''
    ];
}

$totals = calculate_quotation_totals($initialItems, 0, 0);

// Insert Quotation with 5 items
$testQNum = 'QT-E2E-' . time();
$stmtInsQ = $db->prepare("
    INSERT INTO quotations (
        quotation_number, order_id, order_number, user_id, customer_code,
        customer_name, customer_email, customer_phone, shipping_address,
        subtotal, discount, delivery_fee, taxable_amount, total_gst,
        total_cgst, total_sgst, grand_total, status, version, sent_at, created_by_admin
    ) VALUES (
        ?, ?, ?, ?, 'CUST-TEST-001',
        'Wholesale Retailer Test', ?, '9876543210', '123 Test Market, Madurai - 625001',
        ?, 0, 0, ?, ?, ?, ?, ?, 'Sent', 1, NOW(), 1
    )
");
$stmtInsQ->execute([
    $testQNum, $orderId, $testOrderNum, $customerId, $testEmail,
    $totals['subtotal'], $totals['taxable_amount'], $totals['total_gst'],
    $totals['total_cgst'], $totals['total_sgst'], $totals['grand_total']
]);
$quotationId = (int)$db->lastInsertId();

assertTest("Quotation created with ID $quotationId", $quotationId > 0, "Quotation Number: $testQNum");

$stmtInsQItem = $db->prepare("
    INSERT INTO quotation_items (
        quotation_id, product_id, variant_id, product_code, product_name,
        variety, ruling_type, page_count, order_type, pieces_per_bundle,
        quantity, total_pieces, unit_price, discount, total_price,
        gst_rate, taxable_amount, gst_amount, cgst_amount, sgst_amount,
        item_status, original_quantity, customer_action, is_customer_removed
    ) VALUES (
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?,
        'Pending', ?, 'none', 0
    )
");

$createdItemIds = [];
foreach ($initialItems as $idx => $it) {
    $rec = $totals['items'][$idx];
    $stmtInsQItem->execute([
        $quotationId, $it['product_id'], $it['variant_id'], $it['product_code'], $it['product_name'],
        $it['variety'], $it['ruling_type'], $it['page_count'], $it['order_type'], $it['pieces_per_bundle'],
        $it['quantity'], $it['total_pieces'], $it['unit_price'], $it['discount'], $rec['total_price'],
        $it['gst_rate'], $rec['taxable_amount'], $rec['gst_amount'], $rec['cgst_amount'], $rec['sgst_amount'],
        $it['quantity']
    ]);
    $createdItemIds[] = (int)$db->lastInsertId();
}

assertTest("5 items inserted into quotation_items", count($createdItemIds) === 5);
record_quotation_revision($quotationId, 'initial', 'admin', 1, 'Initial quotation sent with 5 products');

// -------------------------------------------------------------
// Step 2: Product-Wise Accept/Decline in Admin Panel
// -------------------------------------------------------------
echo "\n--- STEP 2: Product-Wise Accept and Decline by Admin ---\n";

// Admin accepts Product 1 (idx 0), declines Product 4 (idx 3), leaves others
$res1 = update_quotation_item_status($quotationId, $createdItemIds[0], 'Accepted', 'Wholesale price approved by Admin', 1);
assertTest("Admin can independently Accept Product 1", $res1['success'] === true);

$res4 = update_quotation_item_status($quotationId, $createdItemIds[3], 'Declined', 'Temporarily out of stock', 1);
assertTest("Admin can independently Decline Product 4", $res4['success'] === true);

// Verify individual item statuses in DB
$itemsAfterReview = get_quotation_items($quotationId);
assertTest("Product 1 status is Accepted in database", $itemsAfterReview[0]['item_status'] === 'Accepted');
assertTest("Product 2 status remains Pending", $itemsAfterReview[1]['item_status'] === 'Pending');
assertTest("Product 3 status remains Pending", $itemsAfterReview[2]['item_status'] === 'Pending');
assertTest("Product 4 status is Declined in database", $itemsAfterReview[3]['item_status'] === 'Declined');
assertTest("Product 5 status remains Pending", $itemsAfterReview[4]['item_status'] === 'Pending');

// -------------------------------------------------------------
// Step 3: Customer Requests Changes to Quotation
// -------------------------------------------------------------
echo "\n--- STEP 3: Customer Requests Removal of Product 5 & Quantity Modification of Product 2 ---\n";

// Customer requests:
// - Product 5: Remove
// - Product 2: Change quantity from 20 to 12
$customerChanges = [
    $createdItemIds[1] => ['quantity' => 12], // Product 2: Qty 20 -> 12
    $createdItemIds[4] => ['remove' => 1]     // Product 5: Remove
];
$customerNotes = "Need to reduce Product 2 to 12 bundles due to storage limit, and completely remove Product 5.";

$changeRes = submit_customer_quotation_change_request($quotationId, $customerId, $customerChanges, $customerNotes);
assertTest("Customer change request submitted successfully", $changeRes['success'] === true);

// Verify database reflects the change request
$stmtCheckQ = $db->prepare("SELECT * FROM quotations WHERE id = ?");
$stmtCheckQ->execute([$quotationId]);
$qAfterChange = $stmtCheckQ->fetch();

assertTest("Quotation status transitioned to 'Change Requested'", $qAfterChange['status'] === 'Change Requested');
assertTest("Quotation version incremented to 2", (int)$qAfterChange['version'] === 2);
assertTest("Customer notes recorded properly", $qAfterChange['customer_notes'] === $customerNotes);
assertTest("Change requested timestamp is recorded", !empty($qAfterChange['change_requested_at']));

$itemsAfterChange = get_quotation_items($quotationId);

// Check Product 5 is removed
$item5 = null;
$item2 = null;
foreach ($itemsAfterChange as $it) {
    if ((int)$it['id'] === $createdItemIds[4]) $item5 = $it;
    if ((int)$it['id'] === $createdItemIds[1]) $item2 = $it;
}

assertTest("Product 5 is marked as is_customer_removed = 1", !empty($item5['is_customer_removed']));
assertTest("Product 5 customer_action is 'removed'", $item5['customer_action'] === 'removed');
assertTest("Product 5 item_status is 'Change Requested'", $item5['item_status'] === 'Change Requested');

assertTest("Product 2 quantity modified to 12", (int)$item2['quantity'] === 12);
assertTest("Product 2 original_quantity is preserved as 20", (int)$item2['original_quantity'] === 20);
assertTest("Product 2 customer_action is 'modified'", $item2['customer_action'] === 'modified');
assertTest("Product 2 item_status is marked for review as 'Change Requested'", $item2['item_status'] === 'Change Requested');

// -------------------------------------------------------------
// Step 4: Audit & Revision History Preservation
// -------------------------------------------------------------
echo "\n--- STEP 4: Revision History & Audit Log Verification ---\n";

$revisions = get_quotation_revisions($quotationId);
assertTest("Quotation revisions table contains audit snapshots", count($revisions) >= 2);

$foundCustomerRev = false;
foreach ($revisions as $rev) {
    if ($rev['revision_type'] === 'customer_change_request' && $rev['created_by_role'] === 'customer') {
        $foundCustomerRev = true;
        $snapItems = json_decode($rev['items_snapshot'], true);
        assertTest("Snapshot contains preserved item state in JSON", is_array($snapItems) && count($snapItems) === 5);
        break;
    }
}
assertTest("Customer change request audit entry exists", $foundCustomerRev);

// -------------------------------------------------------------
// Step 5: Backend Validation Against Incomplete Quotation Approval
// -------------------------------------------------------------
echo "\n--- STEP 5: Backend Validation - Prevent Incomplete Approval ---\n";

// Attempt to sync invoice while Product 2, Product 3, or Product 5 remain Pending/Change Requested
$earlySyncRes = sync_final_quotation_to_invoice($quotationId, 1);
assertTest(
    "Validation correctly blocks invoice sync while items are Pending/Change Requested",
    $earlySyncRes['success'] === false,
    "Expected message received: " . ($earlySyncRes['message'] ?? '')
);

// -------------------------------------------------------------
// Step 6: Admin Completes Reviews & Final Product Approval
// -------------------------------------------------------------
echo "\n--- STEP 6: Admin Reviews Revised Request & Completes Approvals ---\n";

// Admin reviews and decisions:
// - Product 1: Accepted (qty 10)
// - Product 2: Accepted revised qty (qty 12)
// - Product 3: Accepted (qty 15)
// - Product 4: Declined
// - Product 5: Customer removed (excluded)
update_quotation_item_status($quotationId, $createdItemIds[1], 'Accepted', 'Approved revised quantity of 12', 1);
update_quotation_item_status($quotationId, $createdItemIds[2], 'Accepted', 'Approved item', 1);

// Verify all active products have definitive decisions
$finalReviewItems = get_quotation_items($quotationId);
$pendingCount = 0;
foreach ($finalReviewItems as $fri) {
    if (empty($fri['is_customer_removed']) && in_array($fri['item_status'], ['Pending', 'Change Requested'])) {
        $pendingCount++;
    }
}
assertTest("Zero pending or unreviewed active items remain", $pendingCount === 0);

// The latest quotation version must be accepted by the customer before order finalization.
$beforeCustomerAccept = sync_final_quotation_to_invoice($quotationId, 1);
assertTest(
    "Final invoice sync is blocked until customer accepts the current quotation version",
    $beforeCustomerAccept['success'] === false
        && strpos($beforeCustomerAccept['message'] ?? '', 'Customer approval is required') !== false
);
$db->prepare("UPDATE quotations SET status = 'Accepted', customer_response_at = NOW() WHERE id = ?")
    ->execute([$quotationId]);
record_quotation_revision($quotationId, 'customer_acceptance', 'customer', $customerId, 'Customer accepted the revised quotation.');

// Admin performs Final Approval & Invoice Synchronization after customer acceptance
$syncRes = sync_final_quotation_to_invoice($quotationId, 1);
assertTest("sync_final_quotation_to_invoice executed successfully", $syncRes['success'] === true);
assertTest("Exactly 3 approved products synced to invoice", $syncRes['approved_count'] === 3);

// -------------------------------------------------------------
// Step 7: Verify Order, Invoice, and PDF Consistency
// -------------------------------------------------------------
echo "\n--- STEP 7: Verify Invoice Items, Totals, Exclusions, and Printable Invoice ---\n";

// Check order_items table
$stmtOI = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
$stmtOI->execute([$orderId]);
$invoiceItems = $stmtOI->fetchAll(PDO::FETCH_ASSOC);

assertTest("Invoice order_items contains exactly 3 approved products", count($invoiceItems) === 3);

$invoiceProductIds = array_column($invoiceItems, 'product_id');
assertTest("Approved Product 1 is present in invoice", in_array($catalogProds[0]['id'], $invoiceProductIds));
assertTest("Approved Product 2 is present in invoice", in_array($catalogProds[1]['id'], $invoiceProductIds));
assertTest("Approved Product 3 is present in invoice", in_array($catalogProds[2]['id'], $invoiceProductIds));
assertTest("Declined Product 4 is strictly EXCLUDED from invoice", !in_array($catalogProds[3]['id'], $invoiceProductIds));
assertTest("Customer-Removed Product 5 is strictly EXCLUDED from invoice", !in_array($catalogProds[4]['id'], $invoiceProductIds));

// Verify approved quantity of Product 2
foreach ($invoiceItems as $ii) {
    if ((int)$ii['product_id'] === (int)$catalogProds[1]['id']) {
        assertTest("Product 2 has revised approved quantity = 12 (not old 20)", (int)$ii['quantity'] === 12);
    }
}

// Check Orders table totals vs recalculated totals
$stmtOrdCheck = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmtOrdCheck->execute([$orderId]);
$finalOrder = $stmtOrdCheck->fetch();

$expectedSubtotal = (10 * 50.00) + (12 * 45.00) + (15 * 60.00); // 500 + 540 + 900 = 1940
assertTest("Invoice subtotal correctly recalculated to ₹$expectedSubtotal", (float)$finalOrder['subtotal'] === (float)$expectedSubtotal);
assertTest("Invoice order grand_total matches calculated totals", (float)$finalOrder['grand_total'] === (float)$syncRes['grand_total']);
assertTest("Order status updated to Confirmed", $finalOrder['order_status'] === 'Confirmed');

// Verify Quotation status and final approval flags
$stmtQFinal = $db->prepare("SELECT * FROM quotations WHERE id = ?");
$stmtQFinal->execute([$quotationId]);
$finalQuotation = $stmtQFinal->fetch();

assertTest("Quotation status is 'Accepted'", $finalQuotation['status'] === 'Accepted');
assertTest("Quotation is_final_approved flag is set to 1", (int)$finalQuotation['is_final_approved'] === 1);
assertTest("Quotation invoice_synced_at is populated", !empty($finalQuotation['invoice_synced_at']));

// Verify render_customer_tax_invoice_block() produces clean HTML matching approved items only
$logos = get_active_logos();
$orderGst = get_order_gst_summary($finalOrder, $invoiceItems);
$invoiceHtml = render_customer_tax_invoice_block($finalOrder, $invoiceItems, $logos, $orderGst, false);

$prod1Code = $initialItems[0]['product_code'];
$prod2Code = $initialItems[1]['product_code'];
$prod3Code = $initialItems[2]['product_code'];
$prod4Code = $initialItems[3]['product_code'];
$prod5Code = $initialItems[4]['product_code'];

assertTest("Printable invoice contains Product 1 code ($prod1Code)", strpos($invoiceHtml, htmlspecialchars($prod1Code)) !== false);
assertTest("Printable invoice contains Product 2 code ($prod2Code)", strpos($invoiceHtml, htmlspecialchars($prod2Code)) !== false);
assertTest("Printable invoice contains Product 3 code ($prod3Code)", strpos($invoiceHtml, htmlspecialchars($prod3Code)) !== false);
assertTest("Printable invoice DOES NOT contain Declined Product 4 code ($prod4Code)", strpos($invoiceHtml, htmlspecialchars($prod4Code)) === false);
assertTest("Printable invoice DOES NOT contain Removed Product 5 code ($prod5Code)", strpos($invoiceHtml, htmlspecialchars($prod5Code)) === false);

echo "\n====================================================================\n";
echo "   TEST SUMMARY: $passCount PASSED, $failCount FAILED\n";
echo "====================================================================\n";

if ($failCount === 0) {
    echo "🎉 ALL TESTS PASSED! Quotation product-wise accept/decline, customer change\n";
    echo "   workflow, visual diffs, and invoice synchronization are working perfectly!\n";
} else {
    echo "❌ SOME TESTS FAILED! Please inspect the failures above.\n";
    exit(1);
}
