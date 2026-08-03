<?php
/**
 * Import products from Allure Thane menu card
 * Source: https://allurethaispa.in/spa-in-thane-west-menu-card.html
 * Each duration = separate product (same image shared).
 *
 * CLI: php database/import_thane_menu.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once ROOT_PATH . '/includes/models/ProductModel.php';

$db = Database::getInstance();

function ensureCategory(PDO $db, string $name, string $slug, int $order = 50): int
{
    $stmt = $db->prepare('SELECT id FROM alluredeal_category WHERE slug = ? AND is_deleted = 0 LIMIT 1');
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $db->prepare(
        'INSERT INTO alluredeal_category (name, slug, display_order, is_active, created_at)
         VALUES (?, ?, ?, 1, NOW())'
    )->execute([$name, $slug, $order]);
    return (int) $db->lastInsertId();
}

function upsertProduct(PDO $db, array $p): void
{
    $slug = $p['slug'];
    $stmt = $db->prepare('SELECT id FROM alluredeal_product WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $id = $stmt->fetchColumn();

    $fields = [
        $p['category_id'],
        $p['name'],
        $slug,
        $p['short'],
        $p['long'],
        $p['benefits'],
        $p['duration'],
        $p['original'],
        $p['offer'],
        $p['discount'],
        $p['image'],
        $p['featured'],
        $p['bestseller'],
        $p['deal'],
        $p['order'],
        $p['seo_title'],
        1, // active
        0, // not deleted
    ];

    if ($id) {
        $db->prepare(
            'UPDATE alluredeal_product SET
             category_id=?, name=?, slug=?, short_description=?, long_description=?, benefits=?,
             duration=?, original_price=?, offer_price=?, discount_percent=?, image=?,
             is_featured=?, is_bestseller=?, is_today_deal=?, display_order=?, seo_title=?,
             is_active=?, is_deleted=?, updated_at=NOW()
             WHERE id=?'
        )->execute([...$fields, (int) $id]);
    } else {
        $db->prepare(
            'INSERT INTO alluredeal_product
             (category_id, name, slug, short_description, long_description, benefits,
              duration, original_price, offer_price, discount_percent, image,
              is_featured, is_bestseller, is_today_deal, display_order, seo_title,
              is_active, is_deleted, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        )->execute($fields);
    }
}

// Soft-delete previous demo seed products (keep menu imports)
$db->exec(
    "UPDATE alluredeal_product SET is_deleted = 1, is_active = 0
     WHERE slug IN (
       'classic-thai-massage-60','swedish-relaxation-60','deep-tissue-90',
       'hot-stone-75','glow-facial-45','gift-voucher-2000'
     )"
);

$cats = [
    'swedish' => ensureCategory($db, 'Swedish Massage', 'swedish-massage', 1),
    'deep' => ensureCategory($db, 'Deep Tissue', 'deep-tissue', 2),
    'balinese' => ensureCategory($db, 'Balinese', 'balinese', 3),
    'aroma' => ensureCategory($db, 'Aromatherapy', 'aromatherapy', 4),
    'thai' => ensureCategory($db, 'Thai Massage', 'thai-massage', 5),
    'enhancement' => ensureCategory($db, 'Enhancements', 'enhancements', 6),
    'hotstone' => ensureCategory($db, 'Hot Stone', 'hot-stone', 7),
    'potli' => ensureCategory($db, 'Potli Massage', 'potli-massage', 8),
    'ayurveda' => ensureCategory($db, 'Ayurveda Therapies', 'ayurveda-therapies', 9),
    'couple' => ensureCategory($db, 'Couple Massage', 'couple-massage', 10),
    'holistic' => ensureCategory($db, 'Holistic Healing', 'holistic-healing', 11),
    'body' => ensureCategory($db, 'Body Scrub', 'body-scrub', 12),
    'foot' => ensureCategory($db, 'Foot Reflexology', 'foot-reflexology', 13),
    'mind' => ensureCategory($db, 'Mind & Sole Rituals', 'mind-sole-rituals', 14),
];

/**
 * Visible services from Thane menu (hidden SHOW_* flags excluded)
 * Source: https://allurethaispa.in/spa-in-thane-west-menu-card.html
 */
