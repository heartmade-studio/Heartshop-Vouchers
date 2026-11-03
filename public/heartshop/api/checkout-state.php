<?php
declare(strict_types=1);

final class CheckoutProblem extends RuntimeException {
    public function __construct(public int $httpStatus, public string $reason, string $message,
        public ?string $orderId = null, public array $errors = []) { parent::__construct($message); }
}
final class LegacyOrderException extends RuntimeException {}

// Everything the order promises, frozen at purchase: mail, panel and Furgonetka never reprice from config.
function makeSnapshot(array $order, array $config): array {
    $offer = offer($order['offer'] ?? null, $config);
    $items = $offer['price'] * $order['qty'];
    $snapshot = [
        'version'=>2, 'type'=>$offer['type'], 'product_id'=>$offer['id'], 'path'=>$offer['path'] ?? '',
        'demo'=>($offer['demo'] ?? true) === true,
        'name'=>$offer['name'], 'unit_price'=>$offer['price'], 'qty'=>$order['qty'],
        'items'=>$items, 'currency'=>'PLN', 'tax_rate'=>$config['tax_rate'],
        'terms_version'=>$config['terms_version'], 'accepted_at'=>time(),
    ];
    if ($offer['type'] === 'voucher') {
        return $snapshot + ['delivery'=>'', 'delivery_label'=>null, 'delivery_eta'=>null, 'shipping'=>0,
            'total'=>$items, 'valid_months'=>$offer['valid_months']];
    }
    $shipping = $config['delivery'][$order['delivery']]['price'];
    if ($config['free_shipping_from'] !== null && $items >= $config['free_shipping_from']) $shipping = 0;
    return $snapshot + [
        'delivery'=>$order['delivery'],
        'delivery_label'=>$config['delivery'][$order['delivery']]['label'],
        'delivery_eta'=>$config['delivery'][$order['delivery']]['eta'] ?? null,
        'shipping'=>$shipping, 'total'=>$items+$shipping,
    ];
}

