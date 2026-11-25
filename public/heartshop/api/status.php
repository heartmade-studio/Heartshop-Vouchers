<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Thank-you page polls this. Fulfillment always requires a signed completion.
try {
    $id = $_GET['o'] ?? null;
    $order = validOrderId($id) ? findOrder($id) : null;
    if (!$order) jsonOut(['status' => 'unknown'], 404);
    if (in_array($order['status'],['pending','canceled'],true)) {
        try { checkSignedCompletion($id,$statusLookup??null,forDisplay:true); }
        catch (Throwable $e) { logError($e,'confirmation_check'); }
        $order=findOrder($id);
    }
    $d = $order['data'];
    $out = [
        // The observation guides the buyer while signed confirmation is pending.
        'status' => orderDisplayStatus($order),
        'resumeAvailable' => canRecheckPayment($order),
        'number' => $order['number'],
        'email'  => preg_replace('/^(.).*(@.*)$/', '$1…$2', $d['email']),
        'name'   => explode(' ', $d['name'])[0],
        'total'  => $order['total'],
        'type'   => orderType($d),
        'offer'  => (string)($d['snapshot']['product_id'] ?? $d['offer'] ?? '1'),
        'demo'   => ($d['snapshot']['demo'] ?? false) === true,
        'testPayment' => testPayment($d),
    ];
    // The order id in the link is the bearer token for its vouchers; only a paid order has codes.
    if ($out['type'] === 'voucher' && $order['status'] === 'paid') {
        $out += [
            'unitPrice'   => $d['snapshot']['unit_price'],
            'buyerEmail'  => $d['email'],
            'product'    => $d['snapshot']['name'],
            'recipient'  => $d['recipient'] ?? '',
            'recipientVerbatim' => $d['snapshot']['recipient_verbatim'] ?? false,
            'dedication' => $d['dedication'] ?? '',
            'seller'     => cfg()['shop']['seller'] ?? '',
            'contact'    => cfg()['voucher_contact'] ?? '',
            'vouchers'   => array_map(fn($v) => ['code' => $v['code'], 'status' => $v['status'],
                'expires' => formatDate((int)$v['expires_at'])], vouchersFor($order['external_id'])),
        ];
    }
    jsonOut($out);
} catch (Throwable $e) {
    logError($e, 'status');
    jsonOut(['status' => 'error'], 503);
}
