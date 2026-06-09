<?php
declare(strict_types=1);

function pwa_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $config = require __DIR__ . '/../config.php';
    $cfg = is_array($config['pwa'] ?? null) ? $config['pwa'] : [];

    return $cfg;
}

function pwa_vapid_public_key(): string
{
    return trim((string) (pwa_config()['vapid_public_key'] ?? ''));
}

function pwa_vapid_private_key(): string
{
    return trim((string) (pwa_config()['vapid_private_key'] ?? ''));
}

function pwa_vapid_subject(): string
{
    $subject = trim((string) (pwa_config()['vapid_subject'] ?? ''));
    if ($subject === '') {
        return 'mailto:support@allure.com';
    }

    return $subject;
}

function pwa_icon_path(string $key, string $default): string
{
    $path = trim((string) (pwa_config()[$key] ?? ''));

    return $path !== '' ? $path : $default;
}

function pwa_icon_192_url(): string
{
    if (!function_exists('allureone_url')) {
        require_once __DIR__ . '/app_path.php';
    }

    return allureone_url(pwa_icon_path('icon_192', 'assets/images/pwa-icon-192.png'));
}

function pwa_icon_512_url(): string
{
    if (!function_exists('allureone_url')) {
        require_once __DIR__ . '/app_path.php';
    }

    return allureone_url(pwa_icon_path('icon_512', 'assets/images/pwa-icon-512.png'));
}

function pwa_apple_touch_icon_url(): string
{
    if (!function_exists('allureone_url')) {
        require_once __DIR__ . '/app_path.php';
    }

    return allureone_url(pwa_icon_path('apple_touch_icon', 'assets/images/pwa-icon-192.png'));
}

function pwa_notification_icon_url(): string
{
    if (!function_exists('allureone_url')) {
        require_once __DIR__ . '/app_path.php';
    }

    return allureone_url(pwa_icon_path('notification_icon', 'assets/images/pwa-icon-192.png'));
}

/**
 * @return array<string,mixed>
 */
function pwa_manifest_data(): array
{
    if (!function_exists('allureone_url')) {
        require_once __DIR__ . '/app_path.php';
    }
    $config = require __DIR__ . '/../config.php';
    $appName = is_array($config['app'] ?? null) ? (string) ($config['app']['name'] ?? 'AllureOne') : 'AllureOne';
    $pwa = pwa_config();
    $themeColor = trim((string) ($pwa['theme_color'] ?? ''));
    if ($themeColor === '') {
        $themeColor = '#2f5f90';
    }

    return [
        'name' => $appName,
        'short_name' => $appName,
        'description' => $appName . ' staff app',
        'start_url' => allureone_url('login.php'),
        'scope' => allureone_scope(),
        'display' => 'standalone',
        'orientation' => 'portrait-primary',
        'background_color' => '#ffffff',
        'theme_color' => $themeColor,
        'icons' => [
            [
                'src' => pwa_icon_192_url(),
                'sizes' => '192x192',
                'type' => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src' => pwa_icon_512_url(),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'any',
            ],
            [
                'src' => pwa_icon_512_url(),
                'sizes' => '512x512',
                'type' => 'image/png',
                'purpose' => 'maskable',
            ],
        ],
    ];
}

function pwa_ensure_vendor_autoload(): bool
{
    static $loaded = null;
    if ($loaded === true) {
        return true;
    }
    if ($loaded === false) {
        return false;
    }
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        $loaded = false;

        return false;
    }
    try {
        require_once $autoload;
        $loaded = true;

        return true;
    } catch (Throwable $e) {
        error_log('AllureOne vendor autoload failed: ' . $e->getMessage());
        $loaded = false;

        return false;
    }
}

/**
 * @return array{ready:bool, vendor:bool, library:bool, public_key:bool, private_key:bool, issues:list<string>}
 */
