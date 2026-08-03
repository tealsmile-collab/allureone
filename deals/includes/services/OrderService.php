<?php
/**
 * Order + Razorpay + WhatsApp + Email services
 */

declare(strict_types=1);

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
                format_phone_in($customer['mobile']),
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

            $mobileDigits = preg_replace('/\D+/', '', (string) $customer['mobile']) ?? '';
                if (strlen($mobileDigits) > 10 && str_starts_with($mobileDigits, '91')) {
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
            throw $e;
        }
    }

    public function verifyPayment(array $payload): array
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $rzOrderId = (string) ($payload['razorpay_order_id'] ?? '');
        $rzPaymentId = (string) ($payload['razorpay_payment_id'] ?? '');
        $rzSignature = (string) ($payload['razorpay_signature'] ?? '');

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
            throw new RuntimeException('Payment signature verification failed');
        }

        // Optional server-side GET confirmation via Basic Auth
        try {
            require_once ROOT_PATH . '/includes/services/RazorpayClient.php';
            $payment = (new RazorpayClient())->fetchPayment($rzPaymentId);
            if (($payment['status'] ?? '') === 'failed') {
                throw new RuntimeException('Razorpay payment status is failed');
            }
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
        $mobile = format_phone_in($customer['mobile']);
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

        $client = new RazorpayClient();
        return $client->createOrder(
            (int) round($amount * 100),
            $receipt,
            ['order_id' => (string) $orderId]
        );
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
        $branchName = $order['branch_name'] ?? 'Franchise';
        $phones = (array) config('branch_phones', []);
        $phone = $order['branch_whatsapp']
            ?: ($phones[$branchName] ?? config('gallabox.default_phone'));
        $recipientName = $branchName ?: config('gallabox.default_name');

        $itemLines = [];
        foreach ($order['items'] as $item) {
            $itemLines[] = $item['product_name'] . ' x' . $item['quantity'];
        }
        $details = 'Preferred Location - ' . ($order['city_name'] ?? $branchName)
            . ' | Order ' . $order['order_no']
            . ' | Amount INR ' . $order['grand_total']
            . ' | ' . implode(', ', $itemLines);

        $payload = [
            'channelId' => config('gallabox.channel_id'),
            'channelType' => 'whatsapp',
            'recipient' => [
                'name' => $recipientName,
                'phone' => format_phone_in((string) $phone),
            ],
            'whatsapp' => [
                'type' => 'template',
                'template' => [
                    'templateName' => config('gallabox.template', 'meta_lead'),
                    'bodyValues' => [
                        'sourceName' => 'Order details',
                        'customerNumber' => format_phone_in((string) $order['customer_mobile']),
                        'customerName' => $order['customer_name'],
                        'details' => $details,
                    ],
                ],
            ],
        ];

        $apiKey = (string) config('gallabox.api_key');
        $apiSecret = (string) config('gallabox.api_secret');
        if (str_contains($apiKey, 'gallabox_api_key')) {
            // Credentials not configured — log and skip
            $this->db->prepare(
                'INSERT INTO alluredeal_payment_logs (order_id, event, payload, status, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([$orderId, 'whatsapp_skipped', json_encode($payload), 'skipped']);
            return false;
        }

        $ch = curl_init((string) config('gallabox.api_url'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apiKey: ' . $apiKey,
                'apiSecret: ' . $apiSecret,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode((string) $response, true);
        $ok = is_array($data) && (($data['status'] ?? '') === 'success' || $http >= 200 && $http < 300);

        $this->db->prepare(
            'INSERT INTO alluredeal_payment_logs (order_id, event, payload, status, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([
            $orderId,
            'whatsapp_sent',
            json_encode(['request' => $payload, 'response' => $data]),
            $ok ? 'success' : 'failed',
        ]);

        if ($ok) {
            $this->db->prepare('UPDATE alluredeal_orders SET whatsapp_sent = 1 WHERE id = ?')->execute([$orderId]);
        }
        return $ok;
    }
}
