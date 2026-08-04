<?php
/**
 * Order + Razorpay + WhatsApp + Email services
 */

declare(strict_types=1);

require_once ROOT_PATH . '/includes/helpers/OrderCartLog.php';

class OrderService
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function createFromCart(array $customer, string $token): array
    {
        $cartModel = new CartModel();
        $summary = $cartModel->summary($token);
        if (empty($summary['items'])) {
            throw new RuntimeException('Cart is empty');
        }
        if (empty($customer['branch_id'])) {
            throw new RuntimeException('Branch is mandatory');
        }

        $customerId = $this->upsertCustomer($customer);
        $orderNo = $this->generateNo('order');
        $invoiceNo = $this->generateNo('invoice');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO alluredeal_orders
                (order_no, invoice_no, customer_id, customer_name, customer_mobile, customer_email,
                 customer_gender, notes, city_id, branch_id, coupon_code, coupon_discount,
                 subtotal, discount_total, gst_percent, gst_amount, grand_total, currency,
                 payment_status, status_code, ip_address, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
            );
            $stmt->execute([
                $orderNo,
                $invoiceNo,
                $customerId,
                $customer['name'],
                format_phone_in((string) $customer['mobile']),
                $customer['email'] ?? null,
                $customer['gender'] ?? null,
                $customer['notes'] ?? null,
                $customer['city_id'] ?? $summary['city_id'],
                (int) $customer['branch_id'],
                $summary['coupon_code'],
                $summary['coupon_discount'],
                $summary['subtotal'],
                $summary['coupon_discount'],
                $summary['gst_percent'],
                $summary['gst_amount'],
                $summary['grand_total'],
                'INR',
                'pending',
                'placed',
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $orderId = (int) $this->db->lastInsertId();

            $itemStmt = $this->db->prepare(
                'INSERT INTO alluredeal_order_items
                (order_id, product_id, product_name, duration, quantity, original_price, unit_price, line_total)
                VALUES (?,?,?,?,?,?,?,?)'
            );
            foreach ($summary['items'] as $item) {
                $itemStmt->execute([
                    $orderId,
                    $item['product_id'],
                    $item['name'],
                    $item['duration'],
                    $item['quantity'],
                    $item['original_price'],
                    $item['unit_price'],
                    $item['line_total'],
                ]);
            }

            $rzOrder = $this->createRazorpayOrder($orderId, $summary['grand_total'], $orderNo);
            $this->db->prepare(
                'UPDATE alluredeal_orders SET razorpay_order_id = ? WHERE id = ?'
            )->execute([$rzOrder['id'], $orderId]);

            $this->db->prepare(
                'INSERT INTO alluredeal_payment_logs (order_id, event, payload, status, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([$orderId, 'razorpay_order_created', json_encode($rzOrder), 'pending']);

            $this->db->commit();

            OrderCartLog::success('order_placed', [
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'invoice_no' => $invoiceNo,
                'customer_name' => $customer['name'],
                'customer' => format_phone_in((string) $customer['mobile']),
                'country_code' => $customer['country_code'] ?? null,
                'email' => $customer['email'] ?? null,
                'branch_id' => (int) $customer['branch_id'],
                'grand_total' => $summary['grand_total'],
                'coupon_code' => $summary['coupon_code'],
                'items' => array_map(static fn ($i) => [
                    'product_id' => $i['product_id'],
                    'name' => $i['name'],
                    'qty' => $i['quantity'],
                    'line_total' => $i['line_total'],
                ], $summary['items']),
                'razorpay_order_id' => $rzOrder['id'] ?? null,
            ]);

            // Razorpay prefill: local 10-digit number when available
            $mobileDigits = preg_replace('/\D+/', '', (string) ($customer['mobile_local'] ?? $customer['mobile'])) ?? '';
            if (strlen($mobileDigits) > 10) {
                $mobileDigits = substr($mobileDigits, -10);
            }

                return [
                'order_id' => $orderId,
                'order_no' => $orderNo,
                'invoice_no' => $invoiceNo,
                'amount' => $summary['grand_total'],
                'amount_paise' => (int) round($summary['grand_total'] * 100),
                'currency' => 'INR',
                'razorpay_order_id' => $rzOrder['id'],
                'razorpay_key' => config('razorpay.key_id'),
                'customer' => [
                    'name' => $customer['name'],
                    'email' => $customer['email'] ?? '',
                    'contact' => $mobileDigits,
                ],
                'company' => config('razorpay.company_name'),
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            OrderCartLog::error('order_place_failed', [
                'order_no' => $orderNo ?? null,
                'invoice_no' => $invoiceNo ?? null,
                'customer_name' => $customer['name'] ?? null,
                'mobile' => isset($customer['mobile']) ? format_phone_in((string) $customer['mobile']) : null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function verifyPayment(array $payload): array
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $rzOrderId = (string) ($payload['razorpay_order_id'] ?? '');
        $rzPaymentId = (string) ($payload['razorpay_payment_id'] ?? '');
        $rzSignature = (string) ($payload['razorpay_signature'] ?? '');

        OrderCartLog::info('razorpay_verify_started', [
            'order_id' => $orderId,
            'razorpay_order_id' => $rzOrderId,
            'razorpay_payment_id' => $rzPaymentId,
        ]);

        try {
            $stmt = $this->db->prepare('SELECT * FROM alluredeal_orders WHERE id = ? LIMIT 1');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new RuntimeException('Order not found');
            }

            $expected = hash_hmac(
                'sha256',
                $rzOrderId . '|' . $rzPaymentId,
                (string) config('razorpay.key_secret')
            );

            if (!hash_equals($expected, $rzSignature)) {
                $this->db->prepare(
                    'UPDATE alluredeal_orders SET payment_status = ? WHERE id = ?'
                )->execute(['failed', $orderId]);
                OrderCartLog::error('razorpay_signature_failed', [
                    'order_id' => $orderId,
                    'order_no' => $order['order_no'] ?? null,
                    'razorpay_order_id' => $rzOrderId,
                    'razorpay_payment_id' => $rzPaymentId,
                ]);
                throw new RuntimeException('Payment signature verification failed');
            }

            // Optional server-side GET confirmation via Basic Auth
            try {
                require_once ROOT_PATH . '/includes/services/RazorpayClient.php';
                $payment = (new RazorpayClient())->fetchPayment($rzPaymentId);
                if (($payment['status'] ?? '') === 'failed') {
                    throw new RuntimeException('Razorpay payment status is failed');
                }
                OrderCartLog::info('razorpay_payment_fetched', [
                    'order_id' => $orderId,
                    'razorpay_payment_id' => $rzPaymentId,
                    'status' => $payment['status'] ?? null,
                    'method' => $payment['method'] ?? null,
                    'amount' => $payment['amount'] ?? null,
                ]);
            } catch (Throwable $e) {
                // Log but don't block if GET fails after valid signature
                $this->db->prepare(
                    'INSERT INTO alluredeal_payment_logs (order_id, event, payload, status, created_at)
                     VALUES (?, ?, ?, ?, NOW())'
                )->execute([
                    $orderId,
                    'razorpay_payment_get',
                    json_encode(['error' => $e->getMessage()]),
                    'warning',
                ]);
                OrderCartLog::error('razorpay_payment_fetch_warning', [
                    'order_id' => $orderId,
                    'razorpay_payment_id' => $rzPaymentId,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->db->prepare(
                'UPDATE alluredeal_orders
                 SET payment_status = ?, razorpay_payment_id = ?, razorpay_signature = ?,
                     razorpay_order_id = ?, status_code = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute(['paid', $rzPaymentId, $rzSignature, $rzOrderId, 'confirmed', $orderId]);

            $this->db->prepare(
                'INSERT INTO alluredeal_payment_logs (order_id, event, payload, status, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([$orderId, 'payment_verified', json_encode($payload), 'paid']);

            OrderCartLog::success('razorpay_transaction_success', [
                'order_id' => $orderId,
                'order_no' => $order['order_no'] ?? null,
                'invoice_no' => $order['invoice_no'] ?? null,
                'grand_total' => $order['grand_total'] ?? null,
                'razorpay_order_id' => $rzOrderId,
                'razorpay_payment_id' => $rzPaymentId,
                'customer_name' => $order['customer_name'] ?? null,
                'mobile' => $order['customer_mobile'] ?? null,
            ]);

            // Mark one-time coupon used
            if (!empty($order['coupon_code'])) {
                $this->db->prepare(
                    'UPDATE alluredeal_onetime_coupon
                     SET is_used = 1, used_at = NOW(), order_id = ?
                     WHERE code = ? AND is_used = 0'
                )->execute([$orderId, $order['coupon_code']]);
                $this->db->prepare(
                    'UPDATE alluredeal_coupon SET used_count = used_count + 1 WHERE code = ?'
                )->execute([$order['coupon_code']]);
                $this->db->prepare(
                    'INSERT INTO alluredeal_coupon_usage (coupon_id, customer_id, order_id, discount_amount, created_at)
                     SELECT id, ?, ?, ?, NOW() FROM alluredeal_coupon WHERE code = ? LIMIT 1'
                )->execute([(int) $order['customer_id'], $orderId, (float) $order['coupon_discount'], $order['coupon_code']]);
            }

            // Update customer stats
            if (!empty($order['customer_id'])) {
                $this->db->prepare(
                    'UPDATE alluredeal_customer
                     SET total_orders = total_orders + 1, total_spent = total_spent + ?
                     WHERE id = ?'
                )->execute([(float) $order['grand_total'], (int) $order['customer_id']]);
            }

            (new CartModel())->clear(cart_token());

            $invoice = $this->generateInvoice($orderId);
            $this->sendEmailConfirmation($orderId);
            $this->sendWhatsApp($orderId);

            $fresh = $this->getOrder($orderId);
            return $fresh;
        } catch (Throwable $e) {
            OrderCartLog::error('razorpay_transaction_error', [
                'order_id' => $orderId,
                'razorpay_order_id' => $rzOrderId,
                'razorpay_payment_id' => $rzPaymentId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function getOrder(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT o.*, b.name AS branch_name, b.whatsapp AS branch_whatsapp, c.name AS city_name
             FROM alluredeal_orders o
             LEFT JOIN alluredeal_branch b ON b.id = o.branch_id
             LEFT JOIN alluredeal_city c ON c.id = o.city_id
             WHERE o.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new RuntimeException('Order not found');
        }
        $items = $this->db->prepare('SELECT * FROM alluredeal_order_items WHERE order_id = ?');
        $items->execute([$id]);
        $order['items'] = $items->fetchAll();
        return $order;
    }

    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $where = ['o.is_deleted = 0'];
        $params = [];
        if (!empty($filters['q'])) {
            $where[] = '(o.order_no LIKE ? OR o.customer_name LIKE ? OR o.customer_mobile LIKE ?)';
            $q = '%' . $filters['q'] . '%';
            $params = array_merge($params, [$q, $q, $q]);
        }
        if (!empty($filters['branch_id'])) {
            $where[] = 'o.branch_id = ?';
            $params[] = (int) $filters['branch_id'];
        }
        if (!empty($filters['city_id'])) {
            $where[] = 'o.city_id = ?';
            $params[] = (int) $filters['city_id'];
        }
        if (!empty($filters['payment_status'])) {
            $where[] = 'o.payment_status = ?';
            $params[] = $filters['payment_status'];
        }
        if (!empty($filters['coupon'])) {
            $where[] = 'o.coupon_code = ?';
            $params[] = $filters['coupon'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'o.status_code = ?';
            $params[] = $filters['status'];
        }

        $whereSql = implode(' AND ', $where);
        $count = $this->db->prepare("SELECT COUNT(*) FROM alluredeal_orders o WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;

        $stmt = $this->db->prepare(
            "SELECT o.*, b.name AS branch_name, c.name AS city_name
             FROM alluredeal_orders o
             LEFT JOIN alluredeal_branch b ON b.id = o.branch_id
             LEFT JOIN alluredeal_city c ON c.id = o.city_id
             WHERE {$whereSql}
             ORDER BY o.id DESC
             LIMIT {$perPage} OFFSET {$offset}"
        );
        $stmt->execute($params);
        return [
            'items' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'pages' => (int) ceil($total / max(1, $perPage)),
        ];
    }

    private function upsertCustomer(array $customer): int
    {
        $mobile = format_phone_in((string) ($customer['mobile'] ?? ''));
        if (!empty($customer['country_code']) && !empty($customer['mobile_local'])) {
            $mobile = format_phone_with_country(
                (string) $customer['mobile_local'],
                (string) $customer['country_code']
            );
        } elseif ($mobile === '' && !empty($customer['mobile_local'])) {
            $mobile = format_phone_with_country(
                (string) $customer['mobile_local'],
                (string) ($customer['country_code'] ?? '91')
            );
        }
        $stmt = $this->db->prepare('SELECT id FROM alluredeal_customer WHERE mobile = ? LIMIT 1');
        $stmt->execute([$mobile]);
        $id = $stmt->fetchColumn();
        if ($id) {
            $this->db->prepare(
                'UPDATE alluredeal_customer SET name = ?, email = ?, gender = ?, city_id = ?, notes = ?, updated_at = NOW() WHERE id = ?'
            )->execute([
                $customer['name'],
                $customer['email'] ?? null,
                $customer['gender'] ?? null,
                $customer['city_id'] ?? null,
                $customer['notes'] ?? null,
                (int) $id,
            ]);
            return (int) $id;
        }
        $this->db->prepare(
            'INSERT INTO alluredeal_customer (name, mobile, email, gender, city_id, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $customer['name'],
            $mobile,
            $customer['email'] ?? null,
            $customer['gender'] ?? null,
            $customer['city_id'] ?? null,
            $customer['notes'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function generateNo(string $type): string
    {
        $prefix = $type === 'invoice'
            ? (string) ($this->setting('invoice_prefix') ?: 'ATS')
            : (string) ($this->setting('order_prefix') ?: 'AD');
        return $prefix . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }

    private function setting(string $key): ?string
    {
        $stmt = $this->db->prepare('SELECT setting_value FROM alluredeal_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (string) $v : null;
    }

    private function createRazorpayOrder(int $orderId, float $amount, string $receipt): array
    {
        require_once ROOT_PATH . '/includes/services/RazorpayClient.php';

        try {
            $client = new RazorpayClient();
            $rzOrder = $client->createOrder(
                (int) round($amount * 100),
                $receipt,
                ['order_id' => (string) $orderId]
            );
            OrderCartLog::success('razorpay_order_created', [
                'order_id' => $orderId,
                'receipt' => $receipt,
                'amount' => $amount,
                'razorpay_order_id' => $rzOrder['id'] ?? null,
                'response' => $rzOrder,
            ]);
            return $rzOrder;
        } catch (Throwable $e) {
            OrderCartLog::error('razorpay_order_create_failed', [
                'order_id' => $orderId,
                'receipt' => $receipt,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function generateInvoice(int $orderId): string
    {
        $order = $this->getOrder($orderId);
        $dir = upload_path('invoices');
        $file = 'invoice-' . $order['invoice_no'] . '.html';
        $path = $dir . '/' . $file;

        $rows = '';
        foreach ($order['items'] as $item) {
            $rows .= '<tr>
                <td>' . e($item['product_name']) . '</td>
                <td>' . (int) $item['quantity'] . '</td>
                <td>' . money($item['unit_price']) . '</td>
                <td>' . money($item['line_total']) . '</td>
            </tr>';
        }

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice ' . e($order['invoice_no']) . '</title>
        <style>body{font-family:DejaVu Sans,Arial,sans-serif;color:#1a1a1a;padding:24px}
        h1{color:#978671}.meta{margin:16px 0}table{width:100%;border-collapse:collapse;margin-top:20px}
        th,td{border:1px solid #ddd;padding:8px;text-align:left}.totals{margin-top:16px;text-align:right}</style></head><body>
        <h1>' . e(config('company_name')) . '</h1>
        <p>Tax Invoice</p>
        <div class="meta">
          <div><strong>Invoice:</strong> ' . e($order['invoice_no']) . '</div>
          <div><strong>Order:</strong> ' . e($order['order_no']) . '</div>
          <div><strong>Date:</strong> ' . e($order['created_at']) . '</div>
          <div><strong>Customer:</strong> ' . e($order['customer_name']) . ' | ' . e($order['customer_mobile']) . '</div>
          <div><strong>Branch:</strong> ' . e($order['branch_name'] ?? '') . ' (' . e($order['city_name'] ?? '') . ')</div>
          <div><strong>Payment:</strong> ' . e($order['payment_status']) . ' | ' . e($order['razorpay_payment_id'] ?? '') . '</div>
        </div>
        <table><thead><tr><th>Service</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
        <tbody>' . $rows . '</tbody></table>
        <div class="totals">
          <div>Subtotal (incl. GST): ' . money($order['subtotal']) . '</div>
          <div>Coupon Discount: -' . money($order['coupon_discount']) . '</div>
          <div>GST included (' . e((string) $order['gst_percent']) . '%): ' . money($order['gst_amount']) . '</div>
          <div><strong>Grand Total (incl. GST): ' . money($order['grand_total']) . '</strong></div>
        </div>
        <p style="margin-top:32px;font-size:12px;color:#666">Thank you for choosing Allure Thai Spa.</p>
        </body></html>';

        file_put_contents($path, $html);
        $rel = 'uploads/invoices/' . $file;
        $this->db->prepare('UPDATE alluredeal_orders SET invoice_path = ? WHERE id = ?')->execute([$rel, $orderId]);
        return $rel;
    }

    public function sendEmailConfirmation(int $orderId): bool
    {
        $order = $this->getOrder($orderId);
        if (empty($order['customer_email'])) {
            return false;
        }
        $subject = 'Order Confirmed ' . $order['order_no'] . ' | ' . config('site_name');
        $body = "Dear {$order['customer_name']},\n\n"
            . "Thank you for your order {$order['order_no']}.\n"
            . "Invoice: {$order['invoice_no']}\n"
            . "Amount Paid: INR {$order['grand_total']}\n"
            . "Branch: " . ($order['branch_name'] ?? '') . "\n"
            . "Payment ID: " . ($order['razorpay_payment_id'] ?? '') . "\n\n"
            . "We look forward to welcoming you at Allure Thai Spa.\n"
            . "Support: " . config('support_phone') . " | " . config('support_email');

        $headers = 'From: ' . config('support_email') . "\r\n" .
            'Reply-To: ' . config('support_email') . "\r\n" .
            "Content-Type: text/plain; charset=UTF-8\r\n";

        $ok = @mail((string) $order['customer_email'], $subject, $body, $headers);
        if ($ok) {
            $this->db->prepare('UPDATE alluredeal_orders SET email_sent = 1 WHERE id = ?')->execute([$orderId]);
        }
        return (bool) $ok;
    }

    public function sendWhatsApp(int $orderId): bool
    {
        $order = $this->getOrder($orderId);
        $buyerOk = $this->sendWhatsAppToBuyer($order);
        $branchOk = $this->sendWhatsAppToBranch($order);
        if ($buyerOk) {
            $this->db->prepare('UPDATE alluredeal_orders SET whatsapp_sent = 1 WHERE id = ?')->execute([$orderId]);
        }
        return $buyerOk || $branchOk;
    }

    /** Payment confirmation to the buyer (named template params). */
    private function sendWhatsAppToBuyer(array $order): bool
    {
        $mobile = trim((string) ($order['customer_mobile'] ?? ''));
        if ($mobile === '') {
            $this->logWhatsApp((int) $order['id'], 'whatsapp_buyer_skipped', ['reason' => 'missing_mobile'], 'skipped');
            return false;
        }

        $branchName = (string) ($order['branch_name'] ?? '');
        $cityName = (string) ($order['city_name'] ?? '');
        $centre = trim($branchName !== '' ? ($cityName !== '' ? $branchName . ', ' . $cityName : $branchName) : $cityName);
        if ($centre === '') {
            $centre = 'Allure Thai Spa';
        }

        $itemLines = [];
        foreach ($order['items'] as $item) {
            $itemLines[] = $item['product_name'] . ' x' . $item['quantity'];
        }
        $services = implode(', ', $itemLines);
        if (mb_strlen($services) > 120) {
            $services = mb_substr($services, 0, 117) . '...';
        }
        if ($services === '') {
            $services = 'Spa service';
        }

        $amount = number_format((float) ($order['grand_total'] ?? 0), 2, '.', '');

        $payload = [
            'channelId' => gallabox_config('channel_id'),
            'channelType' => 'whatsapp',
            'recipient' => [
                'name' => (string) ($order['customer_name'] ?: 'Guest'),
                'phone' => format_phone_in($mobile),
            ],
            'whatsapp' => [
                'type' => 'template',
                'template' => [
                    'templateName' => (string) gallabox_config('buyer_template', 'allure_deal_confirmation'),
                    'bodyValues' => [
                        'fullName' => (string) ($order['customer_name'] ?: 'Guest'),
                        'orderId' => (string) ($order['order_no'] ?? ''),
                        'invoiceNumber' => (string) ($order['invoice_no'] ?? ''),
                        'amountPaid' => $amount,
                        'centreName' => $centre,
                        'serviceDetails' => $services,
                    ],
                ],
            ],
        ];

        return $this->dispatchGallabox($payload, (int) $order['id'], 'whatsapp_buyer');
    }

    /** Internal lead / branch alert (meta_lead template). */
    private function sendWhatsAppToBranch(array $order): bool
    {
        $branchName = trim((string) ($order['branch_name'] ?? ''));
        $dealBranchId = (int) ($order['branch_id'] ?? 0);
        $master = $this->resolveBranchMasterContact($dealBranchId, $branchName, (int) ($order['id'] ?? 0));

        $phone = (string) ($master['mobile'] ?? '');
        $recipientName = (string) ($master['label'] ?? '');
        if ($recipientName === '') {
            $recipientName = $branchName !== '' ? $branchName : (string) gallabox_config('default_name', 'Shailesh');
        }
        if ($phone === '') {
            // Last resort: deals branch whatsapp, then default
            $phone = trim((string) ($order['branch_whatsapp'] ?? ''));
        }
        if ($phone === '') {
            $phone = (string) gallabox_config('default_phone', '');
        }
        if ($phone === '') {
            $this->logWhatsApp((int) $order['id'], 'whatsapp_branch_skipped', [
                'reason' => 'missing_branch_mobile',
                'deal_branch_id' => $dealBranchId,
                'branch_name' => $branchName,
            ], 'skipped');
            return false;
        }

        $itemLines = [];
        foreach ($order['items'] as $item) {
            $line = (string) ($item['product_name'] ?? 'Deal');
            $qty = (int) ($item['quantity'] ?? 1);
            if ($qty > 1) {
                $line .= ' x' . $qty;
            }
            $dur = trim((string) ($item['duration'] ?? ''));
            if ($dur !== '') {
                $line .= ' (' . $dur . (ctype_digit($dur) ? ' Min' : '') . ')';
            }
            $itemLines[] = $line;
        }
        $dealNames = $itemLines !== [] ? implode(', ', $itemLines) : 'Spa deal';
        $amount = number_format((float) ($order['grand_total'] ?? 0), 2, '.', '');
        $gender = trim((string) ($order['customer_gender'] ?? ''));
        $email = trim((string) ($order['customer_email'] ?? ''));
        $notes = trim((string) ($order['notes'] ?? ''));

        $detailParts = [
            'Deal: ' . $dealNames,
            'Amount paid: INR ' . $amount,
        ];
        if ($gender !== '') {
            $detailParts[] = 'Gender: ' . $gender;
        }
        if ($email !== '') {
            $detailParts[] = 'Email: ' . $email;
        }
        if ($notes !== '') {
            $detailParts[] = 'Notes: ' . $notes;
        }
        if ($branchName !== '') {
            $detailParts[] = 'Branch: ' . $branchName;
        }
        $details = implode("\n", $detailParts);

        $payload = [
            'channelId' => gallabox_config('channel_id'),
            'channelType' => 'whatsapp',
            'recipient' => [
                'name' => $recipientName,
                'phone' => format_phone_in($phone),
            ],
            'whatsapp' => [
                'type' => 'template',
                'template' => [
                    'templateName' => gallabox_config('template', 'meta_lead'),
                    'bodyValues' => [
                        'sourceName' => 'Allure Deals',
                        'customerNumber' => format_phone_in((string) ($order['customer_mobile'] ?? '')),
                        'customerName' => (string) ($order['customer_name'] ?? 'Guest'),
                        'details' => $details,
                    ],
                ],
            ],
        ];

        return $this->dispatchGallabox($payload, (int) $order['id'], 'whatsapp_branch');
    }

    /**
     * Resolve WhatsApp mobile from Branch Master (allureone_branch.mobile_number)
     * for the purchased deal's branch.
     *
     * @return array{mobile:string,label:string}
     */
    private function resolveBranchMasterContact(int $dealBranchId, string $dealBranchName, int $orderId = 0): array
    {
        $empty = ['mobile' => '', 'label' => ''];
        try {
            // Direct ID match when deals branch id equals Branch Master id
            if ($dealBranchId > 0) {
                $st = $this->db->prepare(
                    'SELECT mobile_number, locality, business_name
                     FROM allureone_branch
                     WHERE id = ? AND isActive = 1
                     LIMIT 1'
                );
                $st->execute([$dealBranchId]);
                $byId = $st->fetch();
                if (is_array($byId)) {
                    $mobile = format_phone_in((string) ($byId['mobile_number'] ?? ''));
                    if ($mobile !== '') {
                        $loc = trim((string) ($byId['locality'] ?? ''));
                        $bn = trim((string) ($byId['business_name'] ?? ''));
                        return [
                            'mobile' => $mobile,
                            'label' => $loc !== '' ? $loc : $bn,
                        ];
                    }
                }
            }

            // Prefer explicit link column when present on deals branch table
            if ($dealBranchId > 0 && $this->dealsBranchHasAllureoneIdColumn()) {
                $st = $this->db->prepare(
                    'SELECT ab.mobile_number, ab.locality, ab.business_name
                     FROM alluredeal_branch db
                     INNER JOIN allureone_branch ab ON ab.id = db.allureone_branch_id
                     WHERE db.id = ? AND ab.isActive = 1
                     LIMIT 1'
                );
                $st->execute([$dealBranchId]);
                $linked = $st->fetch();
                if (is_array($linked)) {
                    $mobile = format_phone_in((string) ($linked['mobile_number'] ?? ''));
                    if ($mobile !== '') {
                        $loc = trim((string) ($linked['locality'] ?? ''));
                        $bn = trim((string) ($linked['business_name'] ?? ''));
                        return [
                            'mobile' => $mobile,
                            'label' => $loc !== '' ? $loc : $bn,
                        ];
                    }
                }
            }

            $rows = $this->db->query(
                "SELECT id, business_name, locality, mobile_number
                 FROM allureone_branch
                 WHERE isActive = 1
                   AND mobile_number IS NOT NULL
                   AND TRIM(mobile_number) <> ''"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($rows === []) {
                return $empty;
            }

            $needle = $this->normalizeBranchLabel($dealBranchName);
            $best = null;
            $bestScore = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $locality = $this->normalizeBranchLabel((string) ($row['locality'] ?? ''));
                $business = $this->normalizeBranchLabel((string) ($row['business_name'] ?? ''));
                $score = max(
                    $this->branchLabelMatchScore($needle, $locality),
                    $this->branchLabelMatchScore($needle, $business)
                );
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $row;
                }
            }

            // Require a reasonable match (exact / contains / strong token overlap)
            if ($best !== null && $bestScore >= 60) {
                $mobile = format_phone_in((string) ($best['mobile_number'] ?? ''));
                if ($mobile !== '') {
                    $loc = trim((string) ($best['locality'] ?? ''));
                    $bn = trim((string) ($best['business_name'] ?? ''));
                    return [
                        'mobile' => $mobile,
                        'label' => $loc !== '' ? $loc : $bn,
                    ];
                }
            }
        } catch (Throwable $e) {
            if ($orderId > 0) {
                $this->logWhatsApp(
                    $orderId,
                    'whatsapp_branch_master_lookup',
                    ['error' => $e->getMessage(), 'deal_branch_id' => $dealBranchId, 'name' => $dealBranchName],
                    'warning'
                );
            }
        }

        return $empty;
    }

    private function dealsBranchHasAllureoneIdColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $n = (int) $this->db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'alluredeal_branch'
                   AND COLUMN_NAME = 'allureone_branch_id'"
            )->fetchColumn();
            $has = $n > 0;
        } catch (Throwable $e) {
            $has = false;
        }

        return $has;
    }

    private function normalizeBranchLabel(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value;
    }

    private function branchLabelMatchScore(string $needle, string $candidate): int
    {
        if ($needle === '' || $candidate === '') {
            return 0;
        }
        if ($needle === $candidate) {
            return 100;
        }
        if (str_contains($candidate, $needle) || str_contains($needle, $candidate)) {
            return 80;
        }
        // Token-ish overlap via shared substrings of length >= 5
        $score = 0;
        $len = strlen($needle);
        for ($i = 0; $i <= $len - 5; $i++) {
            $part = substr($needle, $i, 5);
            if ($part !== '' && str_contains($candidate, $part)) {
                $score += 15;
            }
        }

        return min(70, $score);
    }

    private function dispatchGallabox(array $payload, int $orderId, string $event): bool
    {
        // Always use deployable includes/config/gallabox.php (matches api/refer.txt)
        $apiUrl = trim((string) gallabox_config('api_url', 'https://server.gallabox.com/devapi/messages/whatsapp'));
        $apiKey = trim((string) gallabox_config('api_key', ''));
        $apiSecret = trim((string) gallabox_config('api_secret', ''));

        if ($apiKey === '' || $apiSecret === '' || str_contains($apiKey, 'YOUR_GALLABOX')) {
            $this->logWhatsApp($orderId, $event . '_skipped', $payload, 'skipped');
            OrderCartLog::info('whatsapp_skipped', [
                'order_id' => $orderId,
                'event' => $event,
                'reason' => 'credentials_not_configured',
                'payload' => $payload,
            ]);
            return false;
        }

        // Same header style as api/refer.txt (working Meta lead sender)
        $headers = [
            'apiKey: ' . $apiKey,
            'apiSecret: ' . $apiSecret,
            'Content-Type: application/json',
        ];

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $response, true);
        $ok = is_array($data) && ((($data['status'] ?? '') === 'success') || ($http >= 200 && $http < 300 && !isset($data['status'])));
        // Explicit unauthorized is always a failure
        if (is_array($data) && strtolower((string) ($data['status'] ?? '')) === 'unauthorized') {
            $ok = false;
        }

        $logPayload = [
            'request' => $payload,
            'response' => $data ?? $response,
            'http' => $http,
            'curl_error' => $curlError !== '' ? $curlError : null,
            'api_key_prefix' => substr($apiKey, 0, 6) . '…',
            'api_secret_prefix' => substr($apiSecret, 0, 6) . '…',
            'api_url' => $apiUrl,
        ];
        $this->logWhatsApp($orderId, $event, $logPayload, $ok ? 'success' : 'failed');

        if ($ok) {
            OrderCartLog::success('whatsapp_api_call', [
                'order_id' => $orderId,
                'event' => $event,
                'http' => $http,
                'payload' => $payload,
                'response' => $data ?? $response,
                'api_key_prefix' => substr($apiKey, 0, 6) . '…',
            ]);
        } else {
            OrderCartLog::error('whatsapp_api_call', [
                'order_id' => $orderId,
                'event' => $event,
                'http' => $http,
                'curl_error' => $curlError !== '' ? $curlError : null,
                'payload' => $payload,
                'response' => $data ?? $response,
                'api_key_prefix' => substr($apiKey, 0, 6) . '…',
                'api_secret_prefix' => substr($apiSecret, 0, 6) . '…',
                'hint' => 'If Unauthorized, confirm includes/config/gallabox.php matches api/refer.txt',
            ]);
        }

        return $ok;
    }

    private function logWhatsApp(int $orderId, string $event, mixed $payload, string $status): void
    {
        $this->db->prepare(
            'INSERT INTO alluredeal_payment_logs (order_id, event, payload, status, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$orderId, $event, json_encode($payload), $status]);
    }
}
