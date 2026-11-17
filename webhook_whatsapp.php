<?php
/**
 * WhatsApp Cloud API Webhook Endpoint for RAJPRIYA Notebooks.
 * 
 * Handles:
 * 1. Webhook Handshake Verification (GET): Verifies hub.verify_token with Meta.
 * 2. Event Delivery Status Updates (POST): Tracks message status transitions
 *    (accepted, sent, delivered, read, failed).
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/WhatsAppService.php';

// Disable error display on webhook output to prevent protocol interference
ini_set('display_errors', 0);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// -------------------------------------------------------------------------
// 1. WEBHOOK VERIFICATION HANDSHAKE (GET)
// -------------------------------------------------------------------------
if ($method === 'GET') {
    // Note: PHP automatically translates dots to underscores in $_GET keys (hub.mode -> hub_mode)
    $mode = $_GET['hub_mode'] ?? ($_GET['hub.mode'] ?? '');
    $token = $_GET['hub_verify_token'] ?? ($_GET['hub.verify_token'] ?? '');
    $challenge = $_GET['hub_challenge'] ?? ($_GET['hub.challenge'] ?? '');

    $expectedToken = defined('WHATSAPP_WEBHOOK_VERIFY_TOKEN') ? WHATSAPP_WEBHOOK_VERIFY_TOKEN : 'rajpriya_wa_secure_webhook_token_2026';

    if ($mode === 'subscribe' && !empty($token) && hash_equals($expectedToken, $token)) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit();
    } else {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden: Verification token mismatch.";
        exit();
    }
}

// -------------------------------------------------------------------------
// 2. WEBHOOK STATUS UPDATES LISTENER (POST)
// -------------------------------------------------------------------------
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Empty request body']);
        exit();
    }

    $payload = json_decode($rawInput, true);
    if (!is_array($payload) || !isset($payload['object'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
        exit();
    }

    $db = getDB();
    $processedCount = 0;

    // Process WhatsApp Business Account Event Entries
    if ($payload['object'] === 'whatsapp_business_account' && !empty($payload['entry'])) {
        foreach ($payload['entry'] as $entry) {
            if (empty($entry['changes'])) {
                continue;
            }
            foreach ($entry['changes'] as $change) {
                if (($change['field'] ?? '') !== 'messages' || empty($change['value'])) {
                    continue;
                }

                $value = $change['value'];

                // Status updates (sent, delivered, read, failed)
                if (!empty($value['statuses']) && is_array($value['statuses'])) {
                    foreach ($value['statuses'] as $statusObj) {
                        $updated = WhatsAppService::handleWebhookStatusUpdate($db, $statusObj);
                        if ($updated) {
                            $processedCount++;
                        }
                    }
                }
            }
        }
    }

    // Always respond with 200 OK so Meta does not retry delivered updates
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'   => true,
        'processed' => $processedCount
    ]);
    exit();
}

// Any other HTTP method
http_response_code(405);
echo "Method Not Allowed";
exit();
