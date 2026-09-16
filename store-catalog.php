<?php
/**
 * Public Club Store Catalog
 * Read-only, no side effects — lists active products with their active
 * variants and photos for store.html's JS to render the browse grid.
 * Pricing here is for display only; store-cart-price.php and
 * store-create-order.php independently recompute authoritative pricing
 * server-side at cart/checkout time, so a stale cached response here can
 * never actually overcharge or undercharge anyone.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

$pdo = get_pdo();

$products = $pdo->query("SELECT id, name, description, category, base_price, shipping_cost, sale_ends_at FROM store_products WHERE is_active = 1 AND (sale_ends_at IS NULL OR sale_ends_at >= CURDATE()) ORDER BY category ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

$photo_stmt  = $pdo->prepare('SELECT filename FROM store_product_photos WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC');
$variant_stmt = $pdo->prepare('SELECT id, size, color, sku, price_override, is_active FROM store_product_variants WHERE product_id = ? AND is_active = 1 ORDER BY id ASC');

$out = [];
foreach ($products as $p) {
    $photo_stmt->execute([$p['id']]);
    $variant_stmt->execute([$p['id']]);
    $variants = array_map(function ($v) use ($p) {
        return [
            'id'    => (int)$v['id'],
            'size'  => $v['size'],
            'color' => $v['color'],
            'price' => round((float)($v['price_override'] ?? $p['base_price']), 2),
        ];
    }, $variant_stmt->fetchAll(PDO::FETCH_ASSOC));

    $out[] = [
        'id'          => (int)$p['id'],
        'name'        => $p['name'],
        'description' => $p['description'],
        'category'    => $p['category'],
        'basePrice'   => round((float)$p['base_price'], 2),
        'shippingCost' => round((float)$p['shipping_cost'], 2),
        'saleEndsAt'  => $p['sale_ends_at'],
        'photos'      => $photo_stmt->fetchAll(PDO::FETCH_COLUMN),
        'variants'    => $variants,
    ];
}

echo json_encode(['success' => true, 'products' => $out]);
