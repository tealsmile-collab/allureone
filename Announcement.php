<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$user = current_user();
$roleId = (int) ($user['role_id'] ?? 0);
if ($roleId !== ROLE_SUPERADMIN && $roleId !== ROLE_ADMIN) {
    http_response_code(403);
    exit('Forbidden');
}

$flash = null;
$vapidSnippet = null;
$announcementHistory = [];
$activeDevices = 0;
$pwaReady = pwa_web_push_available();
$isSuperadmin = $roleId === ROLE_SUPERADMIN;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_vapid'])) {
    if (!$isSuperadmin) {
        $flash = ['type' => 'error', 'text' => 'Only superadmin can generate VAPID keys.'];
    } elseif (!csrf_validate($_POST['_csrf'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $gen = pwa_generate_vapid_keys();
        if (!($gen['ok'] ?? false)) {
            $flash = ['type' => 'error', 'text' => (string) ($gen['error'] ?? 'Could not generate VAPID keys.')];
        } else {
            $vapidSnippet = pwa_format_vapid_config_snippet([
                'public_key' => (string) $gen['public_key'],
                'private_key' => (string) $gen['private_key'],
            ]);
            $flash = ['type' => 'ok', 'text' => 'VAPID keys generated. Copy the block below into config.php on the server, then reload this page.'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_announcement'])) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $flash = ['type' => 'error', 'text' => 'Invalid session. Please refresh and try again.'];
    } else {
        $message = trim((string) ($_POST['message'] ?? ''));
        if ($message === '') {
            $flash = ['type' => 'error', 'text' => 'Announcement message is required.'];
        } elseif (function_exists('mb_strlen') ? mb_strlen($message) > 500 : strlen($message) > 500) {
            $flash = ['type' => 'error', 'text' => 'Announcement must be 500 characters or fewer.'];
        } else {
            $result = pwa_send_announcement(
                $message,
                (int) ($user['id'] ?? 0),
                trim((string) ($user['full_name'] ?? ''))
            );
            if (!($result['ok'] ?? false)) {
                $flash = ['type' => 'error', 'text' => (string) ($result['error'] ?? 'Could not send announcement.')];
            } else {
                $sent = (int) ($result['sent'] ?? 0);
                $failed = (int) ($result['failed'] ?? 0);
                $flash = [
                    'type' => 'ok',
                    'text' => 'Announcement sent to ' . $sent . ' device(s).'
                        . ($failed > 0 ? ' Failed: ' . $failed . '.' : ''),
                ];
            }
        }
    }
}

$announcementHistory = pwa_announcement_history(50);
$activeDevices = pwa_active_subscription_count();

$pageTitle = 'Announcements';
$activeNav = 'announcements';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>Announcements</span>
    </div>
    <div class="card__body">
        <?php if (is_array($flash)): ?>
            <p class="alert alert--<?= ($flash['type'] ?? '') === 'ok' ? 'ok' : 'error' ?>" style="margin:1rem 1.25rem 0"><?= e((string) ($flash['text'] ?? '')) ?></p>
        <?php endif; ?>

        <?php if (!$pwaReady): ?>
            <div class="alert alert--error" style="margin:1rem 1.25rem 0">
                <p style="margin:0 0 0.5rem">Web Push is not ready. Add VAPID keys to <code>config.php</code> on the server under <code>pwa</code>.</p>
                <?php if ($isSuperadmin): ?>
                    <form method="post" action="Announcement.php" style="margin:0.75rem 0 0">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <button type="submit" name="generate_vapid" value="1" class="btn btn--secondary">Generate VAPID keys (on server)</button>
                    </form>
                    <p style="margin:0.5rem 0 0;font-size:.85rem">Or run <code>php pwa_vapid_generate.php</code> on the server, or use <a href="https://vapidkeys.com/" target="_blank" rel="noopener">vapidkeys.com</a>.</p>
                <?php else: ?>
                    <p style="margin:0;font-size:.85rem">Ask a superadmin to generate keys or add them to server <code>config.php</code>.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (is_string($vapidSnippet) && $vapidSnippet !== ''): ?>
            <div style="margin:1rem 1.25rem 0;padding:1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px">
                <p style="margin:0 0 0.5rem;font-weight:600">Paste into server config.php:</p>
                <pre style="margin:0;white-space:pre-wrap;word-break:break-all;font-size:.85rem"><?= e($vapidSnippet) ?></pre>
            </div>
        <?php endif; ?>

        <div style="padding:1rem 1.25rem 0">
            <p style="margin:0 0 1rem;color:var(--muted, #64748b);font-size:.9rem">
                Active PWA push devices: <strong><?= (int) $activeDevices ?></strong>
                (users must allow notifications after installing/opening the app).
            </p>
            <form method="post" action="Announcement.php" class="form" style="max-width:640px">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <div class="form__row">
                    <label for="announcement_message">Announcement</label>
                    <textarea id="announcement_message" name="message" rows="4" maxlength="500" required placeholder="Type announcement to send to all subscribed PWA devices…"></textarea>
                </div>
                <button type="submit" name="send_announcement" value="1" class="btn btn--primary"<?= $pwaReady ? '' : ' disabled' ?>>Send announcement</button>
            </form>
        </div>

        <div style="padding:1.25rem">
            <h3 style="margin:0 0 0.75rem;font-size:1rem">History &amp; delivery summary</h3>
            <?php if ($announcementHistory === []): ?>
                <p class="empty">No announcements sent yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Message</th>
                                <th>Sent by</th>
                                <th>Devices</th>
                                <th>Push sent</th>
                                <th>Shown</th>
                                <th>Read</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($announcementHistory as $row):
                                $annId = (int) ($row['id'] ?? 0);
                                $deliveries = pwa_announcement_deliveries($annId);
                            ?>
                                <tr>
                                    <td><?= e((string) ($row['created_at'] ?? '')) ?></td>
                                    <td style="max-width:280px;white-space:normal"><?= e((string) ($row['message'] ?? '')) ?></td>
                                    <td><?= e((string) ($row['created_by_name'] ?? '')) ?></td>
                                    <td><?= (int) ($row['device_count'] ?? 0) ?></td>
                                    <td><?= (int) ($row['push_sent_count'] ?? 0) ?></td>
                                    <td><?= (int) ($row['delivered_count'] ?? 0) ?></td>
                                    <td><?= (int) ($row['read_count'] ?? 0) ?></td>
                                    <td>
                                        <?php if ($deliveries !== []): ?>
                                            <details>
                                                <summary class="link--underlined" style="cursor:pointer">Devices</summary>
                                                <div class="table-wrap" style="margin-top:0.5rem">
                                                    <table class="data">
                                                        <thead>
                                                            <tr>
                                                                <th>User</th>
                                                                <th>Device</th>
                                                                <th>Push</th>
                                                                <th>Shown</th>
                                                                <th>Read</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($deliveries as $d): ?>
                                                                <tr>
                                                                    <td><?= e(trim((string) ($d['user_name'] ?? '')) !== '' ? (string) $d['user_name'] : ('User #' . (int) ($d['user_id'] ?? 0))) ?></td>
                                                                    <td style="max-width:220px;white-space:normal;font-size:.85rem"><?= e((string) ($d['device_label'] ?? '')) ?></td>
                                                                    <td><?= (int) ($d['push_sent'] ?? 0) === 1 ? 'Yes' : e((string) ($d['push_error'] ?? 'No')) ?></td>
                                                                    <td><?= !empty($d['delivered_at']) ? e((string) $d['delivered_at']) : '—' ?></td>
                                                                    <td><?= !empty($d['read_at']) ? e((string) $d['read_at']) : '—' ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/includes/layout_end.php';
