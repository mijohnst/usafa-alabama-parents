<?php
/**
 * Club Store — shared cart pricing/validation.
 * The one place "what does this cart actually cost, right now" gets
 * computed — called from both store-cart-price.php (so the cart page
 * always shows live server-truth, not stale localStorage-remembered
 * numbers) and store-create-order.php (the authoritative total an order is
 * actually created for). A cart in localStorage holds only
 * {productId, variantId, qty} tuples, never prices — this function is what
 * turns those ids into real, current, server-verified prices, matching
 * this codebase's existing discipline of never trusting a client-supplied
 * money value (e.g. dues_years_price() is always recomputed server-side).
 */

const STORE_MAX_LINE_QTY   = 50;
const STORE_MAX_CART_LINES = 30;

// $items: array of ['productId'=>int, 'variantId'=>?int, 'qty'=>int].
// Returns ['lines'=>[...], 'subtotal'=>float, 'total'=>float, 'hasInvalid'=>bool].
// Each line: productId, variantId, name, variantLabel, unitPrice, qty,
// lineTotal, valid (bool), reason (string, only set when !valid).
// Invalid lines (deleted/hidden product, missing required variant, bad
// qty) are still returned — with valid=false and a human reason — rather
// than silently dropped, so the cart page can show the shopper exactly
// what changed instead of a total that mysteriously shrank.
function store_price_cart(PDO $pdo, array $items): array {
    $lines = [];
    $subtotal = 0.0;
    $hasInvalid = false;

    $product_stmt = $pdo->prepare('SELECT * FROM store_products WHERE id = ?');
    $variant_stmt = $pdo->prepare('SELECT * FROM store_product_variants WHERE id = ? AND product_id = ?');
    $active_variant_count_stmt = $pdo->prepare('SELECT COUNT(*) FROM store_product_variants WHERE product_id = ? AND is_active = 1');

    $count = 0;
    foreach ($items as $item) {
        if ($count >= STORE_MAX_CART_LINES) break;
        $count++;

        $product_id = (int)($item['productId'] ?? 0);
        $variant_id = isset($item['variantId']) && $item['variantId'] !== null ? (int)$item['variantId'] : null;
        $qty        = (int)($item['qty'] ?? 0);

        $line = [
            'productId'    => $product_id,
            'variantId'    => $variant_id,
            'name'         => null,
            'variantLabel' => null,
            'unitPrice'    => 0.0,
            'qty'          => $qty,
            'lineTotal'    => 0.0,
            'valid'        => false,
            'reason'       => '',
        ];

        if ($qty < 1 || $qty > STORE_MAX_LINE_QTY) {
            $line['reason'] = 'Invalid quantity.';
            $lines[] = $line; $hasInvalid = true; continue;
        }

        $product_stmt->execute([$product_id]);
        $product = $product_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product || !$product['is_active']) {
            $line['reason'] = 'No longer available.';
            $lines[] = $line; $hasInvalid = true; continue;
        }
        $line['name'] = $product['name'];

        $active_variant_count_stmt->execute([$product_id]);
        $requires_variant = (int)$active_variant_count_stmt->fetchColumn() > 0;

        $unit_price = (float)$product['base_price'];
        if ($requires_variant) {
            if (!$variant_id) {
                $line['reason'] = 'Please choose a size/color.';
                $lines[] = $line; $hasInvalid = true; continue;
            }
            $variant_stmt->execute([$variant_id, $product_id]);
            $variant = $variant_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$variant || !$variant['is_active']) {
                $line['reason'] = 'That size/color is no longer available.';
                $lines[] = $line; $hasInvalid = true; continue;
            }
            $unit_price = round((float)($variant['price_override'] ?? $product['base_price']), 2);
            $line['variantLabel'] = trim(implode(' / ', array_filter([$variant['size'], $variant['color']], fn($v) => $v !== null && $v !== '')));
        }

        $line['unitPrice'] = round($unit_price, 2);
        $line['lineTotal'] = round($unit_price * $qty, 2);
        $line['valid']     = true;
        $subtotal += $line['lineTotal'];
        $lines[] = $line;
    }

    return [
        'lines'      => $lines,
        'subtotal'   => round($subtotal, 2),
        'total'      => round($subtotal, 2), // no tax/shipping/discount — pickup-only club merch
        'hasInvalid' => $hasInvalid,
    ];
}
