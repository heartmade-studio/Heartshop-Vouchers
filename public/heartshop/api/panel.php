<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// The seller's orders panel (<base>/panel/). Every action except login and session needs a valid session cookie.
//   GET  ?action=session            {authenticated}
//   POST ?action=login  {password}  sets the cookie
//   POST ?action=logout
//   GET  ?action=orders             all orders, newest first
//   GET  ?action=export             the same as CSV
//   POST ?action=redeem|unredeem {code}
//   POST ?action=update {id, note?, done?, invoice_done?}
//   POST ?action=resend {id}
try {
    $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
    $method = $_SERVER['REQUEST_METHOD'];
    $in = [];
    if ($method === 'POST') {
        if (!sameOrigin()) jsonOut(['error' => 'Invalid origin'], 403);
        $raw = file_get_contents('php://input', false, null, 0, 16385) ?: '';
        if (strlen($raw) > 16384) jsonOut(['error' => 'Request too large'], 413);
        $in = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($in) || ($in && array_is_list($in))) jsonOut(['error' => 'Invalid request'], 400);
    } elseif ($method !== 'GET') {
        jsonOut(['error' => 'Method not allowed'], 405);
    }
    if (panelPasswordHash() === null) jsonOut(['error' => 'Panel nie jest skonfigurowany.', 'code' => 'not_configured'], 503);
    $token = $_COOKIE[PANEL_COOKIE] ?? null;

    if ($action === 'login' && $method === 'POST') {
        $new = panelLogin($in['password'] ?? null, $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if ($new === null) jsonOut(['error' => 'Nieprawidłowe hasło.', 'code' => 'wrong_password'], 401);
        panelCookie($new, time() + PANEL_TTL);
        jsonOut(['ok' => true]);
    }
    if ($action === 'logout' && $method === 'POST') {
        panelLogout($token);
        panelCookie('', 1);
        jsonOut(['ok' => true]);
    }
    $valid = panelSessionValid($token);
    if ($action === 'session' && $method === 'GET') jsonOut(['authenticated' => $valid, 'shop' => cfg()['shop']['name']]);
    if (!$valid) jsonOut(['error' => 'Zaloguj się.', 'code' => 'unauthorized'], 401);

    if ($action === 'orders' && $method === 'GET') {
        jsonOut(['orders' => panelOrders(), 'sandbox' => paymoveHosts()['sandbox'], 'now' => time()]);
    }
    if ($action === 'export' && $method === 'GET') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Cache-Control: no-store');
        header('Content-Disposition: attachment; filename="zamowienia-' . date('Y-m-d') . '.csv"');
        echo panelCsv(panelOrders(10000));
        exit;
    }
    if (in_array($action, ['redeem', 'unredeem'], true) && $method === 'POST') {
        [$ok, $reason, $voucher] = setVoucherUsed($in['code'] ?? null, $action === 'redeem');
        $messages = ['invalid' => 'Kod ma 8 znaków, np. ABCD-2345.', 'unknown' => 'Nie ma takiego kodu.',
            'not_paid' => 'Zamówienie z tym kodem nie jest opłacone.', 'already_used' => 'Ten voucher został już wykorzystany.',
            'not_used' => 'Ten voucher nie był oznaczony jako wykorzystany.', 'expired' => 'Ten voucher stracił ważność.'];
        $body = ['ok' => $ok, 'code' => $reason];
        if ($voucher) $body['voucher'] = ['code' => $voucher['code'], 'status' => $voucher['status'],
            'expires_at' => (int)$voucher['expires_at'], 'used_at' => $voucher['used_at'] === null ? null : (int)$voucher['used_at']];
        if (!$ok) $body['error'] = $messages[$reason] ?? 'Nie udało się.';
        jsonOut($body, $ok ? 200 : ($reason === 'unknown' ? 404 : 409));
    }
    if ($action === 'update' && $method === 'POST') {
        panelUpdate($in['id'] ?? null, array_intersect_key($in, array_flip(cfg()['invoice'] ? ['note', 'done', 'invoice_done'] : ['note', 'done'])));
        jsonOut(['ok' => true]);
    }
    if ($action === 'resend' && $method === 'POST') {
        panelResend($in['id'] ?? null);
        jsonOut(['ok' => true]);
    }
    jsonOut(['error' => 'Not Found'], 404);
} catch (PanelProblem $e) {
    if ($e->httpStatus === 429) header('Retry-After: ' . (600 - time() % 600));
    jsonOut(['error' => $e->getMessage(), 'code' => $e->reason], $e->httpStatus);
} catch (Throwable $e) {
    logError($e, 'panel');
    jsonOut(['error' => 'Chwilowy błąd panelu. Spróbuj ponownie.'], 503);
}
