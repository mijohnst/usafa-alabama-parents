<?php
/**
 * PayPal Payouts API — minimal wrapper, plain cURL (no vendored SDK).
 * Requires PAYPAL_MODE ('sandbox'|'live'), PAYPAL_CLIENT_ID, PAYPAL_SECRET
 * defined in admin/config.php. Sandbox and live use different API hosts
 * and completely separate credentials/money.
 */

function paypal_api_base(): string {
    return (defined('PAYPAL_MODE') && PAYPAL_MODE === 'live')
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

// OAuth2 client_credentials grant — short-lived token, fetched fresh for
// every call rather than cached, to keep this wrapper simple; Payouts is a
// low-volume admin action, not a hot path worth optimizing.
function paypal_get_access_token(): ?string {
    if (!defined('PAYPAL_CLIENT_ID') || !defined('PAYPAL_SECRET')) {
        error_log('paypal_get_access_token: PAYPAL_CLIENT_ID/PAYPAL_SECRET not configured');
        return null;
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
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code !== 200) {
        error_log('paypal_get_access_token failed: HTTP ' . $code . ' ' . $err . ' ' . $resp);
        return null;
    }
    $data = json_decode($resp, true);
    return $data['access_token'] ?? null;
}

// Sends a single payout to one PayPal email address. Returns
// ['success'=>true,'batch_id'=>...,'status'=>...] or ['success'=>false,'error'=>...].
function paypal_send_payout(string $recipientEmail, float $amount, string $note, string $senderItemId): array {
    $token = paypal_get_access_token();
    if (!$token) return ['success' => false, 'error' => 'Could not authenticate with PayPal — check PAYPAL_CLIENT_ID/PAYPAL_SECRET in admin/config.php.'];

    $payload = [
        'sender_batch_header' => [
            // Must be unique per batch or PayPal rejects it as a duplicate —
            // timestamped so re-sending after a failure gets a fresh id.
            'sender_batch_id' => $senderItemId . '-' . time(),
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
    return ['success' => false, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}

// Looks up the current status of a previously-sent payout batch.
function paypal_check_payout_status(string $batchId): array {
    $token = paypal_get_access_token();
    if (!$token) return ['success' => false, 'error' => 'Could not authenticate with PayPal.'];

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
