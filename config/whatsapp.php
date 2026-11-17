<?php
/**
 * WhatsApp Cloud API Server Configuration for Rajpriya Notebooks.
 * Securely loads credentials from environment (.env) or database settings table.
 * Never expose credentials in client-side scripts.
 */

if (!function_exists('load_rajpriya_env')) {
    function load_rajpriya_env($filePath) {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return;
        }
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip surrounding quotes if present
                if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                    (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                    $value = substr($value, 1, -1);
                }
                if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}

// Load .env if present
load_rajpriya_env(__DIR__ . '/../.env');

if (!function_exists('get_whatsapp_config')) {
    /**
     * Resolves WhatsApp configuration parameter with fallback precedence:
     * 1. Environment variables ($_ENV, $_SERVER, getenv)
     * 2. Database `settings` table
     * 3. Default fallback
     */
    function get_whatsapp_config($key, $default = '') {
        $envKey = 'WHATSAPP_' . strtoupper($key);
        $val = getenv($envKey);
        if ($val !== false && $val !== '') {
            return $val;
        }
        if (isset($_ENV[$envKey]) && $_ENV[$envKey] !== '') {
            return $_ENV[$envKey];
        }
        if (isset($_SERVER[$envKey]) && $_SERVER[$envKey] !== '') {
            return $_SERVER[$envKey];
        }

        // DB Fallback
        if (function_exists('get_setting')) {
            $dbKey = 'whatsapp_' . strtolower($key);
            $dbVal = get_setting($dbKey, null);
            if ($dbVal !== null && $dbVal !== '') {
                return $dbVal;
            }
        }

        return $default;
    }
}

// Define central constants
if (!defined('WHATSAPP_ENABLED')) {
    $waEnabledVal = get_whatsapp_config('enabled', '1');
    define('WHATSAPP_ENABLED', in_array(strtolower((string)$waEnabledVal), ['1', 'true', 'yes', 'on'], true));
}

if (!defined('WHATSAPP_ACCESS_TOKEN')) {
    define('WHATSAPP_ACCESS_TOKEN', get_whatsapp_config('access_token', ''));
}

if (!defined('WHATSAPP_PHONE_NUMBER_ID')) {
    define('WHATSAPP_PHONE_NUMBER_ID', get_whatsapp_config('phone_number_id', ''));
}

if (!defined('WHATSAPP_GRAPH_API_VERSION')) {
    define('WHATSAPP_GRAPH_API_VERSION', get_whatsapp_config('graph_api_version', get_whatsapp_config('graph_version', 'v20.0')));
}

if (!defined('WHATSAPP_WEBHOOK_VERIFY_TOKEN')) {
    define('WHATSAPP_WEBHOOK_VERIFY_TOKEN', get_whatsapp_config('webhook_verify_token', 'rajpriya_wa_secure_webhook_token_2026'));
}

if (!defined('WHATSAPP_TEMPLATE_NAME')) {
    define('WHATSAPP_TEMPLATE_NAME', get_whatsapp_config('template_name', 'invoice_delivery'));
}

if (!defined('WHATSAPP_AUTO_SEND')) {
    $autoVal = get_whatsapp_config('auto_send', '1');
    define('WHATSAPP_AUTO_SEND', in_array(strtolower((string)$autoVal), ['1', 'true', 'yes', 'on'], true));
}

if (!function_exists('is_whatsapp_configured')) {
    /**
     * Checks if valid Meta WhatsApp Cloud API credentials have been provided.
     */
    function is_whatsapp_configured() {
        if (!WHATSAPP_ENABLED) {
            return false;
        }
        $token = trim((string)WHATSAPP_ACCESS_TOKEN);
        $phoneId = trim((string)WHATSAPP_PHONE_NUMBER_ID);

        if (empty($token) || empty($phoneId)) {
            return false;
        }
        if (strpos($token, 'YOUR_') === 0 || strpos($phoneId, 'YOUR_') === 0) {
            return false;
        }
        return true;
    }
}