function pwa_readiness_details(): array
{
    $vendorFile = __DIR__ . '/../vendor/autoload.php';
    $vendor = is_file($vendorFile);
    $library = false;
    $issues = [];

    if (!$vendor) {
        $issues[] = 'vendor/ folder is missing on the server. Commit and deploy vendor/ (or run composer install on Hostinger).';
    } elseif (!pwa_ensure_vendor_autoload()) {
        $issues[] = 'Composer autoload failed. Check php_errors.log on the server.';
    } elseif (!class_exists(\Minishlink\WebPush\WebPush::class)) {
        $issues[] = 'Web Push library could not be loaded. Ensure vendor/minishlink/web-push exists on the server.';
    } else {
        $library = true;
    }

    $publicKey = pwa_vapid_public_key();
    $privateKey = pwa_vapid_private_key();
    $hasPublic = $publicKey !== '';
    $hasPrivate = $privateKey !== '';

    if (!$hasPublic || !$hasPrivate) {
        $issues[] = 'VAPID keys are missing in server config.php under the pwa section.';
    }

    return [
        'ready' => $vendor && $library && $hasPublic && $hasPrivate,
        'vendor' => $vendor,
        'library' => $library,
        'public_key' => $hasPublic,
        'private_key' => $hasPrivate,
        'issues' => $issues,
    ];
}

function pwa_web_push_available(): bool
{
    return pwa_readiness_details()['ready'];
}

function pwa_prepare_openssl_for_ec_keys(): void
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return;
    }
    $phpDir = dirname(PHP_BINARY);
    foreach ([$phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf', $phpDir . DIRECTORY_SEPARATOR . 'openssl.cnf'] as $conf) {
        $resolved = realpath($conf);
        if ($resolved !== false && is_file($resolved)) {
            putenv('OPENSSL_CONF=' . str_replace('\\', '/', $resolved));

            return;
        }
    }
}

/**
 * @return array{ok:bool, public_key?:string, private_key?:string, error?:string}
 */
function pwa_generate_vapid_keys(): array
{
    if (!extension_loaded('openssl')) {
        return ['ok' => false, 'error' => 'OpenSSL PHP extension is required. Enable extension=openssl in php.ini.'];
    }

    if (pwa_ensure_vendor_autoload() && class_exists(\Minishlink\WebPush\VAPID::class)) {
        try {
            pwa_prepare_openssl_for_ec_keys();
            $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
            $public = trim((string) ($keys['publicKey'] ?? ''));
            $private = trim((string) ($keys['privateKey'] ?? ''));
            if ($public !== '' && $private !== '') {
                return ['ok' => true, 'public_key' => $public, 'private_key' => $private];
            }
        } catch (Throwable $e) {
            // Fall through to openssl_pkey_new.
        }
    }

    pwa_prepare_openssl_for_ec_keys();
    while (openssl_error_string() !== false) {
    }

    $key = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if ($key === false) {
        $opensslErr = '';
        while (($msg = openssl_error_string()) !== false) {
            $opensslErr = $msg;
        }
        $hint = PHP_OS_FAMILY === 'Windows'
            ? ' On Windows, ensure extension=openssl in php.ini, or generate keys on your Hostinger server (Announcements page) or at https://vapidkeys.com/'
            : ' Try generating on your production server or at https://vapidkeys.com/';

        return [
            'ok' => false,
            'error' => 'Could not generate EC key.'
                . ($opensslErr !== '' ? ' OpenSSL: ' . $opensslErr . '.' : '')
                . $hint,
        ];
    }

    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
        return ['ok' => false, 'error' => 'Could not read generated key details.'];
    }

    $publicKey = rtrim(strtr(base64_encode(chr(4) . $details['ec']['x'] . $details['ec']['y']), '+/', '-_'), '=');
    $privateKey = rtrim(strtr(base64_encode(str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '=');

    return ['ok' => true, 'public_key' => $publicKey, 'private_key' => $privateKey];
}

/**
 * @param array{public_key:string, private_key:string} $keys
 */
function pwa_format_vapid_config_snippet(array $keys, string $subject = 'mailto:support@allure.com'): string
{
    return "'pwa' => [\n"
        . "    'vapid_subject' => '" . $subject . "',\n"
        . "    'vapid_public_key' => '" . $keys['public_key'] . "',\n"
        . "    'vapid_private_key' => '" . $keys['private_key'] . "',\n"
        . '],';
}

function pwa_ensure_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $sqlFile = __DIR__ . '/../sql/pwa_tables.sql';
    if (!is_file($sqlFile)) {
        return;
    }
    try {
        $pdo = db();
        $sql = (string) file_get_contents($sqlFile);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            if ($stmt === '' || stripos($stmt, 'SET NAMES') === 0) {
                continue;
            }
            $pdo->exec($stmt);
        }
        pwa_ensure_schema_columns($pdo);
    } catch (Throwable $e) {
        error_log('AllureOne PWA table ensure failed: ' . $e->getMessage());
    }
}

