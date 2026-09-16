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

// Sums each valid line's own per-unit shipping_cost * qty — the actual
// shipping charge when the customer picks "Ship" at checkout. Deliberately
// a straight sum, not a single package/box guess: a 3-shirt order really
// does cost more to ship than 1. Takes store_price_cart()'s output rather
// than re-querying, so this and the pricing it's built from can never
// drift out of sync — call it right after store_price_cart() wherever a
// shipping total is needed (store-cart-price.php for display,
// store-create-order.php for the actual charge).
function store_cart_shipping_total(array $lines): float {
    $total = 0.0;
    foreach ($lines as $line) {
        if (!$line['valid']) continue;
        $total += (float)($line['shippingCost'] ?? 0) * (int)$line['qty'];
    }
    return round($total, 2);
}

// Admin-only bookkeeping figure — what this specific line actually cost the
// club to produce, same override-falls-back-to-base pattern as price
// (variant unit_cost_override, else product unit_cost). Deliberately kept
// out of store_price_cart()'s return value: that function's output flows
// straight into public, unauthenticated JSON responses (store-cart-price.php,
// store-catalog.php), and cost-to-make/margin is exactly the kind of
// internal figure that must never leak into a page's Network tab. Called
// instead only from store-create-order.php (to snapshot onto
// store_order_items, server-side) and the admin order/product pages.
// Returns null when no cost has been entered — margin reporting then shows
// "—" rather than a misleading $0 margin.
function store_unit_cost(PDO $pdo, int $productId, ?int $variantId): ?float {
    if ($variantId) {
        $stmt = $pdo->prepare('SELECT unit_cost_override FROM store_product_variants WHERE id = ? AND product_id = ?');
        $stmt->execute([$variantId, $productId]);
        $override = $stmt->fetchColumn();
        if ($override !== false && $override !== null && $override !== '') return round((float)$override, 2);
    }
    $stmt = $pdo->prepare('SELECT unit_cost FROM store_products WHERE id = ?');
    $stmt->execute([$productId]);
    $cost = $stmt->fetchColumn();
    return ($cost !== false && $cost !== null && $cost !== '') ? round((float)$cost, 2) : null;
}

// $items: array of ['productId'=>int, 'variantId'=>?int, 'qty'=>int].
// Returns ['lines'=>[...], 'subtotal'=>float, 'total'=>float, 'hasInvalid'=>bool].
// Each line: productId, variantId, name, variantLabel, unitPrice,
// shippingCost (per unit — see store_cart_shipping_total() below to sum
// across the cart), qty, lineTotal, valid (bool), reason (string, only set
// when !valid).
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
            'shippingCost' => 0.0,
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
        // Re-checked here (not just filtered out of store-catalog.php's
        // listing) so an item already sitting in someone's cart from before
        // the deadline can't still be checked out afterward — the same
        // "never trust what the client already has" discipline as price.
        if (!empty($product['sale_ends_at']) && $product['sale_ends_at'] < date('Y-m-d')) {
            $line['reason'] = 'This item\'s sale has closed.';
            $lines[] = $line; $hasInvalid = true; continue;
        }
        $line['name'] = $product['name'];
        $line['shippingCost'] = round((float)$product['shipping_cost'], 2);

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
        'total'      => round($subtotal, 2), // merchandise only — shipping (pickup is free) is added on top of this at checkout, see store-create-order.php
        'hasInvalid' => $hasInvalid,
    ];
}
