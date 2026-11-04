<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// GET: public shop settings for the checkout page. POST: validate, store the order, create the payment.
try {
    $c = cfg();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $h = paymoveHosts();
        $offers = [];
        foreach (array_keys($c['offers']) as $id) $offers[$id] = offer($id);
        jsonOut([
            'offers'           => (object)$offers, // always a JSON object keyed by offer id
            'defaultOffer'     => (string)$c['default_offer'],
            'product'          => offer(null), // tabs opened before typed offers read this
            'delivery'         => $c['delivery'],
            'freeShippingFrom' => $c['free_shipping_from'],
            'invoice'          => $c['invoice'],
            'sandbox'          => $h['sandbox'],
            'paymoveSdk'       => 'https://' . $h['checkout'] . '/v1/sdk.js',
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['error' => 'Method not allowed'], 405);
    if (!sameOrigin()) jsonOut(['error' => 'Invalid origin'], 403);

    if (!rateLimit($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
        header('Retry-After: '.(600-time()%600));
        jsonOut(['error'=>'Zbyt wiele prób. Odczekaj kilka minut.','code'=>'rate_limited'],429);
    }
    $raw=file_get_contents('php://input',false,null,0,8193) ?: '';
    if (strlen($raw)>8192) jsonOut(['error'=>'Request too large'],413);
    $in=json_decode($raw,true);
    if (!is_array($in) || array_is_list($in)) jsonOut(['error'=>'Invalid request'],400);
    jsonOut(prepareCheckout($in,$_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
} catch (CheckoutProblem $e) {
    if ($e->httpStatus===429) header('Retry-After: '.(600-time()%600));
    $body=['error'=>$e->getMessage(),'code'=>$e->reason];
    if ($e->orderId!==null) $body['orderId']=$e->orderId;
    if ($e->errors) $body['errors']=$e->errors;
    jsonOut($body,$e->httpStatus);
} catch (Throwable $e) {
    logError($e, 'order');
    jsonOut(['error' => 'Nie udało się połączyć z płatnościami. Spróbuj ponownie za chwilę.'], 503);
}
