<?php
/**
 * WhatsApp Cloud API Integration Service for RAJPRIYA Notebooks.
 * Uses official Meta WhatsApp Cloud API via PHP cURL.
 * Supports PDF Document Upload, Direct Document Delivery, and Template Delivery.
 */

class WhatsAppService {
    private $accessToken;
    private $phoneNumberId;
    private $graphVersion;
    private $templateName;

    public function __construct($accessToken = null, $phoneNumberId = null, $graphVersion = null) {
        $this->accessToken = $accessToken ?: WHATSAPP_ACCESS_TOKEN;
        $this->phoneNumberId = $phoneNumberId ?: WHATSAPP_PHONE_NUMBER_ID;
        $this->graphVersion = $graphVersion ?: WHATSAPP_GRAPH_API_VERSION;
        $this->templateName = defined('WHATSAPP_TEMPLATE_NAME') ? WHATSAPP_TEMPLATE_NAME : 'invoice_delivery';
    }

    /**
     * Normalizes phone number into international E.164-compatible format without '+' or spaces.
     * Defaults to India country code (91) for 10-digit mobile numbers.
     */
    public static function formatPhoneNumber($phone, $defaultCountryCode = '91') {
        if (empty($phone)) {
            return '';
        }
        // Remove all non-digits
        $cleaned = preg_replace('/[^0-9]/', '', (string)$phone);
        if (empty($cleaned)) {
            return '';
        }

        // If starts with '0' (e.g. 09842746472)
        if (strlen($cleaned) === 11 && strpos($cleaned, '0') === 0) {
            $cleaned = substr($cleaned, 1);
        }

        // Standard Indian 10-digit number
        if (strlen($cleaned) === 10) {
            return $defaultCountryCode . $cleaned;
        }

        // Already has 12 digits starting with 91
        if (strlen($cleaned) === 12 && strpos($cleaned, '91') === 0) {
            return $cleaned;
        }

        // If 11 or 12+ digits with valid country code prefix
        if (strlen($cleaned) >= 11 && strlen($cleaned) <= 15) {
            return $cleaned;
        }

        return '';
    }

    /**
     * Executes HTTP cURL request to Meta Graph API.
     */
    private function executeRequest($endpoint, $postData, $isMultipart = false, $timeout = 35) {
        $url = "https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}/" . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'User-Agent: RajpriyaNotebooks-WhatsAppClient/1.0'
        ];

