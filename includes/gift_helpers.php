<?php
declare(strict_types=1);

function format_purchase_date(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    $t = strtotime($dt);
    if ($t === false) {
        return '—';
    }
    return date('d-M-y', $t);
}

function extract_gift_code(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    if (preg_match('/\"([A-Z0-9\\-]{8,})\"/', $raw, $m) === 1) {
        return $m[1];
    }
    return $raw;
}

function format_amount($amount): string
{
    if ($amount === null || $amount === '') {
        return 'Rs 0.00';
    }
    return 'Rs ' . number_format((float) $amount, 2, '.', '');
}

function extract_email_value(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
        return $raw;
    }
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m) === 1) {
        return $m[0];
    }
    return '';
}

/**
 * When true, gift card lists only include orders whose WooCommerce billing_location matches the user's branch locality.
 * When false (default), all gift card line items are listed (branch locality must still be loaded for other uses).
 */
function gift_cards_filter_by_branch_locality_enabled(): bool
{
    $config = require __DIR__ . '/../config.php';

    return (bool) (($config['app']['filter_gift_cards_by_branch_locality'] ?? false));
}