$services = [
    [
        'cat' => 'swedish',
        'title' => 'Swedish Massage',
        'desc' => 'Soothing massage featuring long, gliding strokes and light-to-medium pressure to relax the body, ease tension and enhance overall circulation.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2025/01/Swedish-Massage-In-Mumbai--600x300.png',
        'benefits' => 'Gentle full-body unwind; Improves circulation; Ideal first spa ritual',
        'featured' => 1, 'bestseller' => 1, 'deal' => 1,
        'prices' => [['d' => 45, 'p' => 2500], ['d' => 60, 'p' => 2800], ['d' => 90, 'p' => 4000], ['d' => 120, 'p' => 4500]],
    ],
    [
        'cat' => 'deep',
        'title' => 'Deep Tissue Massage',
        'desc' => 'Deeper pressure is beneficial in relaxation of chronic muscle tension of the body.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2025/03/Deep-Tissue-Massage.png',
        'benefits' => 'Releases stubborn knots; Supports posture relief; Therapist favourite for tension',
        'featured' => 1, 'bestseller' => 1, 'deal' => 1,
        'prices' => [['d' => 45, 'p' => 3000], ['d' => 60, 'p' => 3500], ['d' => 90, 'p' => 4799], ['d' => 120, 'p' => 5999]],
    ],
    [
        'cat' => 'balinese',
        'title' => 'Balinese Massage',
        'desc' => 'Combination of gentle stretches, acupressure, reflexology and aromatherapy that stimulate the flow of blood bringing a sense of calm and well being.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2025/04/Balinese-Massage-600x400.jpeg',
        'benefits' => 'Stretch + pressure blend; Calms mind & body; Aromatic wellness finish',
        'featured' => 1, 'bestseller' => 0, 'deal' => 1,
        'prices' => [['d' => 45, 'p' => 3000], ['d' => 60, 'p' => 3500], ['d' => 90, 'p' => 4799], ['d' => 120, 'p' => 5999]],
    ],
    [
        'cat' => 'aroma',
        'title' => 'Aromatherapy Massage',
        'desc' => 'Indulge in a holistic ritual that nurtures body, mind, and spirit, guiding you into a profound state of relaxation with a curated blend of potent essential oils.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2024/02/essential-oils-oil-fragrance-2918183-768x511.jpg',
        'benefits' => 'Essential oil immersion; Emotional reset; Sensorial luxury',
        'featured' => 1, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 45, 'p' => 2500], ['d' => 60, 'p' => 2800], ['d' => 90, 'p' => 3999], ['d' => 120, 'p' => 4799]],
    ],
    [
        'cat' => 'thai',
        'title' => 'Thai Dry Massage',
        'desc' => 'Traditional dry massage combining acupressure, assisted stretching and deep compression techniques inspired by yoga and Shiatsu to relieve tension and improve flexibility.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2026/07/thai-dry-massage.jpg',
        'benefits' => 'No oil required; Improves flexibility; Yoga-inspired stretches',
        'featured' => 1, 'bestseller' => 1, 'deal' => 1,
        'prices' => [['d' => 45, 'p' => 2500], ['d' => 60, 'p' => 3200], ['d' => 90, 'p' => 4500]],
    ],
    [
        'cat' => 'enhancement',
        'title' => 'Thai Herbal Balm',
        'desc' => 'Powerful herbal formulation that penetrates deep into muscles to relieve stiffness, stubborn knots and chronic pain. Add-on enhancement available with Timeless Classic Therapies.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2023/08/pexels-cottonbro-studio-3997983-768x1152.jpg',
        'benefits' => 'Pairs with Classic Therapies; Relieves stubborn knots; Deep herbal penetration',
        'featured' => 1, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 0, 'p' => 999, 'label' => 'Add-on']],
    ],
    [
        'cat' => 'hotstone',
        'title' => 'Hot Stone Therapy',
        'desc' => 'Indulgent therapy that promotes deep muscular relaxation through the strategic placement of smooth, water-heated basalt stones, melting away tension and restoring inner balance.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2024/02/stones-spa-massage-3184610-768x512.jpg',
        'benefits' => 'Melt-away muscle ease; Warmth-led balance; Deep restorative calm',
        'featured' => 1, 'bestseller' => 1, 'deal' => 1,
        'prices' => [['d' => 90, 'p' => 5499], ['d' => 120, 'p' => 6999]],
    ],
    [
        'cat' => 'potli',
        'title' => 'Potli Therapy',
        'desc' => 'Herb-infused potli therapy helps reduce stiffness and soreness associated with conditions like spondylosis, frozen shoulder & arthritis. It also helps rejuvenate overall body functions.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2023/07/closeup-man-getting-back-massage-with-detox-herbal-balls-health-spa-768x512.jpg',
        'benefits' => 'Herb-packed warmth; Joint & muscle comfort; Whole-body rejuvenation',
        'featured' => 1, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 90, 'p' => 5499], ['d' => 120, 'p' => 6999]],
    ],
    [
        'cat' => 'ayurveda',
        'title' => 'Abhyanga Therapy',
        'desc' => 'Warm ayurvedic oil is applied to entire body using long, rhythmic strokes. Special techniques stimulate energy points & release blockages, helping to balance body and mind.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2023/07/masseur-does-back-massage-young-guy-beauty-salon-768x539.jpg',
        'benefits' => 'Warm oil immersion; Energy point balance; Mind-body harmony',
        'featured' => 0, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 120, 'p' => 5499], ['d' => 190, 'p' => 7499]],
    ],
    [
        'cat' => 'ayurveda',
        'title' => 'Snehanam Therapy',
        'desc' => 'Primary benefits of four hand massage are to give a heightened stage of relaxation & release stress throughout the body.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/elementor/thumbs/Four-Hand-Massage-q8za1216vxds3uo0adusz4aghe2t4pvt06b7edpiwg.jpg',
        'benefits' => 'Four-hand synchrony; Elevated relaxation; Full-body stress release',
        'featured' => 1, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 120, 'p' => 10199], ['d' => 180, 'p' => 17599]],
    ],
    [
        'cat' => 'couple',
        'title' => 'Couple Massage',
        'desc' => 'Two souls, one serene journey featuring synchronized therapies that melt away tension, creating a deeply relaxing escape for two, elevated with an exclusive gift to enhance your shared experience.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2026/05/spa-in-andheri-west-collage.webp',
        'benefits' => 'Side-by-side serenity; Shared escape for two; Exclusive gift included',
        'featured' => 1, 'bestseller' => 1, 'deal' => 1,
        'prices' => [['d' => 60, 'p' => 7999], ['d' => 90, 'p' => 8999], ['d' => 120, 'p' => 9999]],
    ],
    [
        'cat' => 'holistic',
        'title' => 'Tranquility Essential Oil Therapy',
        'desc' => 'Recommended for anxiety and stress-related concerns. Essential oils: Lemongrass and Lavender. Soothing blend known for natural anxiety-reducing properties — promotes contentment and restores deep well-being.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2024/02/essential-oils-oil-fragrance-2918183-768x511.jpg',
        'benefits' => 'Anxiety-soothing blend; Lavender + lemongrass; Emotional ease',
        'featured' => 1, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 90, 'p' => 6000]],
    ],
    [
        'cat' => 'body',
        'title' => 'Body Wrap + Scrub',
        'desc' => 'DETOX | HYDRATION | NOURISH — Detoxifying treatment that draws out impurities while improving skin tone and firmness. Deeply hydrates and nourishes the skin, leaving it soft, smooth and revitalized.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2023/08/WhatsApp-Image-2023-08-23-at-2.04.55-PM-1-1200x500.jpeg',
        'benefits' => 'Detox wrap; Firms & hydrates; Soft revitalized skin',
        'featured' => 0, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 45, 'p' => 4000]],
    ],
    [
        'cat' => 'body',
        'title' => 'Full Back Polishing',
        'desc' => 'GLOW | EXFOLIATION | SMOOTH SKIN — Revitalizing treatment that exfoliates and refines the skin, helping to lighten tan and improve texture — leaving your back soft, smooth, and radiant.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2023/07/masseur-does-back-massage-young-guy-beauty-salon-300x211.jpg',
        'benefits' => 'Back radiance; Tan-lightening polish; Silky smooth finish',
        'featured' => 0, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 45, 'p' => 4000]],
    ],
    [
        'cat' => 'foot',
        'title' => 'Foot Reflexology',
        'desc' => 'Therapeutic pressure-point foot massage designed to relax the body, improve circulation, and restore natural balance.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/elementor/thumbs/Foot-Reflexology-q8za1zvdpyo3ptausshdgvr1v9g0ttm54tsoocbcog.jpg',
        'benefits' => 'Pressure-point balance; Circulation support; Whole-body calm',
        'featured' => 0, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 30, 'p' => 1200]],
    ],
    [
        'cat' => 'foot',
        'title' => 'Foot Massage',
        'desc' => 'Deep relaxation for your tired feet.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2026/07/spa-06.png',
        'benefits' => 'Deep foot ease; Travel recovery; Quick luxury pause',
        'featured' => 0, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 30, 'p' => 1200], ['d' => 60, 'p' => 2400]],
    ],
    [
        'cat' => 'mind',
        'title' => 'Head, Neck & Back Massage',
        'desc' => 'Targeted therapeutic massage to release tension in the head, neck, and back areas.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2023/07/masseur-does-back-massage-young-guy-beauty-salon-300x300.jpg',
        'benefits' => 'Desk-day reset; Neck tension relief; Upper-body ease',
        'featured' => 1, 'bestseller' => 0, 'deal' => 1,
        'prices' => [['d' => 30, 'p' => 1500], ['d' => 45, 'p' => 2000]],
    ],
    [
        'cat' => 'mind',
        'title' => 'Traditional Indian Head Massage',
        'desc' => 'Traditional Indian scalp massage designed to relieve mental stress and promote circulation.',
        'image' => 'https://allurethaispa.in/wp-content/uploads/2024/01/Untitled-design-1-1-300x300.png',
        'benefits' => 'Mental stress relief; Scalp circulation; Express calm',
        'featured' => 0, 'bestseller' => 0, 'deal' => 0,
        'prices' => [['d' => 15, 'p' => 600], ['d' => 30, 'p' => 1200]],
    ],
];

