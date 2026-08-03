<?php
/**
 * Admin AJAX API
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once ROOT_PATH . '/includes/models/ProductModel.php';
require_once ROOT_PATH . '/includes/models/CatalogModel.php';
require_once ROOT_PATH . '/includes/services/OrderService.php';

Auth::requireAdmin();
Security::requireCsrf();

$in = json_input();
$action = $in['action'] ?? '';
$db = Database::getInstance();

try {
    switch ($action) {
        case 'delete':
            $type = $in['type'] ?? '';
            $id = (int) ($in['id'] ?? 0);
            $map = [
                'product' => 'alluredeal_product',
                'category' => 'alluredeal_category',
                'deal' => 'alluredeal_todaydeal',
                'slider' => 'alluredeal_slider',
                'coupon' => 'alluredeal_coupon',
                'onetime_coupon' => 'alluredeal_onetime_coupon',
                'policy' => 'alluredeal_policy',
            ];
            if (!isset($map[$type]) || !$id) {
                Response::error('Invalid delete request');
            }
            // Deals: soft-delete by marking inactive only
            if ($type === 'deal') {
                $prod = $db->prepare('SELECT product_id FROM alluredeal_todaydeal WHERE id = ? LIMIT 1');
                $prod->execute([$id]);
                $productId = (int) ($prod->fetchColumn() ?: 0);
                $db->prepare(
                    'UPDATE alluredeal_todaydeal SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?'
                )->execute([Auth::id(), $id]);
                if ($productId) {
                    $db->prepare('UPDATE alluredeal_product SET is_today_deal = 0 WHERE id = ?')->execute([$productId]);
                }
                activity_log('deactivate', 'deal', $id);
                Response::success(null, 'Deal deactivated');
            }
            $db->prepare("UPDATE {$map[$type]} SET is_deleted = 1, is_active = 0, updated_by = ? WHERE id = ?")
                ->execute([Auth::id(), $id]);
            activity_log('delete', $type, $id);
            Response::success(null, 'Deleted');

        case 'deactivate_deal':
            $id = (int) ($in['id'] ?? 0);
            if (!$id) {
                Response::error('Invalid deal');
            }
            $prod = $db->prepare('SELECT product_id FROM alluredeal_todaydeal WHERE id = ? LIMIT 1');
            $prod->execute([$id]);
            $productId = (int) ($in['product_id'] ?? 0);
            if (!$productId) {
                $productId = (int) ($prod->fetchColumn() ?: 0);
            }
            $db->prepare(
                'UPDATE alluredeal_todaydeal SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE id = ?'
            )->execute([Auth::id(), $id]);
            if ($productId) {
                $db->prepare('UPDATE alluredeal_product SET is_today_deal = 0 WHERE id = ?')->execute([$productId]);
            }
            activity_log('deactivate', 'deal', $id);
            Response::success(null, 'Deal deactivated');

        case 'save_product':
            $id = !empty($in['id']) ? (int) $in['id'] : null;
            $pid = (new ProductModel())->save($in, $id);
            activity_log('save', 'product', $pid);
            Response::success(['id' => $pid], 'Product saved');

        case 'save_category':
            $id = !empty($in['id']) ? (int) $in['id'] : null;
            $fields = [
                'name' => $in['name'] ?? '',
                'slug' => ($in['slug'] ?? '') ?: slugify((string) ($in['name'] ?? '')),
                'description' => $in['description'] ?? null,
                'display_order' => (int) ($in['display_order'] ?? 0),
                'seo_title' => $in['seo_title'] ?? null,
                'is_active' => (int) ($in['is_active'] ?? 1),
                'updated_by' => Auth::id(),
            ];
            if ($id) {
                $db->prepare('UPDATE alluredeal_category SET name=?, slug=?, description=?, display_order=?, seo_title=?, is_active=?, updated_by=? WHERE id=?')
                    ->execute([$fields['name'], $fields['slug'], $fields['description'], $fields['display_order'], $fields['seo_title'], $fields['is_active'], $fields['updated_by'], $id]);
            } else {
                $db->prepare('INSERT INTO alluredeal_category (name, slug, description, display_order, seo_title, is_active, created_by) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$fields['name'], $fields['slug'], $fields['description'], $fields['display_order'], $fields['seo_title'], $fields['is_active'], Auth::id()]);
                $id = (int) $db->lastInsertId();
            }
            Response::success(['id' => $id], 'Category saved');

        case 'save_deal':
            $id = !empty($in['id']) ? (int) $in['id'] : null;
            $endsAt = trim((string) ($in['ends_at'] ?? ''));
            $endsAt = $endsAt !== '' ? $endsAt : null;
            $params = [
                (int) $in['product_id'],
                $in['badge_text'] ?? "Today's Deal",
                (float) ($in['discount_percent'] ?? 0),
                (float) ($in['deal_price'] ?? 0),
                $in['starts_at'] ?: date('Y-m-d H:i:s'),
                $endsAt,
                (int) ($in['show_countdown'] ?? 0),
                (int) ($in['display_order'] ?? 0),
                (int) ($in['is_active'] ?? 1),
                Auth::id(),
            ];
            if ($id) {
                $db->prepare('UPDATE alluredeal_todaydeal SET product_id=?, badge_text=?, discount_percent=?, deal_price=?, starts_at=?, ends_at=?, show_countdown=?, display_order=?, is_active=?, updated_by=? WHERE id=?')
                    ->execute([...$params, $id]);
            } else {
                $db->prepare('INSERT INTO alluredeal_todaydeal (product_id, badge_text, discount_percent, deal_price, starts_at, ends_at, show_countdown, display_order, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute($params);
                $id = (int) $db->lastInsertId();
            }
            $dealPrice = (float) ($in['deal_price'] ?? 0);
            $discount = (float) ($in['discount_percent'] ?? 0);
            $original = $discount > 0 && $discount < 100
                ? round($dealPrice / (1 - $discount / 100), 2)
                : $dealPrice;
            $db->prepare('UPDATE alluredeal_product SET is_today_deal = 1, offer_price = ?, original_price = ?, discount_percent = ? WHERE id = ?')
                ->execute([$dealPrice, $original, $discount, (int) $in['product_id']]);
            Response::success(['id' => $id], 'Deal saved');

        case 'save_slider':
            $id = !empty($in['id']) ? (int) $in['id'] : null;
            $params = [
                $in['heading'] ?? null,
                $in['sub_heading'] ?? null,
                $in['cta_text'] ?? null,
                $in['cta_link'] ?? null,
                $in['desktop_image'] ?? 'assets/img/slider-1.jpg',
                $in['mobile_image'] ?? null,
                (int) ($in['priority'] ?? 0),
                $in['starts_at'] ?: null,
                $in['ends_at'] ?: null,
                (int) ($in['is_active'] ?? 1),
                Auth::id(),
            ];
            if ($id) {
                $db->prepare('UPDATE alluredeal_slider SET heading=?, sub_heading=?, cta_text=?, cta_link=?, desktop_image=?, mobile_image=?, priority=?, starts_at=?, ends_at=?, is_active=?, updated_by=? WHERE id=?')
                    ->execute([...$params, $id]);
            } else {
                $db->prepare('INSERT INTO alluredeal_slider (heading, sub_heading, cta_text, cta_link, desktop_image, mobile_image, priority, starts_at, ends_at, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute($params);
                $id = (int) $db->lastInsertId();
            }
            Response::success(['id' => $id], 'Slider saved');

        case 'save_coupon':
            $id = !empty($in['id']) ? (int) $in['id'] : null;
            $code = strtoupper(trim((string) ($in['code'] ?? '')));
            $params = [
                $code,
                $in['title'] ?? null,
                $in['discount_type'] ?? 'percent',
                (float) ($in['discount_value'] ?? 0),
                (float) ($in['min_order_amount'] ?? 0),
                $in['max_discount'] !== '' && $in['max_discount'] !== null ? (float) $in['max_discount'] : null,
                $in['usage_limit'] !== '' && $in['usage_limit'] !== null ? (int) $in['usage_limit'] : null,
                $in['starts_at'] ?: null,
                $in['expires_at'] ?: null,
                (int) ($in['is_active'] ?? 1),
                Auth::id(),
            ];
            if ($id) {
                $db->prepare('UPDATE alluredeal_coupon SET code=?, title=?, discount_type=?, discount_value=?, min_order_amount=?, max_discount=?, usage_limit=?, starts_at=?, expires_at=?, is_active=?, updated_by=? WHERE id=?')
                    ->execute([...$params, $id]);
            } else {
                $db->prepare('INSERT INTO alluredeal_coupon (code, title, discount_type, discount_value, min_order_amount, max_discount, usage_limit, starts_at, expires_at, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute($params);
                $id = (int) $db->lastInsertId();
            }
            Response::success(['id' => $id], 'Coupon saved');

        case 'save_onetime_coupon':
            $code = strtoupper(trim((string) ($in['code'] ?? '')));
            $db->prepare('INSERT INTO alluredeal_onetime_coupon (code, discount_type, discount_value, max_discount, min_order_amount, expires_at, created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([
                    $code,
                    $in['discount_type'] ?? 'percent',
                    (float) ($in['discount_value'] ?? 0),
                    $in['max_discount'] !== '' && isset($in['max_discount']) ? (float) $in['max_discount'] : null,
                    (float) ($in['min_order_amount'] ?? 0),
                    $in['expires_at'] ?: null,
                    Auth::id(),
                ]);
            Response::success(['id' => (int) $db->lastInsertId()], 'One-time coupon created');

        case 'save_policy':
            $id = !empty($in['id']) ? (int) $in['id'] : null;
            if ($id) {
                $db->prepare('UPDATE alluredeal_policy SET title=?, content=?, display_order=?, is_active=?, updated_by=? WHERE id=?')
                    ->execute([$in['title'], $in['content'], (int) ($in['display_order'] ?? 0), (int) ($in['is_active'] ?? 1), Auth::id(), $id]);
            }
            Response::success(['id' => $id], 'Policy saved');

        case 'save_settings':
            foreach (($in['settings'] ?? []) as $key => $value) {
                $db->prepare(
                    'INSERT INTO alluredeal_settings (setting_key, setting_value, updated_by)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
                )->execute([$key, $value, Auth::id()]);
            }
            Response::success(null, 'Settings saved');

        case 'upload':
            Response::error('Use multipart upload endpoint admin/upload.php');

        default:
            Response::error('Unknown action', 404);
    }
} catch (Throwable $e) {
    Response::error($e->getMessage(), 400);
}
