<?php
/**
 * Public Club Store — Create Order
 * The cart (a localStorage list of {productId, variantId, qty} — never
 * prices) is sent here as-is. Every line is re-priced and re-validated
 * server-side via store_price_cart() — the same function the cart page
 * itself calls to display live totals — so nothing about what PayPal
 * actually charges ever depends on client-supplied numbers. Order + line
 * items are snapshotted (name/variant label/price at this moment) in one
 * transaction so later catalog edits can never retroactively change a
 * historical order's record.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/lib/paypal.php';
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
if (!$payload) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request data.']);
    exit();
}

if (honeypot_tripped($payload, 'website')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'store_create_order')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email treasurer@alabamafalcons.org.']);
    exit();
}

$customer_name  = trim((string)($payload['customerName']  ?? ''));
$customer_email = trim((string)($payload['customerEmail'] ?? ''));
$customer_phone = trim((string)($payload['customerPhone'] ?? ''));
$items          = is_array($payload['items'] ?? null) ? $payload['items'] : [];

$fulfillment_method = ($payload['fulfillmentMethod'] ?? 'pickup') === 'ship' ? 'ship' : 'pickup';
$ship_address = null;
if ($fulfillment_method === 'ship') {
    $ship_name    = trim((string)($payload['shippingName']    ?? $customer_name));
    $ship_address1 = trim((string)($payload['shippingAddress1'] ?? ''));
    $ship_address2 = trim((string)($payload['shippingAddress2'] ?? ''));
    $ship_city    = trim((string)($payload['shippingCity']    ?? ''));
    $ship_state   = trim((string)($payload['shippingState']   ?? ''));
    $ship_zip     = trim((string)($payload['shippingZip']     ?? ''));
    $ship_country = trim((string)($payload['shippingCountry'] ?? 'US')) ?: 'US';
    if ($ship_address1 === '' || $ship_city === '' || $ship_state === '' || $ship_zip === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please enter a complete shipping address.']);
        exit();
    }
    $ship_address = [
        'name' => mb_substr($ship_name, 0, 200), 'address1' => mb_substr($ship_address1, 0, 200),
        'address2' => mb_substr($ship_address2, 0, 200), 'city' => mb_substr($ship_city, 0, 120),
        'state' => mb_substr($ship_state, 0, 60), 'zip' => mb_substr($ship_zip, 0, 20), 'country' => mb_substr($ship_country, 0, 2),
    ];
}

if ($customer_name === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter your name.']);
    exit();
}
if (!filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit();
}
$customer_name  = mb_substr($customer_name, 0, 200);
$customer_phone = mb_substr($customer_phone, 0, 40);

$priced = store_price_cart($pdo, $items);
if ($priced['hasInvalid']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Some items in your cart changed — please review your cart and try again.', 'lines' => $priced['lines']]);
    exit();
}
if (empty($priced['lines']) || $priced['subtotal'] <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Your cart is empty.']);
    exit();
}

// Shipping is never trusted from the client — the flat rate is looked up
// fresh here, same discipline as store_price_cart() for item prices.
$shipping_amount = $fulfillment_method === 'ship' ? store_shipping_flat_rate($pdo) : 0.0;
$total = round($priced['subtotal'] + $shipping_amount, 2);

// Sanity ceiling — a real order this large from the public store would be
// unusual for club merch; catches a malformed payload, not a legitimate
// large order (same reasoning as donate-create-order.php's $25,000 cap).
if ($total > 5000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'This order is too large for online checkout. Please email treasurer@alabamafalcons.org.']);
    exit();
}

$paypal_items = array_map(function ($line) {
    return [
        'name'        => $line['name'] . ($line['variantLabel'] ? ' - ' . $line['variantLabel'] : ''),
        'quantity'    => $line['qty'],
        'unit_amount' => $line['unitPrice'],
    ];
}, $priced['lines']);

$reference_id = 'store-order-' . bin2hex(random_bytes(6));
$request_id   = 'create-' . bin2hex(random_bytes(16));

$order = paypal_create_order($total, $reference_id, $request_id, 'Club Store order', null, $paypal_items, $shipping_amount, $ship_address);
if (!$order['success']) {
    error_log('store-create-order: ' . $order['error']);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'We could not start the PayPal checkout. Please try again in a moment.']);
    exit();
}

try {
    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO store_orders (customer_name, customer_email, customer_phone, status, paypal_order_id, subtotal, total, fulfillment_method, shipping_amount, shipping_name, shipping_address1, shipping_address2, shipping_city, shipping_state, shipping_zip, shipping_country)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $customer_name, $customer_email, $customer_phone ?: null, 'created', $order['order_id'], $priced['subtotal'], $total,
        $fulfillment_method, $shipping_amount,
        $ship_address['name'] ?? null, $ship_address['address1'] ?? null, $ship_address['address2'] ?? null,
        $ship_address['city'] ?? null, $ship_address['state'] ?? null, $ship_address['zip'] ?? null,
        $ship_address['country'] ?? 'US',
    ]);
    $order_row_id = (int)$pdo->lastInsertId();

    $item_stmt = $pdo->prepare(
        'INSERT INTO store_order_items (order_id, product_id, variant_id, product_name_snapshot, variant_label_snapshot, unit_price, unit_cost, quantity, line_total)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($priced['lines'] as $line) {
        $item_stmt->execute([
            $order_row_id,
            $line['productId'],
            $line['variantId'],
            $line['name'],
            $line['variantLabel'] ?: null,
            $line['unitPrice'],
            store_unit_cost($pdo, $line['productId'], $line['variantId']),
            $line['qty'],
            $line['lineTotal'],
        ]);
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    error_log('store-create-order: order/items insert failed for PayPal order ' . $order['order_id'] . ': ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'We could not record your order. Please try again or email treasurer@alabamafalcons.org.']);
    exit();
}

echo json_encode(['success' => true, 'orderId' => $order['order_id']]);