$order = 1;
$count = 0;

foreach ($services as $svc) {
    foreach ($svc['prices'] as $row) {
        $mins = (int) $row['d'];
        $price = (float) $row['p'];
        $label = $row['label'] ?? ($mins . ' Min');
        $name = $mins > 0
            ? $svc['title'] . ' — ' . $mins . ' Min'
            : $svc['title'] . ' — Add-on';
        $slug = slugify($svc['title'] . '-' . ($mins > 0 ? $mins . '-min' : 'addon'));

        // Menu price as offer; light strike price for deals UX (~15%)
        $original = $svc['deal']
            ? (float) (ceil(($price * 1.15) / 100) * 100)
            : $price;
        if ($original <= $price) {
            $original = $price;
        }
        $discount = discount_percent($original, $price);

        upsertProduct($db, [
            'category_id' => $cats[$svc['cat']],
            'name' => $name,
            'slug' => $slug,
            'short' => mb_substr($svc['desc'], 0, 220),
            'long' => $svc['desc'],
            'benefits' => $svc['benefits'],
            'duration' => $mins,
            'original' => $original,
            'offer' => $price,
            'discount' => $discount,
            'image' => $svc['image'],
            'featured' => (int) $svc['featured'],
            'bestseller' => (int) $svc['bestseller'],
            'deal' => (int) $svc['deal'],
            'order' => $order++,
            'seo_title' => $name . ' | Allure Thai Spa Deals',
        ]);
        $count++;
        echo "OK: {$name} — ₹{$price}\n";
    }
}

$total = (int) $db->query(
    'SELECT COUNT(*) FROM alluredeal_product WHERE is_active = 1 AND is_deleted = 0'
)->fetchColumn();

echo "\nImported/updated duration SKUs: {$count}\n";
echo "Active products now: {$total}\n";
echo "Source: https://allurethaispa.in/spa-in-thane-west-menu-card.html\n";

// Apply Today's Deals (duration >= 60 @ 22%, no expiry)
echo "\nApplying Today's Deals...\n";
require __DIR__ . '/setup_60min_deals.php';