function pwa_ensure_schema_columns(PDO $pdo): void
{
    try {
        $cols = $pdo->query('SHOW COLUMNS FROM allureone_push_subscriptions')->fetchAll(PDO::FETCH_COLUMN);
        if (is_array($cols) && !in_array('content_encoding', $cols, true)) {
            $pdo->exec(
                "ALTER TABLE allureone_push_subscriptions
                 ADD COLUMN content_encoding VARCHAR(32) NOT NULL DEFAULT 'aes128gcm' AFTER auth_key"
            );
        }
    } catch (Throwable $e) {
        error_log('AllureOne PWA schema upgrade failed: ' . $e->getMessage());
    }
}

function pwa_normalize_content_encoding(?string $encoding): string
{
    $encoding = strtolower(trim((string) ($encoding ?? '')));
    if (in_array($encoding, ['aesgcm', 'aes128gcm'], true)) {
        return $encoding;
    }

    return 'aes128gcm';
}

/**
 * @return list<string>
 */
function pwa_content_encoding_candidates(?string $preferred): array
{
    $preferred = pwa_normalize_content_encoding($preferred);
    $candidates = [$preferred];
    foreach (['aes128gcm', 'aesgcm'] as $enc) {
        if (!in_array($enc, $candidates, true)) {
            $candidates[] = $enc;
        }
    }

    return $candidates;
}

function pwa_format_push_report_error(Minishlink\WebPush\MessageSentReport $report): string
{
    $reason = trim((string) $report->getReason());
    $response = $report->getResponse();
    if ($response !== null) {
        $body = trim((string) $report->getResponseContent());
        $reason = 'HTTP ' . $response->getStatusCode() . ($reason !== '' ? ': ' . $reason : '');
        if ($body !== '') {
            $reason .= ' — ' . substr($body, 0, 200);
        }
    }

    return substr($reason !== '' ? $reason : 'Push failed.', 0, 512);
}

function pwa_create_web_push(): Minishlink\WebPush\WebPush
{
    $auth = [
        'VAPID' => [
            'subject' => pwa_vapid_subject(),
            'publicKey' => pwa_vapid_public_key(),
            'privateKey' => pwa_vapid_private_key(),
        ],
    ];

    return new Minishlink\WebPush\WebPush($auth, [], 30, ['verify' => true]);
}

/**
 * @param array<string,mixed> $sub
 * @return array{ok:bool, push_sent:int, push_error:?string, encoding:?string}
 */
function pwa_send_push_to_subscription(Minishlink\WebPush\WebPush $webPush, array $sub, string $payload): array
{
    $endpoint = (string) ($sub['endpoint'] ?? '');
    $p256dh = (string) ($sub['p256dh'] ?? '');
    $authKey = (string) ($sub['auth_key'] ?? '');
    $preferredEncoding = (string) ($sub['content_encoding'] ?? 'aes128gcm');
    $lastError = 'Push failed.';

    foreach (pwa_content_encoding_candidates($preferredEncoding) as $contentEncoding) {
        try {
            $pushSub = Minishlink\WebPush\Subscription::create([
                'endpoint' => $endpoint,
                'keys' => [
                    'p256dh' => $p256dh,
                    'auth' => $authKey,
                ],
                'contentEncoding' => $contentEncoding,
            ]);
            $report = $webPush->sendOneNotification($pushSub, $payload);
            if ($report->isSuccess()) {
                return [
                    'ok' => true,
                    'push_sent' => 1,
                    'push_error' => null,
                    'encoding' => $contentEncoding,
                ];
            }
            $lastError = pwa_format_push_report_error($report);
            if ($report->isSubscriptionExpired()) {
                return [
                    'ok' => false,
                    'push_sent' => 0,
                    'push_error' => $lastError,
                    'encoding' => null,
                    'expired' => true,
                ];
            }
        } catch (Throwable $e) {
            $lastError = substr($e->getMessage(), 0, 512);
        }
    }

    // Hostinger sometimes fails HTTPS verify to Mozilla/FCM — retry once without verify.
    try {
        $insecurePush = new Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => pwa_vapid_subject(),
                'publicKey' => pwa_vapid_public_key(),
                'privateKey' => pwa_vapid_private_key(),
            ],
        ], [], 30, ['verify' => false]);
        foreach (pwa_content_encoding_candidates($preferredEncoding) as $contentEncoding) {
            $pushSub = Minishlink\WebPush\Subscription::create([
                'endpoint' => $endpoint,
                'keys' => ['p256dh' => $p256dh, 'auth' => $authKey],
                'contentEncoding' => $contentEncoding,
            ]);
            $report = $insecurePush->sendOneNotification($pushSub, $payload);
            if ($report->isSuccess()) {
                return [
                    'ok' => true,
                    'push_sent' => 1,
                    'push_error' => null,
                    'encoding' => $contentEncoding,
                ];
            }
            $lastError = pwa_format_push_report_error($report);
        }
    } catch (Throwable $e) {
        $lastError = substr($e->getMessage(), 0, 512);
    }

    return [
        'ok' => false,
        'push_sent' => 0,
        'push_error' => $lastError,
        'encoding' => null,
    ];
}

