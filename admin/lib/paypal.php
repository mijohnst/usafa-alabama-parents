<?php
/**
 * PayPal API wrapper — minimal, plain cURL (no vendored SDK). Covers both
 * Payouts (sending reimbursements) and Orders/Checkout (collecting online
 * dues payments). Requires PAYPAL_MODE ('sandbox'|'live'), PAYPAL_CLIENT_ID,
 * PAYPAL_SECRET defined in admin/config.php. Sandbox and live use different
 * API hosts and completely separate credentials/money.
 */

// Orders v2 responses carry a top-level payment_source object keyed by
// whichever funding instrument the payer actually used (paypal/venmo/
// card/apple_pay/google_pay) — distinct from and not derivable off the
// purchase_units/captures block. This is the only place that "PayPal vs
// Venmo vs Apple Pay" is knowable per-transaction; callers persist the
// label so the Finance Report can break income down by real payment
// source instead of every online payment reading as generic "PayPal".
function paypal_funding_source_label(array $data): string {
    $labels = ['paypal' => 'PayPal', 'venmo' => 'Venmo', 'apple_pay' => 'Apple Pay', 'google_pay' => 'Google Pay', 'card' => 'Card'];
    foreach ($data['payment_source'] ?? [] as $key => $val) {
        if (isset($labels[$key])) return $labels[$key];
    }
    return 'PayPal';
}

function paypal_api_base(): string {
    return (defined('PAYPAL_MODE') && PAYPAL_MODE === 'live')
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

// OAuth2 client_credentials grant — short-lived token, fetched fresh for
// every call rather than cached, to keep this wrapper simple; Payouts is a
// low-volume admin action, not a hot path worth optimizing.
// Returns ['token'=>string,'error'=>null] or ['token'=>null,'error'=>string] —
// the specific reason is surfaced all the way to the on-screen flash message
// rather than only to the PHP error log, since that log isn't reliably
// reachable on every hosting setup.
function paypal_get_access_token(): array {
    if (!defined('PAYPAL_CLIENT_ID') || !defined('PAYPAL_SECRET')) {
        return ['token' => null, 'error' => 'PAYPAL_CLIENT_ID/PAYPAL_SECRET are not defined in admin/config.php.'];
    }
    if (!function_exists('curl_init')) {
        return ['token' => null, 'error' => 'The PHP curl extension is not available on this server.'];
    }
    $ch = curl_init(paypal_api_base() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['token' => null, 'error' => 'Connection to PayPal failed: ' . ($curl_err ?: 'unknown cURL error')];
    }
    $data = json_decode($resp, true);
    if ($code !== 200 || empty($data['access_token'])) {
        $reason = $data['error_description'] ?? $data['error'] ?? $resp;
        return ['token' => null, 'error' => "PayPal auth rejected (HTTP $code, mode " . paypal_mode_label() . "): $reason"];
    }
    return ['token' => $data['access_token'], 'error' => null];
}

function paypal_mode_label(): string {
    return (defined('PAYPAL_MODE') && PAYPAL_MODE === 'live') ? 'live' : 'sandbox';
}

// Sends a single payout to one PayPal email address. Returns
// ['success'=>true,'batch_id'=>...,'status'=>...] or ['success'=>false,'error'=>...].
function paypal_send_payout(string $recipientEmail, float $amount, string $note, string $senderItemId): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $payload = [
        'sender_batch_header' => [
            // Deliberately deterministic (purchase id, not timestamped): if
            // a request crashes/times out after PayPal has already accepted
            // it but before our own DB write records the batch id, a retry
            // reuses this exact id — PayPal recognizes it as a duplicate
            // batch and returns the existing one instead of paying out a
            // second time. See the claim/retry logic around this call in
            // admin/purchase-action.php's send_paypal action.
            'sender_batch_id' => $senderItemId,
            'email_subject'   => 'You have a payout from ' . (defined('CLUB_NAME') ? CLUB_NAME : 'USAFA Parents Club of Alabama'),
            'email_message'   => $note,
        ],
        'items' => [[
            'recipient_type' => 'EMAIL',
            'amount'         => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'USD'],
            'receiver'       => $recipientEmail,
            'note'           => $note,
            'sender_item_id' => $senderItemId,
        ]],
    ];

    $ch = curl_init(paypal_api_base() . '/v1/payments/payouts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if ($code >= 200 && $code < 300 && !empty($data['batch_header']['payout_batch_id'])) {
        return [
            'success'  => true,
            'batch_id' => $data['batch_header']['payout_batch_id'],
            'status'   => $data['batch_header']['batch_status'] ?? 'PENDING',
        ];
    }
    error_log('paypal_send_payout failed: HTTP ' . $code . ' ' . $resp);
    // PayPal rejects a reused sender_batch_id outright rather than quietly
    // no-op'ing — which, given our batch id is deterministic per purchase,
    // means it has *already* accepted this exact payout once before (most
    // likely from an earlier attempt whose success response we never got to
    // record). Surfaced distinctly so the caller does not treat this the
    // same as an ordinary, safe-to-retry failure.
    $is_duplicate = ($data['name'] ?? '') === 'DUPLICATE_BATCH_ID';
    return ['success' => false, 'duplicate' => $is_duplicate, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}

