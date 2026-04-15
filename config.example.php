<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => '127.0.0.1',
        'user' => 'your_db_user',
        'password' => 'your_db_password',
        'database' => 'your_database',
        'charset' => 'utf8mb4',
    ],
    'wordpress_db' => [
        'host' => '127.0.0.1',
        'user' => 'your_wp_db_user',
        'password' => 'your_wp_db_password',
        'database' => 'your_wp_database',
        'charset' => 'utf8mb4',
    ],
    'dingg' => [
        'login_url' => 'https://api.dingg.app/api/v1/vendor/login',
        'get_all_business_url' => 'https://api.dingg.app/api/v1/vendor/get_all_business?by_group=false',
        'sales_target_url' => 'https://api.dingg.app/api/v1/vendor/target/all',
        /** sprintf; default Bearer %s. Use "%s" for raw token only in Authorization. */
        'authorization_value' => 'Bearer %s',
        /** Also send posToken: <token> (some vendor endpoints expect it). Set false if redundant. */
        'send_pos_token_header' => true,
        /** When true (default), error_log full vendor/bills (invoice search) URL, HTTP code, and response body (see bootstrap error_log path). */
        'log_invoice_search' => true,
        /** POST JSON { "id": <bill id> } — use {id} in URL for path-style endpoints, or leave empty until Dingg docs confirm */
        'cancel_bill_url' => '',
        /** Set to false only if cURL fails with SSL certificate errors (e.g. local dev). */
        'ssl_verify' => true,
        /** If true (default), failed HTTPS requests retry once with SSL verification off (fixes missing CA bundle on some hosts). */
        'ssl_insecure_retry' => true,
        'isWeb' => true,
        'fcm_token' => '',
        'encryption_key' => 'your_long_random_secret_for_encrypting_pos_token_in_session',
    ],
    'app' => [
        'name' => 'AllureOne',
        'session_name' => 'ALLUREONESESSID',
        'debug' => false,
        /** When true, gift lists only show orders whose billing_location matches the user's branch locality. Default false shows all. */
        'filter_gift_cards_by_branch_locality' => false,
    ],
];