function pwa_device_label_from_user_agent(?string $userAgent): string
{
    $ua = trim((string) ($userAgent ?? ''));
    if ($ua === '') {
        return 'Unknown device';
    }
    if (strlen($ua) > 120) {
        $ua = substr($ua, 0, 120) . '…';
    }

    return $ua;
}

/**
 * @param array<string,mixed> $subscription
 */
function pwa_save_push_subscription(int $userId, array $subscription, ?string $userAgent = null): array
{
    pwa_ensure_tables();
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not logged in.'];
    }
    $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
    $keys = is_array($subscription['keys'] ?? null) ? $subscription['keys'] : [];
    $p256dh = trim((string) ($keys['p256dh'] ?? ''));
    $auth = trim((string) ($keys['auth'] ?? ''));
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return ['ok' => false, 'error' => 'Invalid push subscription.'];
    }
    $endpointHash = hash('sha256', $endpoint);
    $deviceLabel = pwa_device_label_from_user_agent($userAgent);
    $contentEncoding = pwa_normalize_content_encoding((string) ($subscription['contentEncoding'] ?? ''));
    try {
        $st = db()->prepare(
            'INSERT INTO allureone_push_subscriptions
                (user_id, endpoint_hash, endpoint, p256dh, auth_key, content_encoding, user_agent, device_label, is_active)
             VALUES
                (:user_id, :endpoint_hash, :endpoint, :p256dh, :auth_key, :content_encoding, :user_agent, :device_label, 1)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                p256dh = VALUES(p256dh),
                auth_key = VALUES(auth_key),
                content_encoding = VALUES(content_encoding),
                user_agent = VALUES(user_agent),
                device_label = VALUES(device_label),
                is_active = 1,
                updated_at = NOW()'
        );
        $st->execute([
            'user_id' => $userId,
            'endpoint_hash' => $endpointHash,
            'endpoint' => $endpoint,
            'p256dh' => $p256dh,
            'auth_key' => $auth,
            'content_encoding' => $contentEncoding,
            'user_agent' => $deviceLabel,
            'device_label' => $deviceLabel,
        ]);

        return ['ok' => true, 'error' => ''];
    } catch (PDOException $e) {
        error_log('AllureOne PWA save subscription failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Could not save push subscription.'];
    }
}

function pwa_deactivate_push_subscription(int $userId, string $endpoint): array
{
    pwa_ensure_tables();
    $endpoint = trim($endpoint);
    if ($userId <= 0 || $endpoint === '') {
        return ['ok' => false, 'error' => 'Invalid request.'];
    }
    try {
        $st = db()->prepare(
            'UPDATE allureone_push_subscriptions
             SET is_active = 0, updated_at = NOW()
             WHERE user_id = :user_id AND endpoint_hash = :endpoint_hash'
        );
        $st->execute([
            'user_id' => $userId,
            'endpoint_hash' => hash('sha256', $endpoint),
        ]);

        return ['ok' => true, 'error' => ''];
    } catch (PDOException $e) {
        error_log('AllureOne PWA deactivate subscription failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Could not deactivate subscription.'];
    }
}

