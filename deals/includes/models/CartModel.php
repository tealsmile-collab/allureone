<?php
/**
 * Cart Model + Coupon Engine
 */

declare(strict_types=1);

class CartModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function getOrCreate(string $token): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM alluredeal_cart WHERE session_token = ? AND is_active = 1 AND is_deleted = 0 LIMIT 1'
        );
        $stmt->execute([$token]);
        $cart = $stmt->fetch();
        if ($cart) {
            return $cart;
        }
        $this->db->prepare(
            'INSERT INTO alluredeal_cart (session_token, created_at) VALUES (?, NOW())'
        )->execute([$token]);
        return [
            'id' => (int) $this->db->lastInsertId(),
            'session_token' => $token,
            'coupon_code' => null,
            'coupon_discount' => 0,
            'city_id' => null,
            'branch_id' => null,
        ];
    }

    public function summary(string $token): array
    {
        $cart = $this->getOrCreate($token);
        $stmt = $this->db->prepare(
            'SELECT ci.*, p.name, p.slug, p.image, p.duration, p.original_price AS product_original
             FROM alluredeal_cart_items ci
             JOIN alluredeal_product p ON p.id = ci.product_id
             WHERE ci.cart_id = ?
             ORDER BY ci.id ASC'
        );
        $stmt->execute([(int) $cart['id']]);
        $rows = $stmt->fetchAll();

        $items = [];
        $subtotal = 0.0;
        $originalTotal = 0.0;
        foreach ($rows as $r) {
            $line = (float) $r['unit_price'] * (int) $r['quantity'];
            $subtotal += $line;
            $originalTotal += (float) $r['original_price'] * (int) $r['quantity'];
            $items[] = [
                'id' => (int) $r['id'],
                'product_id' => (int) $r['product_id'],
                'name' => $r['name'],
                'slug' => $r['slug'],
                'image' => ProductModel::imageUrl($r['image'] ?? null),
                'duration' => (int) $r['duration'],
                'quantity' => (int) $r['quantity'],
                'unit_price' => (float) $r['unit_price'],
                'original_price' => (float) $r['original_price'],
                'line_total' => round($line, 2),
                'branch_id' => $r['branch_id'] ? (int) $r['branch_id'] : null,
                'city_id' => $r['city_id'] ? (int) $r['city_id'] : null,
            ];
        }

        $couponDiscount = (float) ($cart['coupon_discount'] ?? 0);
        if (!empty($cart['coupon_code'])) {
            $couponDiscount = $this->recalculateCoupon($cart, $subtotal, $items);
        }

        // Product/deal prices are GST-inclusive; do not add tax again.
        $grand = max(0, round($subtotal - $couponDiscount, 2));
        $gstPercent = (float) config('gst_percent', 18);
        $gst = gst_from_inclusive($grand, $gstPercent);

        return [
            'cart_id' => (int) $cart['id'],
            'items' => $items,
            'item_count' => array_sum(array_column($items, 'quantity')),
            'coupon_code' => $cart['coupon_code'],
            'subtotal' => round($subtotal, 2),
            'original_total' => round($originalTotal, 2),
            'coupon_discount' => round($couponDiscount, 2),
            'gst_percent' => $gstPercent,
            'gst_amount' => $gst,
            'grand_total' => $grand,
            'currency' => config('currency', 'INR'),
            'currency_symbol' => config('currency_symbol', '₹'),
            'city_id' => $cart['city_id'] ? (int) $cart['city_id'] : null,
            'branch_id' => $cart['branch_id'] ? (int) $cart['branch_id'] : null,
        ];
    }

    public function add(string $token, int $productId, int $qty = 1, ?int $cityId = null, ?int $branchId = null): array
    {
        $product = (new ProductModel())->find($productId);
        if (!$product) {
            throw new RuntimeException('Product not found');
        }
        $qty = max(1, $qty);
        $cart = $this->getOrCreate($token);

        $stmt = $this->db->prepare(
            'SELECT id, quantity FROM alluredeal_cart_items
             WHERE cart_id = ? AND product_id = ? AND IFNULL(branch_id,0) = IFNULL(?,0) LIMIT 1'
        );
        $stmt->execute([(int) $cart['id'], $productId, $branchId]);
        $existing = $stmt->fetch();

        if ($existing) {
            $this->db->prepare('UPDATE alluredeal_cart_items SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?')
                ->execute([$qty, (int) $existing['id']]);
        } else {
            $this->db->prepare(
                'INSERT INTO alluredeal_cart_items
                (cart_id, product_id, quantity, unit_price, original_price, city_id, branch_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $cart['id'],
                $productId,
                $qty,
                $product['offer_price'],
                $product['original_price'],
                $cityId,
                $branchId,
            ]);
        }

        if ($cityId) {
            $this->db->prepare('UPDATE alluredeal_cart SET city_id = ? WHERE id = ?')->execute([$cityId, (int) $cart['id']]);
        }
        if ($branchId) {
            $this->db->prepare('UPDATE alluredeal_cart SET branch_id = ? WHERE id = ?')->execute([$branchId, (int) $cart['id']]);
        }

        return $this->summary($token);
    }

    public function updateQty(string $token, int $itemId, int $qty): array
    {
        $cart = $this->getOrCreate($token);
        if ($qty <= 0) {
            return $this->remove($token, $itemId);
        }
        $this->db->prepare(
            'UPDATE alluredeal_cart_items SET quantity = ?, updated_at = NOW() WHERE id = ? AND cart_id = ?'
        )->execute([$qty, $itemId, (int) $cart['id']]);
        return $this->summary($token);
    }

    public function remove(string $token, int $itemId): array
    {
        $cart = $this->getOrCreate($token);
        $this->db->prepare('DELETE FROM alluredeal_cart_items WHERE id = ? AND cart_id = ?')
            ->execute([$itemId, (int) $cart['id']]);
        return $this->summary($token);
    }

    public function applyCoupon(string $token, string $code): array
    {
        $cart = $this->getOrCreate($token);
        $summary = $this->summary($token);
        $result = $this->validateCoupon($code, $summary);
        if (!$result['valid']) {
            throw new RuntimeException($result['message']);
        }
        $this->db->prepare(
            'UPDATE alluredeal_cart SET coupon_code = ?, coupon_discount = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$code, $result['discount'], (int) $cart['id']]);
        return $this->summary($token);
    }

    public function removeCoupon(string $token): array
    {
        $cart = $this->getOrCreate($token);
        $this->db->prepare(
            'UPDATE alluredeal_cart SET coupon_code = NULL, coupon_discount = 0 WHERE id = ?'
        )->execute([(int) $cart['id']]);
        return $this->summary($token);
    }

    public function clear(string $token): void
    {
        $cart = $this->getOrCreate($token);
        $this->db->prepare('DELETE FROM alluredeal_cart_items WHERE cart_id = ?')->execute([(int) $cart['id']]);
        $this->db->prepare(
            'UPDATE alluredeal_cart SET coupon_code = NULL, coupon_discount = 0 WHERE id = ?'
        )->execute([(int) $cart['id']]);
    }

    public function validateCoupon(string $code, array $summary): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return ['valid' => false, 'message' => 'Enter a coupon code', 'discount' => 0];
        }

        // One-time coupon
        $stmt = $this->db->prepare(
            'SELECT * FROM alluredeal_onetime_coupon
             WHERE code = ? AND is_active = 1 AND is_deleted = 0 LIMIT 1'
        );
        $stmt->execute([$code]);
        $ot = $stmt->fetch();
        if ($ot) {
            if ((int) $ot['is_used'] === 1) {
                return ['valid' => false, 'message' => 'Coupon already used', 'discount' => 0];
            }
            if ($ot['expires_at'] && strtotime($ot['expires_at']) < time()) {
                return ['valid' => false, 'message' => 'Coupon expired', 'discount' => 0];
            }
            if ($summary['subtotal'] < (float) $ot['min_order_amount']) {
                return ['valid' => false, 'message' => 'Minimum order not met', 'discount' => 0];
            }
            $discount = $this->calcDiscount($ot['discount_type'], (float) $ot['discount_value'], $summary['subtotal'], $ot['max_discount'] !== null ? (float) $ot['max_discount'] : null);
            return ['valid' => true, 'message' => 'Coupon applied', 'discount' => $discount, 'type' => 'onetime', 'id' => (int) $ot['id']];
        }

        // Marketing coupon
        $stmt = $this->db->prepare(
            'SELECT * FROM alluredeal_coupon WHERE code = ? AND is_active = 1 AND is_deleted = 0 LIMIT 1'
        );
        $stmt->execute([$code]);
        $c = $stmt->fetch();
        if (!$c) {
            return ['valid' => false, 'message' => 'Invalid coupon', 'discount' => 0];
        }
        if ($c['starts_at'] && strtotime($c['starts_at']) > time()) {
            return ['valid' => false, 'message' => 'Coupon not started yet', 'discount' => 0];
        }
        if ($c['expires_at'] && strtotime($c['expires_at']) < time()) {
            return ['valid' => false, 'message' => 'Coupon expired', 'discount' => 0];
        }
        if ($c['usage_limit'] !== null && (int) $c['used_count'] >= (int) $c['usage_limit']) {
            return ['valid' => false, 'message' => 'Coupon usage limit reached', 'discount' => 0];
        }
        if ($summary['subtotal'] < (float) $c['min_order_amount']) {
            return ['valid' => false, 'message' => 'Minimum order ₹' . number_format((float) $c['min_order_amount'], 0) . ' required', 'discount' => 0];
        }

        $discount = $this->calcDiscount($c['discount_type'], (float) $c['discount_value'], $summary['subtotal'], $c['max_discount'] !== null ? (float) $c['max_discount'] : null);
        return ['valid' => true, 'message' => 'Coupon applied', 'discount' => $discount, 'type' => 'marketing', 'id' => (int) $c['id']];
    }

    private function calcDiscount(string $type, float $value, float $subtotal, ?float $max = null): float
    {
        $discount = $type === 'percent' ? ($subtotal * $value / 100) : $value;
        if ($max !== null) {
            $discount = min($discount, $max);
        }
        return round(min($discount, $subtotal), 2);
    }

    private function recalculateCoupon(array $cart, float $subtotal, array $items): float
    {
        $summary = ['subtotal' => $subtotal, 'items' => $items, 'city_id' => $cart['city_id'], 'branch_id' => $cart['branch_id']];
        $result = $this->validateCoupon((string) $cart['coupon_code'], $summary);
        $discount = $result['valid'] ? $result['discount'] : 0;
        $this->db->prepare('UPDATE alluredeal_cart SET coupon_discount = ? WHERE id = ?')
            ->execute([$discount, (int) $cart['id']]);
        return $discount;
    }
}
