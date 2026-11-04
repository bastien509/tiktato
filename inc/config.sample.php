<?php
// Copy to config.php on the server. config.php is denied by inc/.htaccess
// and ignored by git. Never put these values in JS, HTML or error text.
defined('TTT') || exit;

return [
    'app' => [
        'name'     => 'TikTato',
        // Absolute URL of the site, no trailing slash. Used for PayPal return URLs.
        'base_url' => 'https://petiht.com/tiktato',
        'debug'    => false,
    ],
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'DB_NAME',
        'user' => 'DB_USER',
        'pass' => 'DB_PASSWORD',
    ],
    'ai' => [
        // Anthropic API key for Tato, the AI wallet agent (server-side only).
        'anthropic_api_key' => '',
    ],
    'paypal' => [
        'mode'       => 'sandbox',   // 'sandbox' or 'live'
        'client_id'  => '',
        'secret'     => '',
        'webhook_id' => '',          // optional: from the PayPal app's webhook settings
        'currency'   => 'USD',
    ],
];
