<?php
declare(strict_types=1);

final class CheckoutProblem extends RuntimeException {
    public function __construct(public int $httpStatus, public string $reason, string $message,
        public ?string $orderId = null, public array $errors = [], public ?int $retryAfter = null) { parent::__construct($message); }
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
            'total'=>$items, 'valid_months'=>$offer['valid_months'],
            'invoice_request_text'=>(string)($config['invoice_request_text'] ?? '')];
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

function findAttempt(string $key, string $fingerprint): ?array {
    $s=db()->prepare('SELECT external_id, request_hash FROM orders WHERE idempotency_key=:key');
    $s->bindValue(':key',$key);$row=$s->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    if (!hash_equals($row['request_hash'],$fingerprint)) throw new CheckoutProblem(409,'attempt_conflict',
        'Poprzednia próba ma inne dane. Nie płać ponownie; skontaktuj się ze sklepem.', $row['external_id']);
    return findOrder($row['external_id']);
}

function demoOnly(?string $id = null): never {
    throw new CheckoutProblem(503,'demo_only','Ta oferta demonstracyjna jest dostępna tylko w trybie testowym.',$id);
}

/** The merchant's CURRENT gates. Checked before every provider create, including a
 * recovered retry: an order frozen while selling was open must not create a payment
 * after the merchant closes it. Status reads of existing payments stay available. */
function salesOpen(array $order): bool {
    if (paymoveHosts()['sandbox']) return true;
    if (($order['snapshot']['demo'] ?? false) === true) return false;
    return !(!(cfg()['production_ready'] ?? false) || (offer($order['offer'] ?? '')['demo'] ?? true) || invoiceTextNeedsApproval(cfg()));
}

