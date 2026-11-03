<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Thank-you page polls this. Read-only: the webhook is the source of truth.
try {
    $id = is_string($_GET['o'] ?? null) ? $_GET['o'] : '';
    $order = preg_match('/^hs_[a-f0-9]{24}$/', $id) ? findOrder($id) : null;
    if (!$order) jsonOut(['status' => 'unknown'], 404);
    $d = $order['data'];
    $out = [
        'status' => $order['status'],
        'number' => $order['number'],
        'email'  => preg_replace('/^(.).*(@.*)$/', '$1…$2', $d['email']),
        'name'   => explode(' ', $d['name'])[0],
        'total'  => $order['total'],
        'type'   => orderType($d),
        'offer'  => (string)($d['snapshot']['product_id'] ?? $d['offer'] ?? '1'),
        'demo'   => ($d['snapshot']['demo'] ?? false) === true,
    ];
    // The order id in the link is the bearer token for its vouchers; only a paid order has codes.
    if ($out['type'] === 'voucher' && $order['status'] === 'paid') {
        $out += [
            'product'    => $d['snapshot']['name'],
            'recipient'  => $d['recipient'] ?? '',
            'dedication' => $d['dedication'] ?? '',
            'seller'     => cfg()['shop']['seller'] ?? '',
            'vouchers'   => array_map(fn($v) => ['code' => $v['code'], 'status' => $v['status'],
                'expires' => formatDate((int)$v['expires_at'])], vouchersFor($order['external_id'])),
        ];
    }
    jsonOut($out);
} catch (Throwable $e) {
    logError($e, 'status');
    jsonOut(['status' => 'error'], 503);
}
