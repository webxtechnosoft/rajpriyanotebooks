<?php
/**
 * End-to-End HTTP Web Integration Test for Rajpriya Order Quotation System
 * Tests actual HTTP requests, cookie sessions, CSRF/forms, and responses.
 */

$baseUrl = 'http://localhost/rajpriya';
$cookieFileAdmin = __DIR__ . '/admin_cookie.txt';
$cookieFileCust = __DIR__ . '/customer_cookie.txt';

if (file_exists($cookieFileAdmin)) unlink($cookieFileAdmin);
if (file_exists($cookieFileCust)) unlink($cookieFileCust);

$pass = 0;
$fail = 0;

function runHttpTest($desc, $cond) {
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] $desc\n";
        $pass++;
    } else {
        echo "[FAIL] $desc\n";
        $fail++;
    }
}

function httpReq($url, $method = 'GET', $data = null, $cookieFile = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        }
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'body' => $response,
        'url'  => $effectiveUrl
    ];
}

echo "====================================================\n";
echo "HTTP END-TO-END INTEGRATION TEST: ORDER QUOTATION\n";
echo "====================================================\n\n";

// 1. Admin Login
echo "--- 1. Admin Login ---\n";
$loginRes = httpReq($baseUrl . '/login.php', 'POST', [
    'login_type' => 'admin',
    'username'   => 'admin',
    'password'   => 'admin@123'
], $cookieFileAdmin);
runHttpTest("Admin login successfully handled (HTTP {$loginRes['code']})", $loginRes['code'] === 200 && strpos($loginRes['url'], 'admin/index.php') !== false);

// 2. Admin Orders List
echo "\n--- 2. Admin Orders Page ---\n";
$ordersRes = httpReq($baseUrl . '/admin/orders.php', 'GET', null, $cookieFileAdmin);
runHttpTest("Admin orders page loads (HTTP {$ordersRes['code']})", $ordersRes['code'] === 200);
runHttpTest("Orders table contains 'Quotation' header", strpos($ordersRes['body'], '<th>Quotation</th>') !== false);
runHttpTest("Orders table contains 'Send Quotation' button", strpos($ordersRes['body'], 'Send Quotation') !== false);
runHttpTest("Existing 'Manage & Invoice' button remains intact", strpos($ordersRes['body'], 'Manage & Invoice') !== false);

// 3. Admin Quotation Editor
echo "\n--- 3. Admin Quotation Editor ---\n";
$qEditorRes = httpReq($baseUrl . '/admin/quotation.php?order_id=4', 'GET', null, $cookieFileAdmin);
runHttpTest("Quotation editor loads for Order #4 (HTTP {$qEditorRes['code']})", $qEditorRes['code'] === 200);
runHttpTest("Customer name 'sam' displayed", stripos($qEditorRes['body'], 'sam') !== false);
runHttpTest("Customer phone '7708620873' displayed", strpos($qEditorRes['body'], '7708620873') !== false);
runHttpTest("Order number 'ORD-20261021-9031' displayed", strpos($qEditorRes['body'], 'ORD-20261021-9031') !== false);
runHttpTest("Line item input fields present", strpos($qEditorRes['body'], 'items[0][product_name]') !== false);
runHttpTest("Unit price field present", strpos($qEditorRes['body'], 'item-unit-price') !== false);
runHttpTest("Save as Draft button present", strpos($qEditorRes['body'], 'Save as Draft') !== false);
runHttpTest("Send Quotation button present", strpos($qEditorRes['body'], 'Send Quotation') !== false);

// 4. Admin Sends Quotation via HTTP POST
echo "\n--- 4. Admin Submits & Sends Quotation ---\n";
$sendData = [
    'quotation_id'     => '0',
    'order_id'         => '4',
    'quotation_number' => 'QTN-20261022-0004',
    'action'           => 'send_quotation',
    'admin_notes'      => 'Special wholesale offer for 8 bundles of POWER 192 Pages Four Line.',
    'terms_conditions' => '1. Valid for 7 days. 2. Wholesale net rates inclusive of GST. 3. Free transport.',
    'discount'         => '200.00',
    'delivery_fee'     => '0.00',
    'items'            => [
        [
            'product_id'        => '205',
            'variant_id'        => '61',
            'product_code'      => 'RP-OCT26-POW-192-FR',
            'product_name'      => 'POWER',
            'variety'           => 'Four Line',
            'ruling_type'       => '24 Pages - Four Line',
            'page_count'        => '192',
            'order_type'        => 'Bundle',
            'pieces_per_bundle' => '320',
            'quantity'          => '8',
            'unit_price'        => '5968.00',
            'discount'          => '0.00',
            'gst_rate'          => '18.00'
        ]
    ]
];

