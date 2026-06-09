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
$pwaStatus = pwa_readiness_details();
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
            $targetMode = strtolower(trim((string) ($_POST['target_mode'] ?? 'all')));
            $selectedUserIds = [];
            if ($targetMode === 'selected') {
                $selectedUserIds = pwa_normalize_user_id_list(
                    is_array($_POST['target_user_ids'] ?? null) ? $_POST['target_user_ids'] : []
                );
                if ($selectedUserIds === []) {
                    $flash = ['type' => 'error', 'text' => 'Select at least one user for a targeted announcement.'];
                }
            }
            if (is_array($flash)) {
                // validation failed above
            } else {
            $result = pwa_send_announcement(
                $message,
                (int) ($user['id'] ?? 0),
                trim((string) ($user['full_name'] ?? '')),
                $targetMode === 'selected' ? $selectedUserIds : null,
                'ui'
            );
            if (!($result['ok'] ?? false)) {
                $flash = ['type' => 'error', 'text' => (string) ($result['error'] ?? 'Could not send announcement.')];
            } else {
                $sent = (int) ($result['sent'] ?? 0);
                $failed = (int) ($result['failed'] ?? 0);
                $errDetail = '';
                $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
                if ($errors !== []) {
                    $errDetail = ' Error: ' . (string) $errors[0];
                }
                $targetNote = $targetMode === 'selected'
                    ? (' to ' . count($selectedUserIds) . ' selected user(s)')
                    : '';
                $flash = [
                    'type' => $sent > 0 ? 'ok' : 'error',
                    'text' => 'Announcement sent' . $targetNote . ' on ' . $sent . ' device(s).'
                        . ($failed > 0 ? ' Failed: ' . $failed . '.' : '')
                        . ($sent === 0 && $targetMode === 'selected' ? ' Selected user(s) may have no PWA push subscription.' : '')
                        . $errDetail,
                ];
            }
            }
        }
    }
}