function prepareCheckout(array $input, string $key, ?callable $provider = null, ?int $now = null,
    ?callable $statusCheck = null): array {
    $now ??= time();
    if (!preg_match('/^[a-f0-9]{32}$/D',$key)) throw new CheckoutProblem(400,'invalid_key','Nieprawidłowy identyfikator próby.');
    $fingerprint=requestFingerprint($input);
    $prior=findAttempt($key,$fingerprint);
    if ($prior !== null) return resumeCheckout($prior,$provider,$now,$statusCheck);
    [$order,$errors]=validateOrder($input);
    if ($errors) throw new CheckoutProblem(422,'validation','Sprawdź dane zamówienia.',null,$errors);
    if (!salesOpen($order)) demoOnly();
    $order['snapshot']=makeSnapshot($order,cfg());
    $order['snapshot']['payment_scope']=paymentScope();
    // Shop-wide cap on new provider calls only: junk or invalid POSTs cannot exhaust it.
    if (!consumeLimits(['global'=>200],$now)) throw new CheckoutProblem(429,'rate_limited','Zbyt wiele prób. Odczekaj kilka minut.');
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        // Serialize simultaneous first submissions. Never hold the DB lock over HTTP.
        $prior=findAttempt($key,$fingerprint);
        if ($prior === null) {
            $id='hs_'.bin2hex(random_bytes(12));
            $s=$db->prepare('INSERT INTO orders (external_id,total,data,created_at,idempotency_key,request_hash,payment_state,offer_type,payment_attempt_at)
            VALUES (:id,:total,:data,:now,:key,:hash,"creating",:type,:now)');
            $s->bindValue(':id',$id);$s->bindValue(':total',$order['snapshot']['total'],SQLITE3_INTEGER);
            $s->bindValue(':type',$order['snapshot']['type']);
            $s->bindValue(':data',json_encode($order,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            $s->bindValue(':now',$now,SQLITE3_INTEGER);$s->bindValue(':key',$key);$s->bindValue(':hash',$fingerprint);
            $s->execute();
        }
        $db->exec('COMMIT');
    } catch(Throwable $e) { $db->exec('ROLLBACK');throw $e; }
    if ($prior !== null) return resumeCheckout($prior,$provider,$now,$statusCheck);
    return finishCheckout(findOrder($id),$provider,$now,$statusCheck,false);
}

function checkoutWaiting(string $id, int $seconds = 3): never {
    throw new CheckoutProblem(409,'payment_wait',
        'Sprawdzamy poprzednią próbę. Odczekaj chwilę i kliknij ponownie — wznowimy tę samą płatność.',$id,[],max(1,$seconds));
}

function checkoutNeedsReview(string $id): never {
    throw new CheckoutProblem(409,'payment_review',
        'Ta płatność wymaga sprawdzenia przez sklep. Nie płać ponownie; podaj sklepowi numer zamówienia.',$id);
}

function resumeCheckout(array $order, ?callable $provider, int $now, ?callable $statusCheck): array {
    $id=$order['external_id'];
    if (in_array($order['status'],['paid','review'],true)) return ['orderId'=>$id,'status'=>$order['status']];
    $scope=$order['data']['snapshot']['payment_scope']??null;
    if ($scope !== null && !hash_equals($scope,paymentScope())) checkoutNeedsReview($id);
    if ($order['payment_state']==='ready' && validPaymentUrl((string)$order['payment_url'])) {
        return checkoutResult($order,$statusCheck,true,$now);
    }
    // Legacy real-money attempts have no recorded provider account. Never replay
    // them into a potentially different product. Legacy demo attempts are sandbox-only.
    if ($scope===null && !(($order['data']['snapshot']['demo']??false)===true && paymoveHosts()['sandbox'])) {
        checkoutNeedsReview($id);
    }
    $db=db();$db->exec('BEGIN IMMEDIATE');
    try {
        $current=findOrder($id);
        $reuse=in_array($current['status'],['paid','review'],true) || $current['payment_state']==='ready';
        // The HTTP timeout is 20s. A crashed worker may be reclaimed after 30s;
        // an uncertain response has a short cooldown. No DB lock is held over HTTP.
        $wait=$current['payment_state']==='creating' ? 30 : 3;
        $remaining=$wait-($now-(int)($current['payment_attempt_at']??$current['created_at']));
        $busy=$remaining>0;
        $closed=!$reuse && !$busy && !salesOpen($current['data']);
        if (!$reuse && !$busy && !$closed) {
            $s=$db->prepare('UPDATE orders SET payment_state="creating",payment_attempt_at=:now,
            data=json_set(data,"$.snapshot.payment_scope",:scope) WHERE external_id=:id');
            $s->bindValue(':now',$now,SQLITE3_INTEGER);$s->bindValue(':scope',paymentScope());$s->bindValue(':id',$id);$s->execute();
        }
        $db->exec('COMMIT');
    } catch(Throwable $e) { $db->exec('ROLLBACK');throw $e; }
    if ($reuse) return checkoutResult($current,$statusCheck,true,$now);
    if ($busy) checkoutWaiting($id,$remaining);
    if ($closed) demoOnly($id);
    return finishCheckout(findOrder($id),$provider,$now,$statusCheck,true);
}

function finishCheckout(array $order, ?callable $provider, int $now, ?callable $statusCheck, bool $recovered): array {
    $id=$order['external_id'];$db=db();
    if ($recovered && !consumeLimits(['provider_recovery'=>200],$now)) {
        $s=$db->prepare('UPDATE orders SET payment_state="uncertain" WHERE external_id=:id AND payment_state="creating" AND payment_attempt_at=:now');
        $s->bindValue(':id',$id);$s->bindValue(':now',$now,SQLITE3_INTEGER);$s->execute();
        throw new CheckoutProblem(429,'rate_limited','Zbyt wiele prób. Odczekaj kilka minut.',$id);
    }
    try {
        $provider ??= 'createPayment';
        // Paymove deduplicates by externalId. Retry the SAME id with the frozen
        // order, even if prices/configuration/form values have since changed.
        $url=$provider($id,$order['number'],$order['data'],(int)$order['total']);
        if (!is_string($url) || !validPaymentUrl($url)) throw new PaymoveFailure('redirect');
        $s=$db->prepare('UPDATE orders SET payment_state="ready",payment_url=:url,payment_error=NULL
            WHERE external_id=:id AND payment_state="creating" AND payment_attempt_at=:now');
        $s->bindValue(':url',$url);$s->bindValue(':id',$id);$s->bindValue(':now',$now,SQLITE3_INTEGER);$s->execute();
    } catch(Throwable $e) {
        $s=$db->prepare('UPDATE orders SET payment_state="uncertain",payment_error=:error
            WHERE external_id=:id AND payment_state="creating" AND payment_attempt_at=:now');
        $s->bindValue(':error',json_encode($e instanceof PaymoveFailure ? $e->diagnostic() : ['kind'=>'internal']));
        $s->bindValue(':id',$id);$s->bindValue(':now',$now,SQLITE3_INTEGER);$s->execute();
        logError($e,'payment_create');
        checkoutWaiting($id);
    }
    return checkoutResult(findOrder($id),$statusCheck,$recovered,$now);
}

function checkoutResult(array $order, ?callable $statusCheck, bool $probe, int $now): array {
    $id=$order['external_id'];
    if (in_array($order['status'],['paid','review'],true)) return ['orderId'=>$id,'status'=>$order['status']];
    $url=(string)$order['payment_url'];
    if ($order['payment_state']!=='ready' || !validPaymentUrl($url)) checkoutWaiting($id);
    // A fresh order cannot have been paid through our UI yet. Every resumed
    // payment must be checked; an unknown URL/response fails closed.
    if ($probe) {
        if ($statusCheck===null && paymentStatusUrl($url)===null) checkoutNeedsReview($id);
        if (!consumeLimits(['provider_status'=>400],$now)) {
            throw new CheckoutProblem(429,'rate_limited','Zbyt wiele prób. Odczekaj kilka minut.',$id);
        }
        try { $observation=($statusCheck??'paymovePaymentStatus')($url); }
        catch(Throwable $e) {
            logError($e,'payment_status');
            if ($e instanceof PaymoveFailure && $e->kind==='status_url') checkoutNeedsReview($id);
            checkoutWaiting($id);
        }
        $status=is_array($observation) ? ($observation['status']??null) : $observation;
        if (in_array($status,['COMPLETED','PENDING','WAITING_FOR_EXTERNAL_ACTION'],true)) {
            // This unsigned read can BLOCK payment, never issue vouchers or mail.
            $observed=['status'=>$status,'order_id'=>is_array($observation)?($observation['orderId']??null):null,'at'=>$now];
            $s=db()->prepare('UPDATE orders SET data=json_set(data,"$.payment_observation",json(:observed)) WHERE external_id=:id AND status IN ("pending","canceled")');
            $s->bindValue(':observed',json_encode($observed,JSON_THROW_ON_ERROR));$s->bindValue(':id',$id);$s->execute();
            $current=findOrder($id);
            if (in_array($current['status'],['paid','review'],true)) return ['orderId'=>$id,'status'=>$current['status']];
            return ['orderId'=>$id,'status'=>'processing'];
        }
        if (!in_array($status,['INITIALIZED','CANCELED','ERROR'],true)) checkoutNeedsReview($id);
        $s=db()->prepare('UPDATE orders SET data=json_remove(data,"$.payment_observation") WHERE external_id=:id AND status IN ("pending","canceled")');
        $s->bindValue(':id',$id);$s->execute();
    }
    // A webhook may have arrived during the HTTP call. It always wins.
    $current=findOrder($id);
    if (in_array($current['status'],['paid','review'],true)) return ['orderId'=>$id,'status'=>$current['status']];
    return ['orderId'=>$id,'redirectUrl'=>$url,'total'=>$order['total']];
}

function validPaymentUrl(string $url): bool {
    $hosts = paymoveHosts();
    $allowed = [$hosts['checkout']];
    if ($hosts['sandbox']) $allowed[] = 'pay.sandbox.paymove.io';
    $p=parse_url($url);
    return is_array($p) && ($p['scheme']??'')==='https' && in_array($p['host'] ?? '', $allowed, true)
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