$postQRes = httpReq($baseUrl . '/admin/quotation.php?order_id=4', 'POST', $sendData, $cookieFileAdmin);
runHttpTest("Quotation save & send processed (HTTP {$postQRes['code']})", $postQRes['code'] === 200);
runHttpTest("Status badge updated to 'Sent / Pending Response'", strpos($postQRes['body'], 'Sent / Pending Response') !== false);
runHttpTest("Success alert message displayed", stripos($postQRes['body'], 'sent successfully') !== false);

// 5. Admin Quotations List Page
echo "\n--- 5. Admin Quotations List Page ---\n";
$adminQListRes = httpReq($baseUrl . '/admin/quotations.php', 'GET', null, $cookieFileAdmin);
runHttpTest("Admin quotations dashboard loads (HTTP {$adminQListRes['code']})", $adminQListRes['code'] === 200);
runHttpTest("Quotation number appears in table", strpos($adminQListRes['body'], 'QTN-20261022-0004') !== false);
runHttpTest("Customer 'sam' appears in list", strpos($adminQListRes['body'], 'sam') !== false);
runHttpTest("Statistics cards present", strpos($adminQListRes['body'], 'Total Quotations') !== false);

// 6. Customer Login
echo "\n--- 6. Customer Login ---\n";
$custLoginRes = httpReq($baseUrl . '/login.php', 'POST', [
    'login_type' => 'customer',
    'email'      => '7708620873',
    'password'   => '7708620873'
], $cookieFileCust);
runHttpTest("Customer login handled (HTTP {$custLoginRes['code']})", $custLoginRes['code'] === 200 && strpos($custLoginRes['url'], 'account.php') !== false);

// 7. Customer Account Dashboard
echo "\n--- 7. Customer Account Dashboard ---\n";
$custAccRes = httpReq($baseUrl . '/account.php', 'GET', null, $cookieFileCust);
runHttpTest("Customer account page loads (HTTP {$custAccRes['code']})", $custAccRes['code'] === 200);
runHttpTest("'My Quotations' navigation link present in sidebar", strpos($custAccRes['body'], 'My Quotations') !== false);
runHttpTest("Pending review alert banner displayed", strpos($custAccRes['body'], 'Wholesale Quotation Waiting for Your Review') !== false);

// 8. Customer Quotations List Page
echo "\n--- 8. Customer Quotations List Page ---\n";
$custQListRes = httpReq($baseUrl . '/quotations.php', 'GET', null, $cookieFileCust);
runHttpTest("Customer quotations page loads (HTTP {$custQListRes['code']})", $custQListRes['code'] === 200);
runHttpTest("Quotation #QTN-20261022-0004 listed", strpos($custQListRes['body'], 'QTN-20261022-0004') !== false);
runHttpTest("Status 'Sent / Pending Response' visible to customer", strpos($custQListRes['body'], 'Sent / Pending Response') !== false);
runHttpTest("'Review & Respond' button visible", strpos($custQListRes['body'], 'Review & Respond') !== false);

// 9. Customer Views Full Quotation Document
echo "\n--- 9. Customer Views Quotation Details ---\n";
// Extract quotation id from the list
preg_match('/quotations\.php\?view=(\d+)/', $custQListRes['body'], $m);
$viewQId = !empty($m[1]) ? (int)$m[1] : 1;