$announcementHistory = pwa_announcement_history(50);
$activeDevices = pwa_active_subscription_count();
$announcementUsers = pwa_users_for_announcement_picker();

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
                <p style="margin:0 0 0.5rem"><strong>Web Push is not ready.</strong></p>
                <ul style="margin:0 0 0.75rem 1.25rem;padding:0;font-size:.9rem">
                    <li>vendor/ on server: <?= !empty($pwaStatus['vendor']) ? 'OK' : 'missing' ?></li>
                    <li>Web Push library: <?= !empty($pwaStatus['library']) ? 'OK' : 'not loaded' ?></li>
                    <li>VAPID public key in config.php: <?= !empty($pwaStatus['public_key']) ? 'OK' : 'missing' ?></li>
                    <li>VAPID private key in config.php: <?= !empty($pwaStatus['private_key']) ? 'OK' : 'missing' ?></li>
                </ul>
                <?php foreach (($pwaStatus['issues'] ?? []) as $issue): ?>
                    <p style="margin:0 0 0.35rem;font-size:.9rem">• <?= e((string) $issue) ?></p>
                <?php endforeach; ?>
                <?php if ($isSuperadmin && empty($pwaStatus['public_key'])): ?>
                    <form method="post" action="Announcement.php" style="margin:0.75rem 0 0">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <button type="submit" name="generate_vapid" value="1" class="btn btn--secondary">Generate VAPID keys (on server)</button>
                    </form>
                    <p style="margin:0.5rem 0 0;font-size:.85rem">Or use <a href="https://vapidkeys.com/" target="_blank" rel="noopener">vapidkeys.com</a> and paste keys into <strong>server</strong> <code>config.php</code> (not git — edit in Hostinger File Manager).</p>
                <?php elseif ($isSuperadmin && empty($pwaStatus['vendor'])): ?>
                    <p style="margin:0.75rem 0 0;font-size:.85rem">Push <code>vendor/</code> from your PC via git, or upload the <code>vendor</code> folder in Hostinger File Manager.</p>
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
            <form method="post" action="Announcement.php" class="form" style="max-width:720px" id="announcementForm">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <div class="form__row announcement-target-mode">
                    <span class="announcement-target-mode__label">Send to</span>
                    <label class="check-label announcement-target-mode__option">
                        <input type="radio" name="target_mode" value="all" checked data-announcement-target-toggle>
                        <span>All users (all subscribed PWA devices)</span>
                    </label>
                    <label class="check-label announcement-target-mode__option">
                        <input type="radio" name="target_mode" value="selected" data-announcement-target-toggle>
                        <span>Selected users only</span>
                    </label>
                </div>
                <div class="form__row announcement-user-picker" id="announcementUserPicker" hidden>
                    <?php if ($announcementUsers === []): ?>
                        <p class="empty" style="margin:0">No active users found.</p>
                    <?php else: ?>
                        <?php foreach ($announcementUsers as $pickUser):
                            $pickId = (int) ($pickUser['id'] ?? 0);
                            $pickName = trim((string) ($pickUser['full_name'] ?? ''));
                            if ($pickName === '') {
                                $pickName = (string) ($pickUser['loginname'] ?? ('User #' . $pickId));
                            }
                            $pickMobile = trim((string) ($pickUser['mobile_no'] ?? ''));
                            $deviceCount = (int) ($pickUser['device_count'] ?? 0);
                        ?>
                            <label class="check-label announcement-user-picker__item">
                                <input type="checkbox" name="target_user_ids[]" value="<?= $pickId ?>">
                                <span class="announcement-user-picker__text">
                                    <?= e($pickName) ?>
                                    <?php if ($pickMobile !== ''): ?> · <?= e($pickMobile) ?><?php endif; ?>
                                    <span class="announcement-user-picker__meta">(<?= $deviceCount ?> device<?= $deviceCount === 1 ? '' : 's' ?>)</span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="form__row">
                    <label for="announcement_message">Announcement</label>
                    <textarea id="announcement_message" name="message" rows="4" maxlength="500" required placeholder="Type announcement message…"></textarea>
                </div>
                <button type="submit" name="send_announcement" value="1" class="btn btn--primary"<?= $pwaReady ? '' : ' disabled' ?>>Send announcement</button>
            </form>
            <script>
            (function () {
                var form = document.getElementById('announcementForm');
                if (!form) return;
                var picker = document.getElementById('announcementUserPicker');
                function syncPicker() {
                    var selected = form.querySelector('input[name="target_mode"][value="selected"]');
                    if (!picker || !selected) return;
                    picker.hidden = !selected.checked;
                }
                form.querySelectorAll('[data-announcement-target-toggle]').forEach(function (el) {
                    el.addEventListener('change', syncPicker);
                });
                syncPicker();
            })();
            </script>
        </div>

        <div style="padding:1rem 1.25rem 0;font-size:.9rem;color:var(--muted, #64748b)">
            <details>
                <summary class="link--underlined" style="cursor:pointer">External POST API</summary>
                <div style="margin-top:0.5rem;padding:0.75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;color:#334155">
                    <p style="margin:0 0 0.5rem"><code>POST <?= e(allureone_url('pwa_announcement_api.php')) ?></code></p>
                    <p style="margin:0 0 0.35rem">Header: <code>X-Announcement-Api-Key: &lt;secret&gt;</code> (set <code>pwa.announcement_api_key</code> in server config.php)</p>
                    <p style="margin:0 0 0.35rem">Body (JSON): <code>{"message":"Hello","user_id":123}</code> or <code>{"message":"Hello","mobile":"9876543210"}</code></p>
                </div>
            </details>
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
                                <th>Target</th>
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
                                    <td><?= e((string) ($row['created_by_name'] ?? '')) ?><?= strtolower((string) ($row['source'] ?? '')) === 'api' ? ' (API)' : '' ?></td>
                                    <td style="max-width:200px;white-space:normal;font-size:.85rem"><?= e(pwa_format_announcement_target_label($row)) ?></td>
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
                                                                <th>Error</th>
                                                                <th>Shown</th>
                                                                <th>Read</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($deliveries as $d): ?>
                                                                <tr>
                                                                    <td><?= e(trim((string) ($d['user_name'] ?? '')) !== '' ? (string) $d['user_name'] : ('User #' . (int) ($d['user_id'] ?? 0))) ?></td>
                                                                    <td style="max-width:220px;white-space:normal;font-size:.85rem"><?= e((string) ($d['device_label'] ?? '')) ?></td>
                                                                    <td><?= (int) ($d['push_sent'] ?? 0) === 1 ? 'Yes' : 'No' ?></td>
                                                                    <td style="max-width:220px;white-space:normal;font-size:.85rem"><?= (int) ($d['push_sent'] ?? 0) === 1 ? '—' : e((string) ($d['push_error'] ?? '')) ?></td>
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