        if ($isMultipart) {
            // Let cURL set multipart Content-Type header with proper boundary
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        } else {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postData) ? json_encode($postData) : $postData);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0) {
            return [
                'success' => false,
                'http_code' => 0,
                'error' => "cURL Communication Error ({$curlErrno}): {$curlError}",
                'raw' => null
            ];
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'http_code' => $httpCode,
                'data' => $decoded,
                'raw' => $response
            ];
        }

        // Extract detailed Meta error message if available
        $metaErrorMsg = 'Unknown API Error';
        $metaErrorCode = null;
        if (is_array($decoded) && isset($decoded['error'])) {
            $metaErrorMsg = $decoded['error']['message'] ?? $metaErrorMsg;
            $metaErrorCode = $decoded['error']['code'] ?? null;
            if (!empty($decoded['error']['error_data']['details'])) {
                $metaErrorMsg .= ' — ' . $decoded['error']['error_data']['details'];
            }
        }

        return [
            'success' => false,
            'http_code' => $httpCode,
            'error_code' => $metaErrorCode,
            'error' => "Meta API Error (HTTP {$httpCode}): {$metaErrorMsg}",
            'raw' => $response
        ];
    }

    /**
     * Uploads a document to Meta WhatsApp Cloud API Media endpoint.
     * POST /{PHONE_NUMBER_ID}/media
     */
    public function uploadMedia($filePath, $mimeType = 'application/pdf') {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ['success' => false, 'error' => "File not found or unreadable: {$filePath}"];
        }

        if (filesize($filePath) > 100 * 1024 * 1024) { // WhatsApp 100MB document limit
            return ['success' => false, 'error' => "File exceeds WhatsApp 100MB limit (" . round(filesize($filePath) / (1024 * 1024), 2) . " MB)"];
        }

        $cFile = new CURLFile($filePath, $mimeType, basename($filePath));
        $postData = [
            'messaging_product' => 'whatsapp',
            'type'              => $mimeType,
            'file'              => $cFile
        ];

        $res = $this->executeRequest('media', $postData, true, 60);
        if ($res['success'] && !empty($res['data']['id'])) {
            return [
                'success'  => true,
                'media_id' => $res['data']['id'],
                'raw'      => $res['raw']
            ];
        }

        return [
            'success' => false,
            'error'   => $res['error'] ?? 'Failed to upload media to WhatsApp Cloud API',
            'raw'     => $res['raw'] ?? null
        ];
    }

    /**
     * Sends a PDF document message to customer via uploaded Media ID.
     * POST /{PHONE_NUMBER_ID}/messages
     */
    public function sendDocumentByMediaId($recipientPhone, $mediaId, $filename, $caption = '') {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $recipientPhone,
            'type'              => 'document',
            'document'          => [
                'id'       => $mediaId,
                'filename' => $filename,
                'caption'  => $caption
            ]
        ];

        $res = $this->executeRequest('messages', $payload, false);
        if ($res['success'] && !empty($res['data']['messages'][0]['id'])) {
            return [
                'success'    => true,
                'message_id' => $res['data']['messages'][0]['id'],
                'status'     => 'accepted',
                'raw'        => $res['raw']
            ];
        }

        return [
            'success'    => false,
            'error_code' => $res['error_code'] ?? null,
            'error'      => $res['error'] ?? 'Failed to send WhatsApp document message',
            'raw'        => $res['raw'] ?? null
        ];
    }

    /**
     * Sends a Document Message using a WhatsApp Business Pre-Approved Template.
     * Complies with Meta 24-hour customer window policies.
     */
    public function sendDocumentTemplate($recipientPhone, $mediaId, $filename, $templateName = null, $bodyParams = []) {
        $tpl = $templateName ?: $this->templateName;
        if (empty($tpl)) {
            return ['success' => false, 'error' => 'No WhatsApp template name configured'];
        }

        $components = [
            [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => 'document',
                        'document' => [
                            'id'       => $mediaId,
                            'filename' => $filename
                        ]
                    ]
                ]
            ]
        ];

        if (!empty($bodyParams)) {
            $bodyParameters = [];
            foreach ($bodyParams as $bp) {
                $bodyParameters[] = [
                    'type' => 'text',
                    'text' => (string)$bp
                ];
            }
            $components[] = [
                'type'       => 'body',
                'parameters' => $bodyParameters
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $recipientPhone,
            'type'              => 'template',
            'template'          => [
                'name'       => $tpl,
                'language'   => [
                    'code' => 'en_US'
                ],
                'components' => $components
            ]
        ];

        $res = $this->executeRequest('messages', $payload, false);
        if ($res['success'] && !empty($res['data']['messages'][0]['id'])) {
            return [
                'success'    => true,
                'message_id' => $res['data']['messages'][0]['id'],
                'status'     => 'accepted',
                'raw'        => $res['raw']
            ];
        }

        return [
            'success' => false,
            'error'   => $res['error'] ?? 'Failed to send WhatsApp template message',
            'raw'     => $res['raw'] ?? null
        ];
    }

    /**
     * Full End-to-End Orchestrator:
     * Dispatches the final approved invoice PDF to customer's registered WhatsApp number.
     * Handles duplicate checks, logging, upload, direct send, and template fallback.
     */
    public function sendInvoicePdf($orderId, $options = []) {
        $db = getDB();
        $oid = (int)$orderId;
        $forceRetry = !empty($options['force_retry']);

        // 1. Fetch Order and Customer Data
        $stmtOrd = $db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmtOrd->execute([$oid]);
        $order = $stmtOrd->fetch();
        if (!$order) {
            return ['success' => false, 'error' => "Order #{$oid} not found.", 'message' => "Order #{$oid} not found."];
        }

        // Fetch associated quotation if available
        $stmtQ = $db->prepare("SELECT id FROM quotations WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $stmtQ->execute([$oid]);
        $quotation = $stmtQ->fetch();
        $qid = $quotation ? (int)$quotation['id'] : null;

        $customerName = !empty($order['customer_name']) ? $order['customer_name'] : 'Valued Customer';
        $customerPhone = !empty($order['customer_phone']) ? $order['customer_phone'] : '';
        $customerId = !empty($order['user_id']) ? (int)$order['user_id'] : null;
        $orderNum = $order['order_number'];

        // 2. Validate & Normalize Phone Number
        $formattedPhone = self::formatPhoneNumber($customerPhone);
        if (empty($formattedPhone) || strlen($formattedPhone) < 10) {
            $errMsg = "Missing or invalid customer phone number: '{$customerPhone}'.";
            self::logAttempt($db, [
                'order_id'        => $oid,
                'quotation_id'    => $qid,
                'customer_id'     => $customerId,
                'recipient_phone' => $customerPhone ?: 'MISSING',
                'customer_name'   => $customerName,
                'invoice_number'  => $orderNum,
                'status'          => 'failed',
                'delivery_status' => 'failed',
                'error_message'   => $errMsg
            ]);
            return ['success' => false, 'error' => $errMsg, 'message' => $errMsg];
        }

        // 3. Prevent Duplicate Automatic Sends
        if (!$forceRetry) {
            $stmtDup = $db->prepare("
                SELECT id, message_id, status, delivery_status, sent_at 
                FROM whatsapp_delivery_logs 
                WHERE order_id = ? AND status IN ('sent', 'delivered', 'read')
                ORDER BY id DESC LIMIT 1
            ");
            $stmtDup->execute([$oid]);
            $existing = $stmtDup->fetch();
            if ($existing) {
                return [
                    'success'   => true,
                    'duplicate' => true,
                    'skipped'   => true,
                    'message'   => "Invoice for Order #{$orderNum} already sent via WhatsApp (Status: {$existing['status']}).",
                    'log_id'    => $existing['id'],
                    'message_id'=> $existing['message_id']
                ];
            }
        }

        // 4. Ensure Final Invoice PDF Exists on Disk (generate if missing)
        $safeOrderNum = preg_replace('/[^A-Za-z0-9_-]/', '', $orderNum);
        $pdfRelativePath = 'uploads/invoices/Invoice-' . $safeOrderNum . '.pdf';
        $pdfAbsolutePath = __DIR__ . '/../' . $pdfRelativePath;

        if (!file_exists($pdfAbsolutePath) || filesize($pdfAbsolutePath) === 0) {
            if (function_exists('generate_order_invoice_pdf')) {
                $genRes = generate_order_invoice_pdf($oid);
                if (!$genRes['success'] || !file_exists($pdfAbsolutePath)) {
                    $errMsg = "Invoice PDF could not be generated: " . ($genRes['message'] ?? 'Unknown PDF error');
                    self::logAttempt($db, [
                        'order_id'        => $oid,
                        'quotation_id'    => $qid,
                        'customer_id'     => $customerId,
                        'recipient_phone' => $formattedPhone,
                        'customer_name'   => $customerName,
                        'invoice_number'  => $orderNum,
                        'status'          => 'failed',
                        'delivery_status' => 'failed',
                        'error_message'   => $errMsg
                    ]);
                    return ['success' => false, 'message' => $errMsg];
                }
            } else {
                $errMsg = "Invoice PDF missing at {$pdfRelativePath}";
                self::logAttempt($db, [
                    'order_id'        => $oid,
                    'quotation_id'    => $qid,
                    'customer_id'     => $customerId,
                    'recipient_phone' => $formattedPhone,
                    'customer_name'   => $customerName,
                    'invoice_number'  => $orderNum,
                    'status'          => 'failed',
                    'delivery_status' => 'failed',
                    'error_message'   => $errMsg
                ]);
                return ['success' => false, 'message' => $errMsg];
            }
        }

        $fileUrl = function_exists('get_site_url') ? (get_site_url() . '/' . $pdfRelativePath) : ('/uploads/invoices/Invoice-' . $safeOrderNum . '.pdf');

        // 5. Check if Meta WhatsApp Cloud API credentials are configured
        if (!is_whatsapp_configured()) {
            $errMsg = "WhatsApp Cloud API credentials not configured (Access Token or Phone ID empty).";
            $logId = self::logAttempt($db, [
                'order_id'        => $oid,
                'quotation_id'    => $qid,
                'customer_id'     => $customerId,
                'recipient_phone' => $formattedPhone,
                'customer_name'   => $customerName,
                'invoice_number'  => $orderNum,
                'file_path'       => $pdfRelativePath,
                'file_url'        => $fileUrl,
                'status'          => 'failed',
                'delivery_status' => 'unconfigured',
                'error_message'   => $errMsg
            ]);
            return [
                'success' => false,
                'configured' => false,
                'log_id'  => $logId,
                'message' => $errMsg
            ];
        }

        // 6. Record Initial Upload Attempt in Log
        $logId = self::logAttempt($db, [
            'order_id'        => $oid,
            'quotation_id'    => $qid,
            'customer_id'     => $customerId,
            'recipient_phone' => $formattedPhone,
            'customer_name'   => $customerName,
            'invoice_number'  => $orderNum,
            'file_path'       => $pdfRelativePath,
            'file_url'        => $fileUrl,
            'status'          => 'uploading',
            'delivery_status' => 'uploading'
        ]);

        // 7. Step 1: Upload Media to WhatsApp Cloud API
        $uploadRes = $this->uploadMedia($pdfAbsolutePath, 'application/pdf');
        if (!$uploadRes['success']) {
            $errMsg = "WhatsApp media upload failed: " . $uploadRes['error'];
            self::updateLog($db, $logId, [
                'status'          => 'failed',
                'delivery_status' => 'failed',
                'error_message'   => $errMsg,
                'raw_response'    => $uploadRes['raw'] ?? null
            ]);
            return ['success' => false, 'log_id' => $logId, 'message' => $errMsg];
        }

        $mediaId = $uploadRes['media_id'];
        $pdfFilename = 'Invoice-' . $safeOrderNum . '.pdf';
        $caption = "Dear {$customerName},\nHere is your official Tax Invoice for Order #{$orderNum} from RAJPRIYA NOTEBOOKS.\nTotal: " . (function_exists('format_price') ? format_price($order['grand_total']) : ('₹' . number_format($order['grand_total'], 2))) . ".\nThank you for choosing Rajpriya Notebooks!";

        // 8. Step 2: Send Document Message via Media ID
        $sendRes = $this->sendDocumentByMediaId($formattedPhone, $mediaId, $pdfFilename, $caption);

        // Check if Meta returned error 131047 (Outside 24-hr customer window -> requires template)
        $isTemplateRequired = (!$sendRes['success'] && isset($sendRes['error_code']) && (int)$sendRes['error_code'] === 131047);

        if ($isTemplateRequired && !empty($this->templateName)) {
            // Attempt approved template send fallback
            $bodyParams = [$customerName, $orderNum, number_format((float)$order['grand_total'], 2)];
            $tplRes = $this->sendDocumentTemplate($formattedPhone, $mediaId, $pdfFilename, $this->templateName, $bodyParams);

            if ($tplRes['success']) {
                self::updateLog($db, $logId, [
                    'media_id'        => $mediaId,
                    'message_id'      => $tplRes['message_id'],
                    'status'          => 'sent',
                    'delivery_status' => 'accepted',
                    'send_type'       => 'template',
                    'template_name'   => $this->templateName,
                    'raw_response'    => $tplRes['raw'] ?? null,
                    'sent_at'         => date('Y-m-d H:i:s')
                ]);
                return [
                    'success'    => true,
                    'log_id'     => $logId,
                    'message_id' => $tplRes['message_id'],
                    'media_id'   => $mediaId,
                    'send_type'  => 'template',
                    'message'    => "Invoice PDF sent successfully to customer WhatsApp via template #{$this->templateName}."
                ];
            } else {
                $sendRes = $tplRes; // Capture template failure details
            }
        }

        if ($sendRes['success']) {
            self::updateLog($db, $logId, [
                'media_id'        => $mediaId,
                'message_id'      => $sendRes['message_id'],
                'status'          => 'sent',
                'delivery_status' => 'accepted',
                'send_type'       => 'document_media',
                'raw_response'    => $sendRes['raw'] ?? null,
                'sent_at'         => date('Y-m-d H:i:s')
            ]);
            return [
                'success'    => true,
                'log_id'     => $logId,
                'message_id' => $sendRes['message_id'],
                'media_id'   => $mediaId,
                'send_type'  => 'document_media',
                'message'    => "Invoice PDF sent successfully to customer WhatsApp ({$formattedPhone})."
            ];
        }

        // Delivery failed
        $failMsg = "WhatsApp document dispatch failed: " . ($sendRes['error'] ?? 'Unknown error');
        self::updateLog($db, $logId, [
            'media_id'        => $mediaId,
            'status'          => 'failed',
            'delivery_status' => 'failed',
            'error_message'   => $failMsg,
            'raw_response'    => $sendRes['raw'] ?? null
        ]);

        return ['success' => false, 'log_id' => $logId, 'message' => $failMsg];
    }

    /**
     * Helper to log initial or standalone attempt into whatsapp_delivery_logs.
     */
    public static function logAttempt(PDO $db, array $data) {
        try {
            $stmt = $db->prepare("
                INSERT INTO whatsapp_delivery_logs (
                    order_id, quotation_id, customer_id, recipient_phone, customer_name,
                    invoice_number, file_path, file_url, media_id, message_id,
                    status, delivery_status, send_type, template_name, error_message,
                    raw_response, sent_at, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, NOW(), NOW()
                )
            ");
            $stmt->execute([
                $data['order_id'],
                $data['quotation_id'] ?? null,
                $data['customer_id'] ?? null,
                $data['recipient_phone'] ?? '',
                $data['customer_name'] ?? '',
                $data['invoice_number'] ?? '',
                $data['file_path'] ?? null,
                $data['file_url'] ?? null,
                $data['media_id'] ?? null,
                $data['message_id'] ?? null,
                $data['status'] ?? 'queued',
                $data['delivery_status'] ?? 'pending',
                $data['send_type'] ?? 'document_media',
                $data['template_name'] ?? null,
                $data['error_message'] ?? null,
                $data['raw_response'] ?? null,
                $data['sent_at'] ?? null
            ]);
            return (int)$db->lastInsertId();
        } catch (Exception $e) {
            error_log("WhatsAppService::logAttempt Error: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Helper to update an existing log entry.
     */
    public static function updateLog(PDO $db, $logId, array $fields) {
        if (empty($logId)) {
            return;
        }
        try {
            $setClauses = [];
            $values = [];
            foreach ($fields as $col => $val) {
                $setClauses[] = "`{$col}` = ?";
                $values[] = $val;
            }
            $values[] = (int)$logId;

            $sql = "UPDATE whatsapp_delivery_logs SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
        } catch (Exception $e) {
            error_log("WhatsAppService::updateLog Error: " . $e->getMessage());
        }
    }

    /**
     * Updates delivery status based on incoming Webhook payload from Meta.
     */
    public static function handleWebhookStatusUpdate(PDO $db, array $statusObj) {
        $msgId = $statusObj['id'] ?? '';
        $newStatus = $statusObj['status'] ?? '';
        if (empty($msgId) || empty($newStatus)) {
            return false;
        }

        $fields = [
            'delivery_status' => $newStatus,
            'webhook_payload' => json_encode($statusObj)
        ];

        if ($newStatus === 'delivered') {
            $fields['status'] = 'delivered';
            $fields['delivered_at'] = date('Y-m-d H:i:s');
        } elseif ($newStatus === 'read') {
            $fields['status'] = 'read';
            $fields['read_at'] = date('Y-m-d H:i:s');
        } elseif ($newStatus === 'failed') {
            $fields['status'] = 'failed';
            if (!empty($statusObj['errors'])) {
                $err = $statusObj['errors'][0];
                $fields['error_message'] = "Webhook Failure: " . ($err['title'] ?? ($err['message'] ?? 'Delivery failed'));
            }
        } elseif ($newStatus === 'sent') {
            $fields['status'] = 'sent';
            $fields['sent_at'] = date('Y-m-d H:i:s');
        }

        try {
            $setClauses = [];
            $params = [];
            foreach ($fields as $col => $val) {
                $setClauses[] = "`{$col}` = ?";
                $params[] = $val;
            }
            $params[] = $msgId;

            $stmt = $db->prepare("UPDATE whatsapp_delivery_logs SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE message_id = ?");
            $stmt->execute($params);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("WhatsAppService::handleWebhookStatusUpdate Error: " . $e->getMessage());
            return false;
        }
    }
}