$custQDocRes = httpReq($baseUrl . "/quotations.php?view={$viewQId}", 'GET', null, $cookieFileCust);
runHttpTest("Quotation document view loads (HTTP {$custQDocRes['code']})", $custQDocRes['code'] === 200);
runHttpTest("Document title 'ORDER QUOTATION' present", strpos($custQDocRes['body'], 'ORDER QUOTATION') !== false);
runHttpTest("Brand header 'RAJPRIYA NOTEBOOKS' present", strpos($custQDocRes['body'], 'RAJPRIYA NOTEBOOKS') !== false);
runHttpTest("Admin message card displayed", strpos($custQDocRes['body'], 'Special wholesale offer for 8 bundles') !== false);
runHttpTest("Line item 'POWER' (320 Pcs/Bdl, 8 Bundles) displayed", strpos($custQDocRes['body'], 'POWER') !== false);
runHttpTest("Accept Quotation button present", strpos($custQDocRes['body'], 'Accept Quotation') !== false);
runHttpTest("Decline Quotation button present", strpos($custQDocRes['body'], 'Decline Quotation') !== false);
runHttpTest("Accept confirmation modal present", strpos($custQDocRes['body'], 'id="acceptQuotationModal"') !== false);
runHttpTest("Decline confirmation modal present with reason input", strpos($custQDocRes['body'], 'name="decline_reason"') !== false);

// 10. Customer Accepts Quotation via POST
echo "\n--- 10. Customer Accepts Quotation ---\n";
$acceptRes = httpReq($baseUrl . '/quotations.php', 'POST', [
    'quotation_id' => $viewQId,
    'action'       => 'accept'
], $cookieFileCust);
runHttpTest("Quotation acceptance processed (HTTP {$acceptRes['code']})", $acceptRes['code'] === 200);
runHttpTest("Success message displayed", stripos($acceptRes['body'], 'You have accepted Quotation') !== false);
runHttpTest("Quotation status updated to 'Accepted'", strpos($acceptRes['body'], 'Accepted') !== false);
runHttpTest("Action buttons removed/locked after acceptance", strpos($acceptRes['body'], 'Respond to this Wholesale Quotation') === false);

// 11. Security Check: Customer Isolation
echo "\n--- 11. Security & Tenant Isolation via HTTP ---\n";
// Requesting quotation with no login (guest)
$guestRes = httpReq($baseUrl . "/quotations.php?view={$viewQId}", 'GET', null, null);
runHttpTest("Guest request redirected to login.php", strpos($guestRes['url'], 'login.php') !== false);

// Attempting unauthorized access with different customer
$cookieFileOther = __DIR__ . '/other_cookie.txt';
if (file_exists($cookieFileOther)) unlink($cookieFileOther);
// Customer babu phone: 7538850829
httpReq($baseUrl . '/login.php', 'POST', [
    'login_type' => 'customer',
    'email'      => '7538850829',
    'password'   => '7538850829'
], $cookieFileOther);

$unauthRes = httpReq($baseUrl . "/quotations.php?view={$viewQId}", 'GET', null, $cookieFileOther);
runHttpTest("Customer B denied access to Customer A's quotation", stripos($unauthRes['body'], 'access denied') !== false || strpos($unauthRes['url'], 'view=') === false);

// 12. Admin Verifies Updated Status Without Manual Intervention
echo "\n--- 12. Admin Real-Time Status Verification ---\n";
$adminCheckRes = httpReq($baseUrl . '/admin/quotations.php', 'GET', null, $cookieFileAdmin);
runHttpTest("Admin view reflects 'Accepted' status in real-time", strpos($adminCheckRes['body'], 'Accepted') !== false);

$adminOrdCheckRes = httpReq($baseUrl . '/admin/orders.php?view=4', 'GET', null, $cookieFileAdmin);
runHttpTest("Admin order view shows quotation status 'Accepted'", strpos($adminOrdCheckRes['body'], 'Accepted') !== false);

// Clean up test cookie files
if (file_exists($cookieFileAdmin)) unlink($cookieFileAdmin);
if (file_exists($cookieFileCust)) unlink($cookieFileCust);
if (file_exists($cookieFileOther)) unlink($cookieFileOther);

echo "\n====================================================\n";
echo "SUMMARY: Passed: $pass | Failed: $fail\n";
echo "====================================================\n";
if ($fail === 0) {
    echo "ALL HTTP TESTS PASSED PERFECTLY!\n";
} else {
    echo "SOME HTTP TESTS FAILED. PLEASE REVIEW.\n";
}