// Looks up the current status of a previously-sent payout batch.
function paypal_check_payout_status(string $batchId): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $ch = curl_init(paypal_api_base() . '/v1/payments/payouts/' . urlencode($batchId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if ($code >= 200 && $code < 300) {
        // Prefer the individual item's status (SUCCESS/FAILED/UNCLAIMED/etc.)
        // over the batch-level status, since a batch of one item is "done"
        // as soon as PayPal has processed it either way.
        $item_status  = $data['items'][0]['transaction_status'] ?? null;
        $batch_status = $data['batch_header']['batch_status'] ?? 'UNKNOWN';
        return ['success' => true, 'status' => $item_status ?: $batch_status];
    }
    error_log('paypal_check_payout_status failed: HTTP ' . $code . ' ' . $resp);
    return ['success' => false, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}

// Passive reconciliation for admin/purchases.php: rather than requiring a
// treasurer to click a manual refresh button, re-checks any purchase whose
// payout is still in an in-flight PayPal state every time the Finance page
// loads, updating both the DB and the in-memory $purchases array (by
// reference) so this same page render reflects the fresh status without a
// second query. Capped at $limit checks per page load — each one is a full
// OAuth-token-plus-status-lookup round trip to PayPal, so an unbounded loop
// here would make Finance noticeably slower to load whenever several
// payouts are in flight at once. Terminal states (SUCCESS, and the
// FAILED/DENIED/etc. family) are left alone — they need a human to look at
// them, not repeated polling.
//
// Deliberately does not call flash() the way the manual check_paypal_status
// action does: flash() is single-slot ($_SESSION['flash'] holds only the
// latest message), so looping it here across multiple confirmations would
// silently drop all but the last one. The status badge updating in place is
// the feedback for this passive path; notify_paid() below still emails the
// submitter same as the manual path does.
function paypal_refresh_pending_purchase_payouts(PDO $pdo, array &$purchases, int $limit = 8): void {
    $in_flight = ['PENDING', 'SENDING', 'UNCLAIMED'];
    $checked = 0;
    foreach ($purchases as &$p) {
        if ($checked >= $limit) break;
        if (empty($p['paypal_payout_batch_id'])) continue;
        if (!in_array($p['paypal_payout_status'] ?? '', $in_flight, true)) continue;
        $checked++;

        $result = paypal_check_payout_status($p['paypal_payout_batch_id']);
        if (!$result['success']) continue;

        $pdo->prepare('UPDATE purchases SET paypal_payout_status = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$result['status'], $p['id']]);
        $p['paypal_payout_status'] = $result['status'];

        if ($result['status'] === 'SUCCESS' && $p['status'] === 'submitted') {
            $note = 'Automatically marked paid — PayPal payout confirmed SUCCESS.';
            $pdo->prepare('UPDATE purchases SET status = ?, paid_note = ?, paid_at = NOW(), updated_at = NOW() WHERE id = ?')
                ->execute(['paid', $note, $p['id']]);
            $p['status']    = 'paid';
            $p['paid_note'] = $note;
            notify_paid($pdo, $p, 'PayPal (automatic)');
        }
    }
    unset($p);
}

// ── Orders API — collects a payment (the reverse of Payouts, above,
// which sends one) ─────────────────────────────────────────────────────

// Creates a PayPal order for a single USD amount. $requestId is sent as
// the PayPal-Request-Id idempotency header — a retry with the same id
// (e.g. a double-click before the first request finishes) returns the
// same still-open order instead of creating a second one. Returns
// ['success'=>true,'order_id'=>...,'status'=>...] or
// ['success'=>false,'error'=>...].
// $description/$customId are optional — when set (e.g. a named campaign
// like the Saber Fund), they land on the order's purchase_unit exactly as
// PayPal defines them: `description` is the free-text line shown in
// PayPal's own transaction details and the buyer's receipt email;
// `custom_id` is a merchant-defined tag (not shown to the buyer) that
// appears in the seller's PayPal Activity/transaction detail view and in
// PayPal's transaction-history CSV exports, so it can be filtered/searched
// there independent of this site's own admin panel. Both are capped at
// PayPal's own 127-character limit for these fields. General donations
// (no campaign) leave both null, so the order looks exactly as it did
// before this parameter existed.
//
// $items is optional — the Club Store's only caller. Each entry:
// ['name'=>string, 'quantity'=>int, 'unit_amount'=>float]. PayPal requires
// amount.breakdown.item_total to equal the sum of (unit_amount*quantity)
// across all items, AND requires the top-level amount.value to equal that
// same breakdown total (no tax/shipping/discount here) — a mismatch of
// even a cent gets the whole order creation rejected with a 422. The
// caller is responsible for computing $amount from the exact same
// item/quantity data passed here (see store_price_cart() in
// admin/lib/store.php), not as an independently-rounded number.
function paypal_create_order(float $amount, string $referenceId, string $requestId, ?string $description = null, ?string $customId = null, ?array $items = null): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $amount_str = number_format($amount, 2, '.', '');
    $purchase_unit = [
        'reference_id' => $referenceId,
        'amount' => ['currency_code' => 'USD', 'value' => $amount_str],
    ];
    if ($description !== null) $purchase_unit['description'] = mb_substr($description, 0, 127);
    if ($customId !== null)    $purchase_unit['custom_id']   = mb_substr($customId, 0, 127);

    if ($items !== null) {
        $purchase_unit['items'] = array_map(function ($item) {
            return [
                'name'        => mb_substr((string)$item['name'], 0, 127),
                'quantity'    => (string)(int)$item['quantity'],
                'unit_amount' => ['currency_code' => 'USD', 'value' => number_format((float)$item['unit_amount'], 2, '.', '')],
            ];
        }, $items);
        // Must equal amount.value exactly (no tax/shipping/discount) —
        // recomputed here from the same rounded unit_amount strings just
        // built above, rather than trusting the caller's $amount to
        // already agree with them to the cent.
        $item_total = 0.0;
        foreach ($purchase_unit['items'] as $it) {
            $item_total += (float)$it['unit_amount']['value'] * (int)$it['quantity'];
        }
        $purchase_unit['amount']['value'] = number_format($item_total, 2, '.', '');
        $purchase_unit['amount']['breakdown'] = [
            'item_total' => ['currency_code' => 'USD', 'value' => number_format($item_total, 2, '.', '')],
        ];
    }

    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [$purchase_unit],
    ];

    $ch = curl_init(paypal_api_base() . '/v2/checkout/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'PayPal-Request-Id: ' . $requestId],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if ($code >= 200 && $code < 300 && !empty($data['id'])) {
        return ['success' => true, 'order_id' => $data['id'], 'status' => $data['status'] ?? 'CREATED'];
    }
    error_log('paypal_create_order failed: HTTP ' . $code . ' ' . $resp);
    return ['success' => false, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}

// Captures a previously-created order — the call that actually moves the
// payer's money. $requestId should be deterministic per order (callers
// use 'capture-' . $orderId) so a network retry after a crash/timeout
// reuses it and lands on PayPal's own duplicate-capture rejection rather
// than attempting to charge the payer a second time — the collection-side
// mirror of paypal_send_payout()'s DUPLICATE_BATCH_ID handling above.
// 'already_captured' distinguishes that specific, expected-on-retry
// rejection from an ordinary failure the caller should just report as-is.
function paypal_capture_order(string $orderId, string $requestId): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $ch = curl_init(paypal_api_base() . '/v2/checkout/orders/' . urlencode($orderId) . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'PayPal-Request-Id: ' . $requestId],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
    if ($code >= 200 && $code < 300 && $capture && ($capture['status'] ?? '') === 'COMPLETED') {
        return [
            'success'         => true,
            'status'          => $capture['status'],
            'capture_id'      => $capture['id'],
            'captured_amount' => (float)($capture['amount']['value'] ?? 0),
            'funding_source'  => paypal_funding_source_label($data),
        ];
    }
    error_log('paypal_capture_order failed: HTTP ' . $code . ' ' . $resp);
    $is_duplicate = $code === 422 && (($data['details'][0]['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED');
    return ['success' => false, 'already_captured' => $is_duplicate, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}

// Recovery path only: looks up an order's current state directly. Used
// when paypal_capture_order() reports 'already_captured' but our own
// tracking row doesn't show it as applied yet (a crash between PayPal
// confirming the capture and our own bookkeeping finishing) — recovers
// the real capture id/amount so the flow can still finish instead of
// stranding an already-paid order in limbo.
function paypal_get_order(string $orderId): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $ch = curl_init(paypal_api_base() . '/v2/checkout/orders/' . urlencode($orderId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
    // Must require COMPLETED here, same as paypal_capture_order() above —
    // a capture object existing at all doesn't mean it succeeded. PayPal
    // uses this same resource for DECLINED/PENDING/FAILED captures too, and
    // this function exists specifically to recover the real outcome after
    // a crash/retry, so treating any non-empty capture as success would
    // let a declined or still-pending payment get reported to the browser
    // (and recorded in the ledger) as a completed one.
    if ($code >= 200 && $code < 300 && $capture && ($capture['status'] ?? '') === 'COMPLETED') {
        return [
            'success'         => true,
            'status'          => $capture['status'],
            'capture_id'      => $capture['id'] ?? null,
            'captured_amount' => (float)($capture['amount']['value'] ?? 0),
            'funding_source'  => paypal_funding_source_label($data),
        ];
    }
    $actual_status = $capture['status'] ?? null;
    error_log('paypal_get_order failed: HTTP ' . $code . ' status=' . ($actual_status ?? 'none') . ' ' . $resp);
    return [
        'success' => false,
        'error'   => $actual_status ? "PayPal capture status is \"$actual_status\", not completed." : ($data['message'] ?? ('PayPal returned HTTP ' . $code)),
    ];
}
