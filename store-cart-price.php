<?php
/**
 * Public Cart Pricing
 * Read-only, no side effects — the cart page calls this every time it
 * renders so a shopper always sees current, server-verified prices and
 * availability instead of numbers remembered in localStorage from whenever
 * each item was added. See store_price_cart() in admin/lib/store.php for
 * the actual pricing logic — this is a thin JSON wrapper around it, shared
 * verbatim with store-create-order.php so there's exactly one place that
 * "looks up the price" can ever be wrong.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/lib/store.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

$pdo = get_pdo();
$priced = store_price_cart($pdo, $items);
$priced['shippingIfShipped'] = store_cart_shipping_total($priced['lines']);

echo json_encode(array_merge(['success' => true], $priced));