function requestFingerprint(array $input): string {
    // Sort keys, ignore irrelevant extra fields. Keep validation before creation,
    // but look up old attempts before validation or any InPost network request.
    $keys=['offer','qty','email','phone','name','delivery','point','point_label','street','postcode','city',
        'recipient','dedication','invoice','company','nip','company_address','terms'];
    $canonical = array_intersect_key($input, array_flip($keys));
    ksort($canonical);
    return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

function existingAttempt(string $key, string $fingerprint): ?array {
    $s=db()->prepare('SELECT external_id, request_hash, payment_state, payment_url FROM orders WHERE idempotency_key=:key');
    $s->bindValue(':key',$key);$row=$s->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    if (!hash_equals($row['request_hash'],$fingerprint)) throw new CheckoutProblem(409,'attempt_conflict',
        'Poprzednia próba ma inne dane. Nie płać ponownie; skontaktuj się ze sklepem.', $row['external_id']);
    $order=findOrder($row['external_id']);
    if (in_array($order['status'],['paid','review'],true)) return ['orderId'=>$order['external_id'],'status'=>$order['status']];
    if ($order['status']==='canceled') throw new CheckoutProblem(409,'payment_closed',
        'Poprzednia płatność została anulowana. Rozpocznij nową płatność.', $order['external_id']);
    if ($row['payment_state']==='ready' && validPaymentUrl((string)$row['payment_url'],paymoveHosts()['checkout'])) {
        return ['orderId'=>$order['external_id'],'redirectUrl'=>$row['payment_url'],'total'=>$order['total']];
    }
    throw new CheckoutProblem(409,'payment_uncertain',
        'Wynik poprzedniej próby płatności jest nieznany. Nie płać ponownie; skontaktuj się ze sklepem.', $order['external_id']);
}

function prepareCheckout(array $input, string $key, ?callable $provider = null, ?int $now = null): array {
    if (!preg_match('/^[a-f0-9]{32}$/D',$key)) throw new CheckoutProblem(400,'invalid_key','Nieprawidłowy identyfikator próby.');
    $fingerprint=requestFingerprint($input);
    $prior=existingAttempt($key,$fingerprint);
    if ($prior !== null) return $prior;
    [$order,$errors]=validateOrder($input);
    if ($errors) throw new CheckoutProblem(422,'validation','Sprawdź dane zamówienia.',null,$errors);
    if (!paymoveHosts()['sandbox'] && (!(cfg()['production_ready'] ?? false) || (offer($order['offer'])['demo'] ?? true))) {
        throw new CheckoutProblem(503,'demo_only','Ta oferta demonstracyjna jest dostępna tylko w trybie testowym.');
    }
    $order['snapshot']=makeSnapshot($order,cfg());
    // Shop-wide cap on new provider calls only: junk or invalid POSTs cannot exhaust it.
    if (!consumeLimits(['global'=>200],$now)) throw new CheckoutProblem(429,'rate_limited','Zbyt wiele prób. Odczekaj kilka minut.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        // Serialize simultaneous first submissions. Never hold the DB lock over HTTP.
        $prior=existingAttempt($key,$fingerprint);
        if ($prior !== null) { $db->exec('COMMIT');return $prior; }
        $id='hs_'.bin2hex(random_bytes(12));
        $s=$db->prepare('INSERT INTO orders (external_id,total,data,created_at,idempotency_key,request_hash,payment_state,offer_type)
            VALUES (:id,:total,:data,:now,:key,:hash,"creating",:type)');
        $s->bindValue(':id',$id);$s->bindValue(':total',$order['snapshot']['total'],SQLITE3_INTEGER);
        $s->bindValue(':type',$order['snapshot']['type']);
        $s->bindValue(':data',json_encode($order,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $s->bindValue(':now',time(),SQLITE3_INTEGER);$s->bindValue(':key',$key);$s->bindValue(':hash',$fingerprint);
        $s->execute();$number=orderNumber($db->lastInsertRowID());$db->exec('COMMIT');
    } catch(Throwable $e) { $db->exec('ROLLBACK');throw $e; }
    try {
        $provider ??= 'createPayment';
        $url=$provider($id,$number,$order,$order['snapshot']['total']);
        if (!is_string($url) || !validPaymentUrl($url,paymoveHosts()['checkout'])) throw new RuntimeException('Invalid payment URL');
        $s=$db->prepare('UPDATE orders SET payment_state="ready", payment_url=:url WHERE external_id=:id AND payment_state="creating"');
        $s->bindValue(':url',$url);$s->bindValue(':id',$id);$s->execute();
        return ['orderId'=>$id,'redirectUrl'=>$url,'total'=>$order['snapshot']['total']];
    } catch(Throwable $e) {
        $s=$db->prepare('UPDATE orders SET payment_state="uncertain" WHERE external_id=:id AND payment_state="creating"');
        $s->bindValue(':id',$id);$s->execute();
        logError($e,'payment_create');
        throw new CheckoutProblem(409,'payment_uncertain',
            'Nie można potwierdzić wyniku próby płatności. Nie płać ponownie; skontaktuj się ze sklepem.',$id);
    }
}

function validPaymentUrl(string $url, string $host): bool {
    $p=parse_url($url);
    return is_array($p) && ($p['scheme']??'')==='https' && ($p['host']??'')===$host
        && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment'])
        && (!isset($p['port']) || $p['port']===443);
}

function rateLimit(string $address, ?int $now=null): bool {
    return consumeLimits(['ip:'.hash('sha256',$address)=>20],$now);
}

/** Fixed 10-minute windows; all buckets must have room, then each is charged once. */
function consumeLimits(array $limits, ?int $now=null): bool {
    $now ??= time();$window=intdiv($now,600);$db=db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->exec('DELETE FROM request_limits WHERE window < '.($window-1));
        foreach ($limits as $key=>$limit) {
            $s=$db->prepare('SELECT hits FROM request_limits WHERE bucket=:key AND window=:window');
            $s->bindValue(':key',$key);$s->bindValue(':window',$window,SQLITE3_INTEGER);
            $row=$s->execute()->fetchArray(SQLITE3_ASSOC);
            if ($row && $row['hits'] >= $limit) { $db->exec('COMMIT');return false; }
        }
        foreach ($limits as $key=>$limit) {
            $s=$db->prepare('INSERT INTO request_limits(bucket,window,hits) VALUES(:key,:window,1)
                ON CONFLICT(bucket,window) DO UPDATE SET hits=hits+1');
            $s->bindValue(':key',$key);$s->bindValue(':window',$window,SQLITE3_INTEGER);$s->execute();
        }
        $db->exec('COMMIT');return true;
    } catch(Throwable $e) { $db->exec('ROLLBACK');throw $e; }
}

function furgonetkaOrder(array $row): array {
    $d=$row['data'];$snapshot=$d['snapshot']??null;
    if (!is_array($snapshot)) throw new LegacyOrderException('Legacy order needs historical reconciliation');
    $tz=new DateTimeZone('Europe/Warsaw');
    $iso=static fn($ts)=>(new DateTimeImmutable('@'.(int)$ts))->setTimezone($tz)->format('Y-m-d\TH:i:s');
    [$first,$last]=array_pad(explode(' ',$d['name'],2),2,'');
    $invoice=null;
    if ($d['invoice']) {
        $address=parseCompanyAddress($d['company_address']) ?? ['street'=>$d['company_address'],'postcode'=>'','city'=>''];
        $invoice=['company'=>$d['company'],'nip'=>$d['nip'],...$address,
            'countryCode'=>'PL','phone'=>$d['phone'],'email'=>$d['email']];
    }
    return [
        'sourceOrderId'=>$row['number'], 'datetimeOrder'=>$iso($row['created_at']),
        'sourceDatetimeChange'=>$iso(max((int)$row['paid_at'],(int)($row['tracking_at']??0))),
        'service'=>$d['delivery']==='paczkomat'?'inpost':'inpostkurier',
        'serviceDescription'=>$snapshot['delivery_label'], 'status'=>'paid',
        'totalPrice'=>$row['total']/100,'shippingCost'=>$snapshot['shipping']/100,
        'shippingTaxRate'=>$snapshot['tax_rate']??null,'totalPaid'=>$row['total']/100,'codAmount'=>0,
        'point'=>$d['delivery']==='paczkomat'?$d['point']:null,
        'shippingAddress'=>['name'=>$first,'surname'=>$last,'street'=>$d['street'],
            'postcode'=>$d['postcode'],'city'=>$d['city'],'countryCode'=>'PL','phone'=>$d['phone'],'email'=>$d['email']],
        'invoiceAddress'=>$invoice,
        'products'=>[['sourceProductId'=>$snapshot['product_id'],'name'=>$snapshot['name'],
            'priceGross'=>$snapshot['unit_price']/100,'taxRate'=>$snapshot['tax_rate']??null,'quantity'=>$snapshot['qty'],'unit'=>'szt.']],
        'paymentDatetime'=>$iso($row['paid_at']),
    ];
}
