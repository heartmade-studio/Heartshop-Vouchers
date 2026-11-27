<?php
declare(strict_types=1);

/** Durable queued messages are at-least-once: a worker can crash after mail() accepts
 * a message but before recording success, so lease expiry may cause a duplicate. */
function reliabilitySchema(SQLite3 $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS payment_transactions (
        order_external_id TEXT NOT NULL, provider_order_id TEXT NOT NULL,
        status TEXT NOT NULL, updated_at INTEGER NOT NULL,
        PRIMARY KEY(order_external_id,provider_order_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS signed_completions (
        scope TEXT NOT NULL, provider_id TEXT NOT NULL, price INTEGER NOT NULL,
        method TEXT, received_at INTEGER NOT NULL, matched_order TEXT,
        PRIMARY KEY(scope,provider_id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS confirmation_checks (
        order_id TEXT PRIMARY KEY, next_at INTEGER NOT NULL DEFAULT 0
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS mail_outbox (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_external_id TEXT NOT NULL,
        recipient TEXT NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        reply_to TEXT,
        status TEXT NOT NULL DEFAULT "pending" CHECK(status IN ("pending","sending","sent")),
        attempts INTEGER NOT NULL DEFAULT 0,
        next_attempt_at INTEGER NOT NULL DEFAULT 0,
        lease_token TEXT,
        lease_until INTEGER,
        created_at INTEGER NOT NULL,
        sent_at INTEGER,
        last_error TEXT
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS mail_outbox_due ON mail_outbox(status,next_attempt_at,lease_until,id)');
}

// Legacy orders have no recorded redirect and use their creation time.
const CONFIRMATION_ACTIVITY = 'max(o.created_at,coalesce(o.payment_link_served_at,o.created_at))';
const CONFIRMATION_WINDOW = 'c.scope=json_extract(o.data,"$.snapshot.payment_scope")
    AND '.CONFIRMATION_ACTIVITY.' >= unixepoch()-86400
    AND c.received_at BETWEEN '.CONFIRMATION_ACTIVITY.' AND unixepoch()';
const CONFIRMATION_CANDIDATE = 'SELECT 1 FROM orders o JOIN signed_completions c ON '.CONFIRMATION_WINDOW.'
    WHERE o.external_id=:id AND o.status IN ("pending","canceled")
    AND (c.matched_order IS NULL OR c.matched_order=:id)';

// PAYMOVE can omit the merchant externalId. Retain only authenticated completion fields.
// Queue the existing orders in this payment scope once. Their provider-issued checkout
// URLs determine the transaction identity; two equal totals never establish a match.
function recordSignedCompletion(array $event): void {
    $id=$event['orderId'] ?? null;
    $price=$event['price'] ?? null;
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id)
        || (!is_int($price) && !is_string($price)) || !preg_match('/^[0-9]{1,12}$/D',(string)$price)
        || !is_string($event['paymentMethod'] ?? '')) throw new InvalidArgumentException('Invalid completion');
    $db=db();
    $scope=paymentScope();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $q=$db->prepare('INSERT OR IGNORE INTO signed_completions(scope,provider_id,price,method,received_at,matched_order)
            VALUES(:scope,:id,:price,:method,:now,(SELECT external_id FROM orders
                WHERE status="paid" AND paymove_order_id=:id AND json_extract(data,"$.snapshot.payment_scope")=:scope))');
        $q->bindValue(':scope',$scope); $q->bindValue(':id',$id); $q->bindValue(':price',(int)$price,SQLITE3_INTEGER);
        $q->bindValue(':method',$event['paymentMethod'] ?? ''); $q->bindValue(':now',time(),SQLITE3_INTEGER); $q->execute();
        if ($db->changes()) {
            $q=$db->prepare('INSERT OR IGNORE INTO confirmation_checks(order_id)
                SELECT o.external_id FROM orders o JOIN signed_completions c ON '.CONFIRMATION_WINDOW.'
                WHERE o.status IN ("pending","canceled") AND c.scope=:scope AND c.provider_id=:provider
                AND c.matched_order IS NULL');
            $q->bindValue(':scope',$scope); $q->bindValue(':provider',$id); $q->execute();
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
}

function checkSignedCompletion(string $id, ?callable $lookup=null, bool $forDisplay=false): void {
    $db=db();
    $now=time();
    // Drop exhausted candidates atomically. A later event can enqueue the order again.
    $q=$db->prepare('DELETE FROM confirmation_checks WHERE order_id=:id AND NOT EXISTS ('.CONFIRMATION_CANDIDATE.')');
    $q->bindValue(':id',$id); $q->execute();
    $q=$db->prepare('UPDATE confirmation_checks SET next_at=:later WHERE order_id=:id');
    $q->bindValue(':later',$now+60,SQLITE3_INTEGER); $q->bindValue(':id',$id); $q->execute();
    $queued=$db->changes()===1;
    if (!$queued && !$forDisplay) return;
    $order=findOrder($id);
    $scope=paymentScope();
    if (!$order || !in_array($order['status'],['pending','canceled'],true) || $order['payment_state']!=='ready'
        || !$order['payment_url'] || ($order['data']['snapshot']['payment_scope']??$scope)!==$scope) return;

    $seen=refreshPaymentObservation($order,$now,$lookup);
    if (!$queued || ($seen['status']??null)!=='COMPLETED' || empty($seen['orderId'])) return;
    $q=$db->prepare('UPDATE signed_completions AS c SET matched_order=:id WHERE scope=:scope AND provider_id=:provider
        AND (matched_order IS NULL OR matched_order=:id) AND EXISTS (
            SELECT 1 FROM orders o WHERE o.external_id=:id AND o.status IN ("pending","canceled") AND '.CONFIRMATION_WINDOW.')
        RETURNING provider_id,price,method');
    $q->bindValue(':scope',$scope); $q->bindValue(':provider',$seen['orderId']); $q->bindValue(':id',$id);
    $result=$q->execute(); $signed=$result->fetchArray(SQLITE3_ASSOC); $result->finalize(); $q->close();
    if ($signed) {
        applyPaymentEvent(['externalId'=>$id,'orderId'=>$signed['provider_id'],'status'=>'COMPLETED',
            'price'=>$signed['price'],'paymentMethod'=>$signed['method']]);
    }
}

function refreshPaymentObservation(array $order, int $now, ?callable $lookup): ?array {
    $db=db();
    $id=$order['external_id'];
    // A status poll and the worker share one provider read per order per minute.
    $q=$db->prepare('UPDATE orders SET status_check_after=:later WHERE external_id=:id AND status_check_after<=:now');
    $q->bindValue(':later',$now+60,SQLITE3_INTEGER); $q->bindValue(':now',$now,SQLITE3_INTEGER); $q->bindValue(':id',$id);
    $q->execute();
    if (!$db->changes() || !consumeLimits(['confirmation_status'=>400],$now)) return null;
    $seen=($lookup ?? 'paymovePaymentStatus')($order['payment_url']);
    $status=$seen['status']??null;
    $hint=['status'=>$status,'order_id'=>paymentConfirming($status)?($seen['orderId']??null):null,'at'=>$now];
    // A checkout or signed event that changed the order while HTTP was in flight wins.
    $q=$db->prepare('UPDATE orders SET data=json_set(data,"$.payment_observation",json(:hint))
        WHERE external_id=:id AND status=:status AND json_extract(data,"$.payment_observation.at") IS :previous
        AND payment_link_served_at IS :served
        AND status_check_after=:later');
    $q->bindValue(':hint',json_encode($hint,JSON_THROW_ON_ERROR)); $q->bindValue(':id',$id);
    $q->bindValue(':status',$order['status']); $q->bindValue(':later',$now+60,SQLITE3_INTEGER);
    $q->bindValue(':served',$order['payment_link_served_at'],$order['payment_link_served_at']===null?SQLITE3_NULL:SQLITE3_INTEGER);
    $previous=$order['data']['payment_observation']['at']??null;
    $q->bindValue(':previous',$previous,$previous===null?SQLITE3_NULL:SQLITE3_INTEGER);
    $q->execute();

    return $seen;
}

// Orders created before typed offers have no type: they were all shipped products.
function orderType(array $data): string {
    return $data['snapshot']['type'] ?? 'shipping';
}

function paymentConfirming(?string $status): bool {
    return in_array($status,['PENDING','WAITING_FOR_EXTERNAL_ACTION','COMPLETED'],true);
}

function paymentResumable(?string $status): bool {
    return in_array($status,['INITIALIZED','CANCELED','ERROR'],true);
}

function orderDisplayStatus(array $order): string {
    if (in_array($order['status'],['pending','canceled'],true)
        && paymentConfirming($order['data']['payment_observation']['status']??null)) return 'confirming';
    return $order['status'];
}

function canRecheckPayment(array $order, ?int $now = null): bool {
    $observation=$order['data']['payment_observation']??[];
    return in_array($order['status'],['pending','canceled'],true) && $order['payment_state']==='ready'
        && (!isset($order['data']['snapshot']['payment_scope']) || $order['data']['snapshot']['payment_scope']===paymentScope())
        && paymentResumable($observation['status']??null)
        && ($observation['at']??0)>($now??time())-60;
}

function applyPaymentEvent(array $event): array {
    if (!is_string($event['externalId']??null) || $event['externalId']==='' || strlen($event['externalId'])>128 || !is_string($event['status']??null)) throw new InvalidArgumentException('Invalid payment event');
    if (!in_array($event['status'],['COMPLETED','CANCELED','ERROR'],true)) return ['status'=>'ignored','changed'=>false];
    $price=$event['price']??null;
    if ($event['status']==='COMPLETED' && !(is_int($price) && $price>=0) && !(is_string($price) && preg_match('/^[0-9]{1,12}$/D',$price))) throw new InvalidArgumentException('Invalid payment price');
    foreach (['paymentMethod','orderId'] as $key) if (isset($event[$key]) && !is_string($event[$key])) throw new InvalidArgumentException('Invalid payment event');
    $db=db(); $db->exec('BEGIN IMMEDIATE');
    try {
        // Serialize decision and update: never act on a pre-transaction status.
        $order=findOrder($event['externalId']);
        if ($order && trim($event['orderId']??'')!=='') {
            // A priced item can have multiple failed transaction ids before its
            // successful transaction. Keep the signed history independently of
            // the immutable binding to the transaction that actually paid.
            $tx=$db->prepare('INSERT INTO payment_transactions(order_external_id,provider_order_id,status,updated_at)
                VALUES(:id,:provider,:status,:now) ON CONFLICT(order_external_id,provider_order_id)
                DO UPDATE SET status=CASE WHEN status="COMPLETED" THEN status ELSE excluded.status END,updated_at=excluded.updated_at');
            $tx->bindValue(':id',$order['external_id']);$tx->bindValue(':provider',$event['orderId']);
            $tx->bindValue(':status',$event['status']);$tx->bindValue(':now',time(),SQLITE3_INTEGER);$tx->execute();
        }
        if (!$order || !in_array($order['status'],['pending','canceled'],true)) {
            $db->exec('COMMIT'); return ['status'=>$order['status']??'ignored','changed'=>false];
        }
        $observed=$order['data']['payment_observation']??null;
        if ($event['status']!=='COMPLETED' && is_array($observed) && !empty($observed['order_id'])
            && $observed['order_id']!==($event['orderId']??null)) {
            // The active transaction can be newer even when no failed webhook
            // has reached the shop yet and its local status is still pending.
            $db->exec('COMMIT');return ['status'=>$order['status'],'changed'=>false];
        }
        if ($event['status']!=='COMPLETED' && $order['status']==='canceled') {
            // Ignore an old failed webhook when a different resumed transaction
            // is processing. A failure for that resumed transaction clears the hint.
            $clear=is_array($observed) && (empty($observed['order_id']) || $observed['order_id']===($event['orderId']??null));
            if ($clear) {
                $s=$db->prepare('UPDATE orders SET data=json_remove(data,"$.payment_observation") WHERE external_id=:id');
                $s->bindValue(':id',$order['external_id']);$s->execute();
            }
            $db->exec('COMMIT');return ['status'=>'canceled','changed'=>$clear];
        }
        $status=(string)$order['status']; $target=$status; $paidAt=null; $review=false;
        $incoming=strtoupper($event['status']);
        if ($incoming==='COMPLETED' && !in_array($status,['paid','review'],true)) {
            // Legacy canceled rows may bind the failed transaction. A new signed
            // completion after failure can replace that failed id, never a paid id.
            $failedBinding=$status==='canceled' && empty($order['paid_at']);
            if (trim($event['orderId']??'')==='' || (ltrim((string)$price,'0') ?: '0') !== (string)$order['total'] ||
                (!empty($order['paymove_order_id']) && ($event['orderId']??null)!==$order['paymove_order_id'] && !$failedBinding)) { $target='review'; $review=true; }
            else { $target='paid'; $paidAt=time(); }
        } elseif (in_array($incoming,['CANCELED','ERROR'],true) && $status==='pending') {
            $target='canceled';
        }
        $stmt=$db->prepare('UPDATE orders SET status=:status, paid_at=CASE WHEN :paid IS NULL THEN paid_at ELSE :paid END,
            payment_method=COALESCE(:method,payment_method), paymove_order_id=CASE WHEN :paid IS NOT NULL THEN :pm ELSE paymove_order_id END,
            data=json_remove(data,"$.payment_observation") WHERE external_id=:id AND status=:old');
        $stmt->bindValue(':status',$target); $stmt->bindValue(':paid',$paidAt, $paidAt===null?SQLITE3_NULL:SQLITE3_INTEGER); $stmt->bindValue(':method',$event['paymentMethod']??null,isset($event['paymentMethod'])?SQLITE3_TEXT:SQLITE3_NULL); $stmt->bindValue(':pm',$event['orderId']??null,isset($event['orderId'])?SQLITE3_TEXT:SQLITE3_NULL); $stmt->bindValue(':id',$event['externalId']); $stmt->bindValue(':old',$status); $stmt->execute();
        $changed=$db->changes()===1;
        if ($changed && in_array($target,['paid','review'],true) && in_array($status,['pending','canceled'],true)) {
            $updated=findOrder($event['externalId']);
            if ($target==='paid') {
                $match=$db->prepare('UPDATE signed_completions SET matched_order=:id WHERE provider_id=:provider
                    AND scope=:scope AND matched_order IS NULL');
                $match->bindValue(':id',$order['external_id']);
                $match->bindValue(':provider',$event['orderId']);
                $match->bindValue(':scope',$order['data']['snapshot']['payment_scope'] ?? '');
                $match->execute();
            }
            // Codes exist only for a confirmed payment; a review order gets them after manual reconciliation.
            if ($target==='paid' && orderType($updated['data'])==='voucher') issueVouchers($db,$updated);
            $messages=paymentNotices($updated,$target,$event);
            foreach($messages as $message) queueMail($db,$event['externalId'],$message);
        }
        $db->exec('COMMIT');
        $result=findOrder($event['externalId']); return ['status'=>$result['status'],'changed'=>$changed];
    } catch(Throwable $e) { try{$db->exec('ROLLBACK');}catch(Throwable){} throw $e; }
}

// PAYMOVE refunds are always full and name the refunded payment in parentOrderId. A completed
// refund of the transaction that paid an order withdraws it: its voucher codes stop redeeming and
// it leaves the Furgonetka export. A refund of any other transaction (a duplicate payment) changes nothing.
function applyRefundEvent(array $event): array {
    foreach (['externalId','orderId','parentOrderId','status'] as $key) {
        if (!is_string($event[$key] ?? null) || strlen($event[$key])>128) throw new InvalidArgumentException('Invalid refund event');
    }
    if ($event['status']!=='COMPLETED' || $event['parentOrderId']==='') return ['status'=>'ignored','changed'=>false];
    $db=db(); $db->exec('BEGIN IMMEDIATE');
    try {
        // The merchant externalId can be empty; the paid transaction alone then identifies one order.
        $q=$db->prepare('SELECT external_id FROM orders o WHERE status IN ("paid","review")
            AND (:id="" OR external_id=:id)
            AND (paymove_order_id=:parent OR EXISTS (SELECT 1 FROM payment_transactions t
                WHERE t.order_external_id=o.external_id AND t.provider_order_id=:parent AND t.status="COMPLETED"))
            LIMIT 2');
        $q->bindValue(':id',$event['externalId']); $q->bindValue(':parent',$event['parentOrderId']);
        $res=$q->execute(); $ids=[];
        while ($row=$res->fetchArray(SQLITE3_ASSOC)) $ids[]=$row['external_id'];
        $res->finalize();
        if (count($ids)!==1) {
            $db->exec('COMMIT');
            $known=$event['externalId']!=='' ? findOrder($event['externalId']) : null;
            return ['status'=>$known['status'] ?? 'ignored','changed'=>false];
        }
        $order=findOrder($ids[0]);
        $refund=['order_id'=>$event['orderId'],'parent_order_id'=>$event['parentOrderId'],'at'=>time()];
        $u=$db->prepare('UPDATE orders SET status="refunded", data=json_set(data,"$.refund",json(:refund))
            WHERE external_id=:id AND status=:old');
        $u->bindValue(':refund',json_encode($refund,JSON_THROW_ON_ERROR)); $u->bindValue(':id',$order['external_id']);
        $u->bindValue(':old',$order['status']); $u->execute();
        $changed=$db->changes()===1;
        if ($changed) foreach (refundNotices($order,$refund) as $message) queueMail($db,$order['external_id'],$message);
        $db->exec('COMMIT');
        return ['status'=>'refunded','changed'=>$changed];
    } catch(Throwable $e) { try{$db->exec('ROLLBACK');}catch(Throwable){} throw $e; }
}

/** The merchant's notice that a paid order was withdrawn by a refund. $order is the state before it. */
function refundNotices(array $order, array $refund): array {
    $merchant=(string)(secrets()['MERCHANT_EMAIL']??'');
    if ($merchant==='') return [];
    if (orderType($order['data'])==='voucher') {
        $codes=array_map(fn($v)=>"{$v['code']}".($v['status']==='used' ? ' (był już wykorzystany)' : ''),vouchersFor($order['external_id']));
        $what="Kody voucherów z tego zamówienia są unieważnione — panel nie pozwoli ich zrealizować."
            .($codes ? "\nKody: ".implode(', ',$codes) : '');
    } else {
        $what="Zamówienie zniknęło z eksportu do Furgonetki. Jeśli paczka została już przygotowana lub nadana"
            .(!empty($order['tracking_number']) ? " (nr przesyłki {$order['tracking_number']})" : '')
            .", zatrzymaj ją albo uzgodnij z kupującym zwrot towaru.";
    }
    return [[$merchant,"Zwrot płatności {$order['number']}",
        "PAYMOVE potwierdził zwrot płatności za zamówienie {$order['number']}. $what\n\n"
        ."ID zwrotu Paymove: {$refund['order_id']} (płatność {$refund['parent_order_id']})\n\n".orderSummary($order),
        (string)($order['data']['email'] ?? '') ?: null]];
}

/** $message is [recipient, subject, body, reply-to]. The caller owns any surrounding transaction. */
function queueMail(SQLite3 $db, string $orderId, array $message, ?int $now = null): void {
    [$to,$subject,$body,$reply]=$message;
    $s=$db->prepare('INSERT INTO mail_outbox(order_external_id,recipient,subject,body,reply_to,created_at) VALUES(:id,:to,:subject,:body,:reply,:now)');
    $s->bindValue(':id',$orderId);$s->bindValue(':to',$to);$s->bindValue(':subject',$subject);$s->bindValue(':body',$body);
    $s->bindValue(':reply',$reply,$reply===null?SQLITE3_NULL:SQLITE3_TEXT);$s->bindValue(':now',$now??time(),SQLITE3_INTEGER);
    $s->execute();
}

/** Messages queued with a paid/review transition: [recipient, subject, body, reply-to]. */
function paymentNotices(array $order, string $target, array $event): array {
    $s=secrets(); $merchant=(string)($s['MERCHANT_EMAIL']??''); $d=$order['data'];
    $shop=cfg()['shop']; $seller=(string)($shop['seller']??$shop['name']??'');
    $summary=(paymoveHosts()['sandbox'] ? "[SANDBOX — bez rzeczywistej płatności]\n\n" : '').orderSummary($order);
    $reportedId=trim($event['orderId']??'');
    $payment="ID płatności Paymove: ".($target==='review' ? ($reportedId ?: 'brak') : ($order['paymove_order_id'] ?: 'brak'));
    if ($target==='review' && !empty($order['paymove_order_id']) && $order['paymove_order_id']!==$reportedId) {
        $payment.="\nPoprzednio powiązane ID Paymove: ".$order['paymove_order_id'];
    }
    $voucher=orderType($d)==='voucher';
    if ($target!=='paid') {
        if ($merchant==='') return [];
        $reported=is_scalar($event['price']??null) ? (string)$event['price'] : 'brak';
        return [[$merchant,"Weryfikacja płatności {$order['number']}",
            "Kwota lub identyfikator płatności nie zgadza się z zamówieniem. "
            .($voucher ? 'Kody voucherów nie zostały wydane — nie wystawiaj vouchera' : 'Nie wysyłaj towaru')
            ." przed sprawdzeniem w panelu operatora.\n\n"
            ."Paymove zgłosił: $reported gr, zamówienie: {$order['total']} gr. $payment\n\n$summary",null]];
    }
    $first=explode(' ',(string)($d['name']??''))[0];
    if ($voucher) return voucherNotices($order,$first,$summary,$payment,$merchant,$seller);
    $eta=$d['snapshot']['delivery_eta'] ?? null; // Promise made at purchase time, never today's config.
    $demoBox=($d['snapshot']['demo'] ?? false) === true;
    $intro=$demoBox ? "To zamówienie demonstracyjne. Nie pobrano prawdziwej płatności i nie wyślemy produktu." :
        "Płatność dotarła, a my szykujemy Twoją paczkę.".(is_string($eta) && $eta!=='' ? " Wysyłamy w ciągu $eta." : '');
    $messages=[[(string)$d['email'],"Dziękujemy za zamówienie {$order['number']}",
        "Cześć $first,\n\ndziękujemy! $intro"
        ."\n\n$summary\n\nPytania? Po prostu odpowiedz na tę wiadomość.".($seller!=='' ? "\n\n$seller" : ''),
        $merchant!=='' ? $merchant : null]];
    if ($merchant!=='') $messages[]=[$merchant,"Nowe opłacone zamówienie {$order['number']} — ".formatPln((int)$order['total']),
        "Opłacone zamówienie".($order['payment_method'] ? " ({$order['payment_method']})" : '').".\n\n$summary\n\n$payment",
        (string)$d['email']];
    return $messages;
}

/** Booking uses the buyer address; each voucher line also carries its own number and validity. */
function voucherNotices(array $order,string $first,string $summary,string $payment,string $merchant,string $seller): array {
    $q=$order['data']['snapshot']; $codes=vouchersFor($order['external_id']);
    $list=implode("\n",array_map(fn($v,$i)=>"Voucher ".($i+1)." · nr {$v['code']} · ważny do ".formatDate((int)$v['expires_at']),$codes,array_keys($codes)));
    $messages=[[(string)$order['data']['email'],(count($codes)>1 ? 'Twoje vouchery' : 'Twój voucher')." — {$q['name']}",
        voucherBuyerMail($order,$first,$list).(($q['invoice_request_text'] ?? '')!=='' ? "\n\n".$q['invoice_request_text'] : '')."\n\n$summary\n\nPytania? Po prostu odpowiedz na tę wiadomość.".($seller!=='' ? "\n\n$seller" : ''),
        $merchant!=='' ? $merchant : null]];
    if ($merchant!=='') $messages[]=[$merchant,"Nowy opłacony voucher {$order['number']} — ".formatPln((int)$order['total']),
        "Opłacone zamówienie".($order['payment_method'] ? " ({$order['payment_method']})" : '').".\n\nE-mail kupującego: {$order['data']['email']}\nVouchery:\n$list\n\n$summary\n\n$payment",
        (string)$order['data']['email']];
    return $messages;
}

/** Paid in the PAYMOVE sandbox, as recorded when the order was created. Older orders have no record. */
function testPayment(array $data): bool {
    return ($data['snapshot']['test_payment'] ?? false) === true;
}

function voucherBuyerMail(array $order,string $first,string $list): string {
    $demo=($order['data']['snapshot']['demo'] ?? false) === true;
    $test=!$demo && testPayment($order['data']);
    $intro=$demo ? 'To voucher demonstracyjny. Płatność była testowa. '
        : ($test ? 'To voucher z płatności testowej — nie uprawnia do usługi. ' : 'dziękujemy! Płatność dotarła. ');
    return "Cześć $first,\n\n$intro\nE-mail kupującego do rezerwacji: {$order['data']['email']}\n\n$list\n\n"
        ."Voucher do wydruku lub wysłania dalej:\n".voucherPageUrl($order)."\n\n"
        .($demo || $test ? "To wyłącznie przykład sposobu działania vouchera." : "Przy umawianiu terminu podaj adres e-mail kupującego.")
        .((cfg()['voucher_contact'] ?? '')!=='' ? "\n".cfg()['voucher_contact'] : '');
}

function processMailQueue(?callable $sender=null,int $limit=20): array {
    $limit=max(1,min(100,$limit)); $sender ??= 'sendMail';
    $counts=['sent'=>0,'failed'=>0,'claimed'=>0]; $db=db();
    for($i=0;$i<$limit;$i++) {
        $now=time(); $token=bin2hex(random_bytes(16));
        $db->exec('BEGIN IMMEDIATE');
        try {
            $pick=$db->prepare('SELECT id FROM mail_outbox WHERE (status="pending" AND next_attempt_at<=:now) OR (status="sending" AND lease_until<=:now) ORDER BY id LIMIT 1'); $pick->bindValue(':now',$now,SQLITE3_INTEGER); $row=$pick->execute()->fetchArray(SQLITE3_ASSOC);
            if(!$row){$db->exec('COMMIT');break;}
            $claim=$db->prepare('UPDATE mail_outbox SET status="sending",lease_token=:token,lease_until=:until,attempts=attempts+1 WHERE id=:id AND ((status="pending" AND next_attempt_at<=:now) OR (status="sending" AND lease_until<=:now))'); $claim->bindValue(':token',$token);$claim->bindValue(':until',$now+120,SQLITE3_INTEGER);$claim->bindValue(':id',(int)$row['id'],SQLITE3_INTEGER);$claim->bindValue(':now',$now,SQLITE3_INTEGER);$claim->execute();
            if($db->changes()!==1){$db->exec('COMMIT');continue;}
            $get=$db->prepare('SELECT * FROM mail_outbox WHERE id=:id AND lease_token=:token');$get->bindValue(':id',(int)$row['id'],SQLITE3_INTEGER);$get->bindValue(':token',$token);$msg=$get->execute()->fetchArray(SQLITE3_ASSOC);$db->exec('COMMIT');
        } catch(Throwable $e){try{$db->exec('ROLLBACK');}catch(Throwable){} throw $e;}
        $counts['claimed']++;
        try {
            $order = findOrder($msg['order_external_id']) ?? throw new RuntimeException('Missing mail order');
            $accepted = $sender($msg['recipient'], $msg['subject'], $msg['body'], $msg['reply_to'], orderType($order['data']));
            if ($accepted !== true) throw new RuntimeException('mail not accepted');
            $upd=$db->prepare('UPDATE mail_outbox SET status="sent",sent_at=:now,lease_token=NULL,lease_until=NULL,last_error=NULL WHERE id=:id AND status="sending" AND lease_token=:token');$upd->bindValue(':now',time(),SQLITE3_INTEGER);$upd->bindValue(':id',(int)$msg['id'],SQLITE3_INTEGER);$upd->bindValue(':token',$token);$upd->execute(); if($db->changes()===1)$counts['sent']++;
        } catch(Throwable $e) {
            $attempt=(int)$msg['attempts']; $delay=min(86400,30*(2 ** min(11,max(0,$attempt-1))));
            $upd=$db->prepare('UPDATE mail_outbox SET status="pending",next_attempt_at=:next,lease_token=NULL,lease_until=NULL,last_error=:err WHERE id=:id AND status="sending" AND lease_token=:token');$upd->bindValue(':next',time()+$delay,SQLITE3_INTEGER);$upd->bindValue(':err','sender failed');$upd->bindValue(':id',(int)$msg['id'],SQLITE3_INTEGER);$upd->bindValue(':token',$token);$upd->execute(); if($db->changes()===1)$counts['failed']++;
        }
    }
    return $counts;
}