/**
 * @return array{ok:bool, announcement_id?:int, error?:string, sent?:int, failed?:int}
 */
function pwa_send_announcement(string $message, int $createdBy, string $createdByName): array
{
    pwa_ensure_tables();
    $message = trim($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'Announcement message is required.'];
    }
    if (!pwa_ensure_vendor_autoload()) {
        return ['ok' => false, 'error' => 'Web Push library missing. Ensure vendor/ is deployed on the server.'];
    }
    if (!pwa_web_push_available()) {
        return ['ok' => false, 'error' => 'Web Push is not configured. Set VAPID keys in config.php.'];
    }

    try {
        $pdo = db();
        $ins = $pdo->prepare(
            'INSERT INTO allureone_announcements (message, created_by, created_by_name)
             VALUES (:message, :created_by, :created_by_name)'
        );
        $ins->execute([
            'message' => $message,
            'created_by' => $createdBy,
            'created_by_name' => $createdByName,
        ]);
        $announcementId = (int) $pdo->lastInsertId();

        $subs = $pdo->query(
            'SELECT id, user_id, endpoint, p256dh, auth_key, content_encoding, device_label
             FROM allureone_push_subscriptions
             WHERE is_active = 1
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $sent = 0;
        $failed = 0;

        $webPush = pwa_create_web_push();
        $pushErrors = [];

        $deliveryIns = $pdo->prepare(
            'INSERT INTO allureone_announcement_deliveries
                (announcement_id, subscription_id, user_id, ack_token, push_sent, push_error)
             VALUES
                (:announcement_id, :subscription_id, :user_id, :ack_token, :push_sent, :push_error)'
        );

        foreach ($subs as $sub) {
            $subscriptionId = (int) ($sub['id'] ?? 0);
            $userId = (int) ($sub['user_id'] ?? 0);
            if ($subscriptionId <= 0) {
                continue;
            }
            $ackToken = bin2hex(random_bytes(32));
            $payload = json_encode([
                'title' => 'AllureOne Announcement',
                'body' => $message,
                'url' => allureone_url('Announcement.php'),
                'icon' => pwa_notification_icon_url(),
                'announcementId' => $announcementId,
                'deliveryId' => 0,
                'ackToken' => $ackToken,
                'tag' => 'announcement-' . $announcementId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $pushSent = 0;
            $pushError = null;
            $sendResult = pwa_send_push_to_subscription(
                $webPush,
                $sub,
                $payload === false ? '{}' : $payload
            );
            if (($sendResult['ok'] ?? false) && (int) ($sendResult['push_sent'] ?? 0) === 1) {
                $pushSent = 1;
                $sent++;
                if (!empty($sendResult['encoding'])) {
                    $pdo->prepare(
                        'UPDATE allureone_push_subscriptions SET content_encoding = :enc WHERE id = :id'
                    )->execute([
                        'enc' => (string) $sendResult['encoding'],
                        'id' => $subscriptionId,
                    ]);
                }
            } else {
                $pushError = (string) ($sendResult['push_error'] ?? 'Push failed.');
                $pushErrors[] = $pushError;
                $failed++;
                if (!empty($sendResult['expired'])) {
                    $pdo->prepare('UPDATE allureone_push_subscriptions SET is_active = 0 WHERE id = :id')
                        ->execute(['id' => $subscriptionId]);
                }
            }

            $deliveryIns->execute([
                'announcement_id' => $announcementId,
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
                'ack_token' => $ackToken,
                'push_sent' => $pushSent,
                'push_error' => $pushError,
            ]);
            $deliveryId = (int) $pdo->lastInsertId();
            if ($deliveryId > 0 && $pushSent === 1 && $payload !== false) {
                $payloadData = json_decode($payload, true);
                if (is_array($payloadData)) {
                    $payloadData['deliveryId'] = $deliveryId;
                    // Re-send not needed; ack token already in payload. Update is optional.
                }
            }
        }

        return [
            'ok' => true,
            'announcement_id' => $announcementId,
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $pushErrors,
        ];
    } catch (Throwable $e) {
        error_log('AllureOne PWA send announcement failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Could not send announcement.'];
    }
}

function pwa_ack_delivery(string $ackToken, string $event): array
{
    pwa_ensure_tables();
    $ackToken = trim($ackToken);
    $event = strtolower(trim($event));
    if ($ackToken === '' || !in_array($event, ['delivered', 'read'], true)) {
        return ['ok' => false, 'error' => 'Invalid ack request.'];
    }
    $column = $event === 'read' ? 'read_at' : 'delivered_at';
    try {
        $st = db()->prepare(
            "UPDATE allureone_announcement_deliveries
             SET {$column} = COALESCE({$column}, NOW())
             WHERE ack_token = :ack_token"
        );
        $st->execute(['ack_token' => $ackToken]);
        if ($st->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'Delivery not found.'];
        }
        if ($event === 'read') {
            db()->prepare(
                'UPDATE allureone_announcement_deliveries
                 SET delivered_at = COALESCE(delivered_at, NOW())
                 WHERE ack_token = :ack_token'
            )->execute(['ack_token' => $ackToken]);
        }

        return ['ok' => true, 'error' => ''];
    } catch (PDOException $e) {
        error_log('AllureOne PWA ack failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'Could not record delivery status.'];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function pwa_announcement_history(int $limit = 50): array
{
    pwa_ensure_tables();
    $limit = max(1, min(100, $limit));
    try {
        $st = db()->prepare(
            "SELECT a.id, a.message, a.created_by, a.created_by_name, a.created_at,
                    COUNT(d.id) AS device_count,
                    SUM(CASE WHEN d.push_sent = 1 THEN 1 ELSE 0 END) AS push_sent_count,
                    SUM(CASE WHEN d.delivered_at IS NOT NULL THEN 1 ELSE 0 END) AS delivered_count,
                    SUM(CASE WHEN d.read_at IS NOT NULL THEN 1 ELSE 0 END) AS read_count
             FROM allureone_announcements a
             LEFT JOIN allureone_announcement_deliveries d ON d.announcement_id = a.id
             GROUP BY a.id, a.message, a.created_by, a.created_by_name, a.created_at
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT {$limit}"
        );
        $st->execute();

        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('AllureOne PWA announcement history failed: ' . $e->getMessage());

        return [];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function pwa_announcement_deliveries(int $announcementId): array
{
    pwa_ensure_tables();
    if ($announcementId <= 0) {
        return [];
    }
    try {
        $st = db()->prepare(
            'SELECT d.id, d.user_id, d.push_sent, d.push_error, d.delivered_at, d.read_at,
                    s.device_label, u.FullName AS user_name, u.loginname
             FROM allureone_announcement_deliveries d
             INNER JOIN allureone_push_subscriptions s ON s.id = d.subscription_id
             LEFT JOIN allureone_users u ON u.id = d.user_id
             WHERE d.announcement_id = :announcement_id
             ORDER BY d.id ASC'
        );
        $st->execute(['announcement_id' => $announcementId]);

        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('AllureOne PWA announcement deliveries failed: ' . $e->getMessage());

        return [];
    }
}

function pwa_active_subscription_count(): int
{
    pwa_ensure_tables();
    try {
        return (int) (db()->query('SELECT COUNT(*) FROM allureone_push_subscriptions WHERE is_active = 1')->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        return 0;
    }
}

function pwa_render_head_tags(): void
{
    if (!function_exists('allureone_base_href')) {
        require_once __DIR__ . '/app_path.php';
    }
    $vapidPublic = pwa_vapid_public_key();
    $scope = allureone_scope();
    ?>
    <base href="<?= e(allureone_base_href()) ?>">
    <link rel="manifest" href="<?= e(allureone_url('manifest.php')) ?>">
    <meta name="allureone-base" content="<?= e($scope) ?>">
    <meta name="theme-color" content="#2f5f90">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="apple-touch-icon" href="<?= e(pwa_apple_touch_icon_url()) ?>">
    <?php if ($vapidPublic !== ''): ?>
    <meta name="allureone-vapid-key" content="<?= e($vapidPublic) ?>">
    <?php endif; ?>
    <?php
}

function pwa_render_register_script(bool $subscribePush = false): void
{
    ?>
    <script src="assets/js/pwa.js" defer></script>
    <?php if ($subscribePush): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.AllureOnePwa && typeof window.AllureOnePwa.initPush === 'function') {
            window.AllureOnePwa.initPush(<?= json_encode(csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        }
    });
    </script>
    <?php endif; ?>
    <?php
}
