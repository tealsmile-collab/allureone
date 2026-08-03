<?php
/**
 * Product Model
 */

declare(strict_types=1);

class ProductModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function list(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        $where = ['p.is_deleted = 0', 'p.is_active = 1'];
        $params = [];

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = (int) $filters['category_id'];
        }
        if (!empty($filters['category_slug'])) {
            $where[] = 'c.slug = ?';
            $params[] = $filters['category_slug'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.name LIKE ? OR p.short_description LIKE ?)';
            $q = '%' . $filters['q'] . '%';
            $params[] = $q;
            $params[] = $q;
        }
        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $where[] = 'p.offer_price >= ?';
            $params[] = (float) $filters['min_price'];
        }
        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $where[] = 'p.offer_price <= ?';
            $params[] = (float) $filters['max_price'];
        }
        if (!empty($filters['min_discount'])) {
            $where[] = 'p.discount_percent >= ?';
            $params[] = (float) $filters['min_discount'];
        }
        if (!empty($filters['duration'])) {
            $where[] = 'p.duration = ?';
            $params[] = (int) $filters['duration'];
        }
        if (!empty($filters['today_deal']) && !in_array((string) $filters['today_deal'], ['0', 'false'], true)) {
            $where[] = 'p.is_today_deal = 1';
        }
        if (!empty($filters['featured'])) {
            $where[] = 'p.is_featured = 1';
        }
        if (!empty($filters['city_id'])) {
            $cityId = (int) $filters['city_id'];
            // All locations, city listed, or any selected branch belonging to this city
            $where[] = '(
                d.all_locations = 1
                OR JSON_CONTAINS(COALESCE(d.city_ids, JSON_ARRAY()), ?, \'$\')
                OR EXISTS (
                    SELECT 1 FROM alluredeal_branch b
                    WHERE b.city_id = ?
                      AND b.is_active = 1 AND b.is_deleted = 0
                      AND JSON_CONTAINS(COALESCE(d.branch_ids, JSON_ARRAY()), CAST(b.id AS JSON), \'$\')
                )
            )';
            $params[] = json_encode($cityId);
            $params[] = $cityId;
        }
        if (!empty($filters['branch_id'])) {
            $branchId = (int) $filters['branch_id'];
            $where[] = '(
                d.all_locations = 1
                OR JSON_CONTAINS(COALESCE(d.branch_ids, JSON_ARRAY()), ?, \'$\')
                OR (
                    (d.branch_ids IS NULL OR JSON_LENGTH(COALESCE(d.branch_ids, JSON_ARRAY())) = 0)
                    AND EXISTS (
                        SELECT 1 FROM alluredeal_branch b
                        WHERE b.id = ?
                          AND JSON_CONTAINS(COALESCE(d.city_ids, JSON_ARRAY()), CAST(b.city_id AS JSON), \'$\')
                    )
                )
            )';
            $params[] = json_encode($branchId);
            $params[] = $branchId;
        }

        $sort = match ($filters['sort'] ?? 'popular') {
            'price_asc'  => 'p.offer_price ASC',
            'price_desc' => 'p.offer_price DESC',
            'discount'   => 'p.discount_percent DESC',
            'newest'     => 'p.id DESC',
            default      => 'p.display_order ASC, p.is_bestseller DESC, p.id DESC',
        };

        $whereSql = implode(' AND ', $where);
        $dealJoin = "LEFT JOIN alluredeal_todaydeal d
                  ON d.product_id = p.id AND d.is_active = 1 AND d.is_deleted = 0
                  AND d.starts_at <= NOW() AND (d.ends_at IS NULL OR d.ends_at >= NOW())";
        $countStmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT p.id) FROM alluredeal_product p
             LEFT JOIN alluredeal_category c ON c.id = p.category_id
             {$dealJoin}
             WHERE {$whereSql}"
        );
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = max(0, ($page - 1) * $perPage);
        $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                       d.ends_at AS deal_ends_at, d.badge_text, d.show_countdown,
                       d.all_locations AS deal_all_locations, d.city_ids AS deal_city_ids
                FROM alluredeal_product p
                LEFT JOIN alluredeal_category c ON c.id = p.category_id
                {$dealJoin}
                WHERE {$whereSql}
                ORDER BY {$sort}
                LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();

        return [
            'items' => array_map([$this, 'format'], $items),
            'total' => $total,
            'page'  => $page,
            'pages' => (int) ceil($total / max(1, $perPage)),
            'per_page' => $perPage,
        ];
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug,
                    d.ends_at AS deal_ends_at, d.badge_text, d.show_countdown
             FROM alluredeal_product p
             LEFT JOIN alluredeal_category c ON c.id = p.category_id
             LEFT JOIN alluredeal_todaydeal d
               ON d.product_id = p.id AND d.is_active = 1 AND d.is_deleted = 0
               AND d.starts_at <= NOW() AND (d.ends_at IS NULL OR d.ends_at >= NOW())
             WHERE p.slug = ? AND p.is_deleted = 0 AND p.is_active = 1
             LIMIT 1'
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ? $this->format($row, true) : null;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name
             FROM alluredeal_product p
             LEFT JOIN alluredeal_category c ON c.id = p.category_id
             WHERE p.id = ? AND p.is_deleted = 0 LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->format($row, true) : null;
    }

    public function related(int $categoryId, int $excludeId, int $limit = 4): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, c.name AS category_name, c.slug AS category_slug
             FROM alluredeal_product p
             LEFT JOIN alluredeal_category c ON c.id = p.category_id
             WHERE p.category_id = ? AND p.id <> ? AND p.is_active = 1 AND p.is_deleted = 0
             ORDER BY p.is_bestseller DESC, p.id DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(2, $excludeId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'format'], $stmt->fetchAll());
    }

    public function gallery(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, image, alt_text FROM alluredeal_product_images
             WHERE product_id = ? AND is_active = 1 AND is_deleted = 0
             ORDER BY display_order ASC, id ASC'
        );
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    public function save(array $data, ?int $id = null): int
    {
        $original = (float) ($data['original_price'] ?? 0);
        $offer = (float) ($data['offer_price'] ?? 0);
        $discount = isset($data['discount_percent'])
            ? (float) $data['discount_percent']
            : discount_percent($original, $offer);

        $fields = [
            'category_id' => (int) $data['category_id'],
            'name' => $data['name'],
            'slug' => $data['slug'] ?: slugify($data['name']),
            'short_description' => $data['short_description'] ?? null,
            'long_description' => $data['long_description'] ?? null,
            'benefits' => $data['benefits'] ?? null,
            'duration' => (int) ($data['duration'] ?? 0),
            'original_price' => $original,
            'offer_price' => $offer,
            'discount_percent' => $discount,
            'auto_strike_price' => (int) ($data['auto_strike_price'] ?? 1),
            'image' => $data['image'] ?? null,
            'is_today_deal' => (int) ($data['is_today_deal'] ?? 0),
            'is_featured' => (int) ($data['is_featured'] ?? 0),
            'is_bestseller' => (int) ($data['is_bestseller'] ?? 0),
            'display_order' => (int) ($data['display_order'] ?? 0),
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
            'seo_keywords' => $data['seo_keywords'] ?? null,
            'is_active' => (int) ($data['is_active'] ?? 1),
        ];

        if ($id) {
            $fields['updated_by'] = Auth::id();
            $sets = [];
            $params = [];
            foreach ($fields as $k => $v) {
                $sets[] = "`$k` = ?";
                $params[] = $v;
            }
            $params[] = $id;
            $this->db->prepare('UPDATE alluredeal_product SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($params);
            return $id;
        }

        $fields['created_by'] = Auth::id();
        $cols = array_keys($fields);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $this->db->prepare(
            'INSERT INTO alluredeal_product (`' . implode('`,`', $cols) . '`) VALUES (' . $placeholders . ')'
        )->execute(array_values($fields));
        return (int) $this->db->lastInsertId();
    }

    public function softDelete(int $id): void
    {
        $this->db->prepare(
            'UPDATE alluredeal_product SET is_deleted = 1, is_active = 0, updated_by = ? WHERE id = ?'
        )->execute([Auth::id(), $id]);
    }

    private function format(array $row, bool $full = false): array
    {
        $original = (float) $row['original_price'];
        $offer = (float) $row['offer_price'];
        $out = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'slug' => $row['slug'],
            'category_id' => (int) $row['category_id'],
            'category_name' => $row['category_name'] ?? null,
            'category_slug' => $row['category_slug'] ?? null,
            'short_description' => $row['short_description'],
            'duration' => (int) ($row['duration'] ?? 0),
            'original_price' => $original,
            'offer_price' => $offer,
            'discount_percent' => (float) $row['discount_percent'],
            'save_amount' => save_amount($original, $offer),
            'image_path' => $row['image'],
            'image' => self::imageUrl($row['image'] ?? null),
            'rating' => (float) ($row['rating'] ?? 4.5),
            'rating_count' => (int) ($row['rating_count'] ?? 0),
            'is_today_deal' => (int) ($row['is_today_deal'] ?? 0),
            'is_featured' => (int) ($row['is_featured'] ?? 0),
            'is_bestseller' => (int) ($row['is_bestseller'] ?? 0),
            'badge_text' => $row['badge_text'] ?? config('offers.deal_badge_text'),
            'deal_ends_at' => $row['deal_ends_at'] ?? null,
            'show_countdown' => (int) ($row['show_countdown'] ?? 1),
            'auto_strike_price' => (int) ($row['auto_strike_price'] ?? 1),
        ];

        if ($full) {
            $out['long_description'] = $row['long_description'] ?? '';
            $out['benefits'] = $row['benefits'] ?? '';
            $out['seo_title'] = $row['seo_title'] ?? $row['name'];
            $out['seo_description'] = $row['seo_description'] ?? $row['short_description'];
            $out['gallery'] = $this->gallery((int) $row['id']);
        }

        return $out;
    }

    public static function imageUrl(?string $path): string
    {
        if ($path === null || $path === '') {
            return asset_url('assets/img/product-placeholder.svg');
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        return asset_url(ltrim($path, '/'));
    }
}
