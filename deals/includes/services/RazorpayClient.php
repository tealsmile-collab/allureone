<?php
/**
 * Razorpay API client (India)
 * Auth: HTTP Basic — Key ID (user) : Key Secret (pass) on every request.
 */
declare(strict_types=1);

class RazorpayClient
{
    private string $keyId;
    private string $keySecret;
    private string $baseUrl = 'https://api.razorpay.com/v1';

    public function __construct(?string $keyId = null, ?string $keySecret = null)
    {
        $this->keyId = $keyId ?? (string) config('razorpay.key_id');
        $this->keySecret = $keySecret ?? (string) config('razorpay.key_secret');
    }

    /** GET with Basic Auth */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    /** POST with Basic Auth */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $body);
    }

    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        if ($amountPaise < 100) {
            throw new RuntimeException('Order amount must be at least ₹1.00');
        }

        // Razorpay receipt max length = 40
        $receipt = substr($receipt, 0, 40);

        return $this->post('/orders', [
            'amount' => $amountPaise,
            'currency' => (string) config('razorpay.currency', 'INR'),
            'receipt' => $receipt,
            'payment_capture' => 1,
            'notes' => $notes,
        ]);
    }

    public function fetchPayment(string $paymentId): array
    {
        return $this->get('/payments/' . rawurlencode($paymentId));
    }

    public function fetchOrder(string $orderId): array
    {
        return $this->get('/orders/' . rawurlencode($orderId));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $payload = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null;

        // Try with SSL verification first; fall back if CA store is broken (common on Windows / some shared hosts)
        $attempts = [
            ['verify' => true],
            ['verify' => false],
        ];

        $lastError = 'Unknown Razorpay error';

        foreach ($attempts as $i => $attempt) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => $this->keyId . ':' . $this->keySecret,
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_SSL_VERIFYPEER => $attempt['verify'],
                CURLOPT_SSL_VERIFYHOST => $attempt['verify'] ? 2 : 0,
            ];

            $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
            if ($attempt['verify'] && $ca && is_file($ca)) {
                $opts[CURLOPT_CAINFO] = $ca;
            }

            if ($payload !== null && strtoupper($method) !== 'GET') {
                $opts[CURLOPT_POSTFIELDS] = $payload;
            }

            curl_setopt_array($ch, $opts);
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($errno) {
                $lastError = 'Razorpay connection error: ' . $error;
                // Retry without SSL verify only on certificate problems
                if ($attempt['verify'] && (str_contains(strtolower($error), 'ssl') || str_contains(strtolower($error), 'certificate'))) {
                    continue;
                }
                throw new RuntimeException($lastError);
            }

            $data = json_decode((string) $response, true);
            if (!is_array($data)) {
                throw new RuntimeException('Invalid Razorpay response (HTTP ' . $code . ')');
            }

            if ($code >= 400) {
                $msg = $data['error']['description']
                    ?? $data['error']['reason']
                    ?? $data['error']['code']
                    ?? ('HTTP ' . $code);
                throw new RuntimeException('Razorpay: ' . $msg);
            }

            return $data;
        }

        throw new RuntimeException($lastError);
    }
}
