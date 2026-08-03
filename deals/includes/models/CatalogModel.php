<?php
/**
 * Catalog helpers: categories, cities, branches, slider, settings
 */

declare(strict_types=1);

class CatalogModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function categories(): array
    {
        return $this->db->query(
            'SELECT id, name, slug, image, icon, display_order
             FROM alluredeal_category
             WHERE is_active = 1 AND is_deleted = 0
             ORDER BY display_order ASC, name ASC'
        )->fetchAll();
    }

    public function cities(): array
    {
        return $this->db->query(
            'SELECT id, name, slug FROM alluredeal_city
             WHERE is_active = 1 AND is_deleted = 0
             ORDER BY display_order ASC, name ASC'
        )->fetchAll();
    }

    public function branches(?int $cityId = null): array
    {
        if ($cityId) {
            $stmt = $this->db->prepare(
                'SELECT id, city_id, name, slug, phone, whatsapp, address
                 FROM alluredeal_branch
                 WHERE city_id = ? AND is_active = 1 AND is_deleted = 0
                 ORDER BY display_order ASC, name ASC'
            );
            $stmt->execute([$cityId]);
            return $stmt->fetchAll();
        }
        return $this->db->query(
            'SELECT b.id, b.city_id, b.name, b.slug, b.phone, b.whatsapp, b.address, c.name AS city_name
             FROM alluredeal_branch b
             JOIN alluredeal_city c ON c.id = b.city_id
             WHERE b.is_active = 1 AND b.is_deleted = 0
             ORDER BY c.display_order, b.display_order'
        )->fetchAll();
    }

    public function sliders(): array
    {
        $rows = $this->db->query(
            'SELECT * FROM alluredeal_slider
             WHERE is_active = 1 AND is_deleted = 0
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
             ORDER BY priority ASC, id DESC'
        )->fetchAll();
        return array_map(static function ($r) {
            $r['desktop_image'] = asset_url($r['desktop_image']);
            $r['mobile_image'] = asset_url($r['mobile_image'] ?: $r['desktop_image']);
            return $r;
        }, $rows);
    }

    public function policies(): array
    {
        return $this->db->query(
            'SELECT id, slug, title FROM alluredeal_policy
             WHERE is_active = 1 AND is_deleted = 0
             ORDER BY display_order ASC'
        )->fetchAll();
    }

    public function policy(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM alluredeal_policy WHERE slug = ? AND is_active = 1 AND is_deleted = 0 LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function dashboardStats(): array
    {
        $revenue = (float) $this->db->query(
            "SELECT COALESCE(SUM(grand_total),0) FROM alluredeal_orders WHERE payment_status = 'paid' AND is_deleted = 0"
        )->fetchColumn();
        $orders = (int) $this->db->query(
            "SELECT COUNT(*) FROM alluredeal_orders WHERE is_deleted = 0"
        )->fetchColumn();
        $couponUsage = (int) $this->db->query('SELECT COUNT(*) FROM alluredeal_coupon_usage')->fetchColumn();
        $customers = (int) $this->db->query(
            'SELECT COUNT(*) FROM alluredeal_customer WHERE is_deleted = 0'
        )->fetchColumn();

        $topProducts = $this->db->query(
            "SELECT oi.product_name, SUM(oi.quantity) qty, SUM(oi.line_total) revenue
             FROM alluredeal_order_items oi
             JOIN alluredeal_orders o ON o.id = oi.order_id
             WHERE o.payment_status = 'paid'
             GROUP BY oi.product_id, oi.product_name
             ORDER BY qty DESC LIMIT 5"
        )->fetchAll();

        $salesGraph = $this->db->query(
            "SELECT DATE(created_at) d, SUM(grand_total) total, COUNT(*) cnt
             FROM alluredeal_orders
             WHERE payment_status = 'paid' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
             GROUP BY DATE(created_at) ORDER BY d ASC"
        )->fetchAll();

        $byCity = $this->db->query(
            "SELECT COALESCE(c.name,'Unknown') name, COUNT(*) cnt, SUM(o.grand_total) revenue
             FROM alluredeal_orders o
             LEFT JOIN alluredeal_city c ON c.id = o.city_id
             WHERE o.payment_status = 'paid'
             GROUP BY o.city_id, c.name ORDER BY revenue DESC LIMIT 8"
        )->fetchAll();

        $byBranch = $this->db->query(
            "SELECT COALESCE(b.name,'Unknown') name, COUNT(*) cnt, SUM(o.grand_total) revenue
             FROM alluredeal_orders o
             LEFT JOIN alluredeal_branch b ON b.id = o.branch_id
             WHERE o.payment_status = 'paid'
             GROUP BY o.branch_id, b.name ORDER BY revenue DESC LIMIT 8"
        )->fetchAll();

        return compact('revenue', 'orders', 'couponUsage', 'customers', 'topProducts', 'salesGraph', 'byCity', 'byBranch');
    }
}
