<?php
/**
 * Comprehensive Test Suite for Rajpriya Customer Type & Customer ID
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

echo "====================================================\n";
echo "RAJPRIYA CUSTOMER TYPE & CUSTOMER ID TEST SUITE\n";
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

// ---------------------------------------------------------
// TEST 1: Register customers with Wholesaler, Distributor, Retailer, Other
// ---------------------------------------------------------
echo "--- TEST 1: Customer ID Format & Customer Type Mapping ---\n";

$testData = [
    [
        'district' => 'Madurai',
        'name'     => 'Babu Husain',
        'type'     => 'Wholesaler',
        'expected_prefix' => 'MAD-BAB-WHO-'
    ],
    [
        'district' => 'Tirunelveli',
        'name'     => 'Rajesh',
        'type'     => 'Distributor',
        'expected_prefix' => 'TIR-RAJ-DIS-'
    ],
    [
        'district' => 'Thoothukudi',
        'name'     => 'Kumar',
        'type'     => 'Retailer',
        'expected_prefix' => 'THO-KUM-RET-'
    ],
    [
        'district' => 'Salem',
        'name'     => 'Anand',
        'type'     => 'Other',
        'expected_prefix' => 'SAL-ANA-OTH-'
    ],
];

$createdUserIds = [];

foreach ($testData as $td) {
    $code = generate_next_customer_code($td['district'], $td['name'], $td['type']);
    $shortCode = get_customer_type_short_code($td['type']);
    
    assertTest(
        str_starts_with($code, $td['expected_prefix']),
        "Code '{$code}' starts with expected prefix '{$td['expected_prefix']}'"
    );

    assertTest(
        preg_match('/^[A-Z]{3}-[A-Z]{3}-[A-Z]{3}-\d{4}$/', $code) === 1,
        "Code '{$code}' conforms to 4-part [3 letters]-[3 letters]-[3 letters]-[4 digits] pattern"
    );

    // Insert user into database to simulate registration
    $dummyPhone = '98' . rand(10000000, 99999999);
    $dummyEmail = 'test_' . strtolower($shortCode) . '_' . rand(100, 999) . '@example.com';
    $qr = generate_qr_code_image_url(get_customer_tracking_url($code), 200);

    $stmt = $db->prepare("
        INSERT INTO users (customer_code, qr_code, name, email, phone, city, district, customer_type, password, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'testpass123', 'active')
    ");
    $stmt->execute([
        $code, $qr, $td['name'], $dummyEmail, $dummyPhone, $td['district'], $td['district'], $td['type']
    ]);
    $userId = (int)$db->lastInsertId();
    $createdUserIds[$td['type']] = [
        'id' => $userId,
        'code' => $code,
        'phone' => $dummyPhone,
        'email' => $dummyEmail,
        'name' => $td['name'],
        'district' => $td['district'],
        'type' => $td['type']
    ];

    echo "Registered {$td['type']}: ID={$userId}, Customer Code={$code}\n";
}

// ---------------------------------------------------------
// TEST 2: Validation of Customer Types
// ---------------------------------------------------------
echo "\n--- TEST 2: Customer Type Validation ---\n";
assertTest(is_valid_customer_type('Wholesaler'), "is_valid_customer_type('Wholesaler') returns true");
assertTest(is_valid_customer_type('Distributor'), "is_valid_customer_type('Distributor') returns true");
assertTest(is_valid_customer_type('Retailer'), "is_valid_customer_type('Retailer') returns true");
assertTest(is_valid_customer_type('Other'), "is_valid_customer_type('Other') returns true");
assertTest(!is_valid_customer_type('InvalidType'), "is_valid_customer_type('InvalidType') returns false");
assertTest(!is_valid_customer_type(''), "is_valid_customer_type('') returns false");
assertTest(!is_valid_customer_type(null), "is_valid_customer_type(null) returns false");

// ---------------------------------------------------------
// TEST 3: Edit customer details & test Customer ID regeneration
// ---------------------------------------------------------
echo "\n--- TEST 3: Edit Details & Regeneration Behavior ---\n";

$wholesalerUser = $createdUserIds['Wholesaler'];
$uId = $wholesalerUser['id'];
$initialCode = $wholesalerUser['code'];

// 3a. Edit without regenerating ID (should preserve existing ID)
$stmt = $db->prepare("UPDATE users SET name = 'Babu Husain Updated', city = 'Madurai South' WHERE id = ?");
$stmt->execute([$uId]);
$checkUser = get_customer_by_code($initialCode);
assertTest(
    $checkUser['customer_code'] === $initialCode,
    "Standard profile edit preserves Customer ID ($initialCode)"
);

// 3b. Explicitly regenerate Customer ID (e.g. changing type to Distributor in Chennai)
$newRegenCode = regenerate_customer_code($uId, 'Chennai', 'Babu Husain', 'Distributor');
assertTest(
    $newRegenCode !== $initialCode,
    "Regeneration produces new code: $newRegenCode (was $initialCode)"
);
assertTest(
    str_starts_with($newRegenCode, 'CHE-BAB-DIS-'),
    "New code starts with 'CHE-BAB-DIS-'"
);

$updatedUser = get_customer_by_code($newRegenCode);
assertTest(
    $updatedUser['customer_type'] === 'Distributor',
    "Customer type updated to 'Distributor'"
);

// 3c. Backwards compatibility: resolving customer using their OLD ID
$resolvedByOld = get_customer_by_code($initialCode);
assertTest(
    $resolvedByOld !== null && (int)$resolvedByOld['id'] === $uId,
    "Searching by old historical Customer ID ($initialCode) resolves user ID $uId"
);

// ---------------------------------------------------------
// TEST 4: Unique Constraint & Collision Prevention
// ---------------------------------------------------------
echo "\n--- TEST 4: Unique Constraint & Safe Generation ---\n";

// Try inserting duplicate customer_code directly into users table (should throw PDOException)
$duplicateExceptionCaught = false;
try {
    $stmt_dup = $db->prepare("
        INSERT INTO users (customer_code, name, phone, password, customer_type)
        VALUES (?, 'Duplicate User', '9999999999', 'pass', 'Retailer')
    ");
    $stmt_dup->execute([$newRegenCode]);
} catch (PDOException $e) {
    $duplicateExceptionCaught = true;
}
assertTest($duplicateExceptionCaught, "Database UNIQUE constraint prevents duplicate customer_code in users");

// Test that generate_next_customer_code never returns an existing code
$nextCandidate = generate_next_customer_code('Chennai', 'Babu Husain', 'Distributor');
assertTest(
    $nextCandidate !== $newRegenCode,
    "generate_next_customer_code automatically skips existing code ($newRegenCode) and generates unique $nextCandidate"
);

// ---------------------------------------------------------
// TEST 5: Login by Customer ID, Phone, and Email
// ---------------------------------------------------------
echo "\n--- TEST 5: Login with Customer ID, Phone, Email ---\n";

$retailer = $createdUserIds['Retailer'];
$stmt_login_code = $db->prepare("SELECT * FROM users WHERE (email = ? OR phone = ? OR customer_code = ?) AND status = 'active' LIMIT 1");

$stmt_login_code->execute([$retailer['code'], $retailer['code'], $retailer['code']]);
$userByCode = $stmt_login_code->fetch(PDO::FETCH_ASSOC);
assertTest($userByCode && (int)$userByCode['id'] === $retailer['id'], "Login lookup succeeds with Customer ID ({$retailer['code']})");

$stmt_login_code->execute([$retailer['phone'], $retailer['phone'], $retailer['phone']]);
$userByPhone = $stmt_login_code->fetch(PDO::FETCH_ASSOC);
assertTest($userByPhone && (int)$userByPhone['id'] === $retailer['id'], "Login lookup succeeds with Mobile Phone");

$stmt_login_code->execute([$retailer['email'], $retailer['email'], $retailer['email']]);
$userByEmail = $stmt_login_code->fetch(PDO::FETCH_ASSOC);
assertTest($userByEmail && (int)$userByEmail['id'] === $retailer['id'], "Login lookup succeeds with Email");

// ---------------------------------------------------------
// TEST 6: Order Creation & Historical Preservation
// ---------------------------------------------------------
echo "\n--- TEST 6: Orders & Invoices Historical Preservation ---\n";

$distributor = $createdUserIds['Distributor'];
$ordNum = 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);

$stmt_ord = $db->prepare("
    INSERT INTO orders (
        order_number, user_id, customer_code, customer_type, customer_name, customer_email, customer_phone,
        shipping_address, city, district, state, pincode, subtotal, grand_total, order_status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, '123 Test St', 'Tirunelveli', 'Tirunelveli', 'Tamil Nadu', '627001', 500.00, 500.00, 'Pending')
");
$stmt_ord->execute([
    $ordNum, $distributor['id'], $distributor['code'], $distributor['type'], $distributor['name'], $distributor['email'], $distributor['phone']
]);
$newOrdId = (int)$db->lastInsertId();

$history1 = get_customer_orders_history($distributor['code']);
assertTest(count($history1) >= 1, "Order history contains order placed with Customer ID ({$distributor['code']})");

// Now regenerate the distributor's Customer ID
$distNewCode = regenerate_customer_code($distributor['id'], 'Salem', 'Rajesh', 'Wholesaler');

// Verify past order still has the historical snapshot in orders table
$stmt_o_check = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmt_o_check->execute([$newOrdId]);
$ordSnapshot = $stmt_o_check->fetch(PDO::FETCH_ASSOC);
assertTest(
    $ordSnapshot['customer_code'] === $distributor['code'],
    "Historical order invoice snapshot retains original customer_code ({$distributor['code']})"
);

// Verify customer order history still displays the order even when searched by NEW code
$history2 = get_customer_orders_history($distNewCode);
assertTest(
    count($history2) >= 1 && (int)$history2[0]['id'] === $newOrdId,
    "Order history queried by NEW Customer ID ({$distNewCode}) still returns historical order (#{$newOrdId})"
);

// Verify customer order history when queried by OLD code
$history3 = get_customer_orders_history($distributor['code']);
assertTest(
    count($history3) >= 1 && (int)$history3[0]['id'] === $newOrdId,
    "Order history queried by OLD Customer ID ({$distributor['code']}) still returns historical order (#{$newOrdId})"
);

// Clean up test data safely
echo "\n--- Cleaning up temporary test accounts and test order ---\n";
$db->exec("DELETE FROM orders WHERE id = $newOrdId");
foreach ($createdUserIds as $u) {
    $uid = (int)$u['id'];
    $db->exec("DELETE FROM users WHERE id = $uid");
}
echo "Cleaned up test data.\n";

echo "\n====================================================\n";
echo "SUMMARY: Passed: $passCount | Failed: $failCount\n";
echo "====================================================\n";

if ($failCount === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
} else {
    echo "SOME TESTS FAILED!\n";
    exit(1);
}
