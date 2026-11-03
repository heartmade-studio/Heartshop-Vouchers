<?php
declare(strict_types=1);

/** Durable queued messages are at-least-once: a worker can crash after mail() accepts
 * a message but before recording success, so lease expiry may cause a duplicate. */
function reliabilitySchema(SQLite3 $db): void {
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

// Orders created before typed offers have no type: they were all shipped products.
function orderType(array $data): string {
    return $data['snapshot']['type'] ?? 'shipping';
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
        if (!$order || !in_array($order['status'],['pending','canceled'],true) || ($event['status']!=='COMPLETED' && $order['status']!=='pending')) {
            $db->exec('COMMIT'); return ['status'=>$order['status']??'ignored','changed'=>false];
        }
        $status=(string)$order['status']; $target=$status; $paidAt=null; $review=false;
        $incoming=strtoupper($event['status']);
        if ($incoming==='COMPLETED' && !in_array($status,['paid','review'],true)) {
            if (trim($event['orderId']??'')==='' || (ltrim((string)$price,'0') ?: '0') !== (string)$order['total'] || (!empty($order['paymove_order_id']) && ($event['orderId']??null)!==$order['paymove_order_id'])) { $target='review'; $review=true; }
            else { $target='paid'; $paidAt=time(); }
        } elseif (in_array($incoming,['CANCELED','ERROR'],true) && $status==='pending') {
            $target='canceled';
        }
        $stmt=$db->prepare('UPDATE orders SET status=:status, paid_at=CASE WHEN :paid IS NULL THEN paid_at ELSE :paid END, payment_method=COALESCE(:method,payment_method), paymove_order_id=COALESCE(paymove_order_id,:pm) WHERE external_id=:id AND status=:old');
        $stmt->bindValue(':status',$target); $stmt->bindValue(':paid',$paidAt, $paidAt===null?SQLITE3_NULL:SQLITE3_INTEGER); $stmt->bindValue(':method',$event['paymentMethod']??null,isset($event['paymentMethod'])?SQLITE3_TEXT:SQLITE3_NULL); $stmt->bindValue(':pm',$event['orderId']??null,isset($event['orderId'])?SQLITE3_TEXT:SQLITE3_NULL); $stmt->bindValue(':id',$event['externalId']); $stmt->bindValue(':old',$status); $stmt->execute();
        $changed=$db->changes()===1;
        if ($changed && in_array($target,['paid','review'],true) && in_array($status,['pending','canceled'],true)) {
            $updated=findOrder($event['externalId']);
            // Codes exist only for a confirmed payment; a review order gets them after manual reconciliation.
            if ($target==='paid' && orderType($updated['data'])==='voucher') issueVouchers($db,$updated);
            $messages=paymentNotices($updated,$target,$event);
            $ins=$db->prepare('INSERT INTO mail_outbox(order_external_id,recipient,subject,body,reply_to,created_at) VALUES(:id,:to,:subject,:body,:reply,:now)');
            foreach($messages as [$to,$subject,$body,$reply]) { $ins->bindValue(':id',$event['externalId']);$ins->bindValue(':to',$to);$ins->bindValue(':subject',$subject);$ins->bindValue(':body',$body);$ins->bindValue(':reply',$reply,$reply===null?SQLITE3_NULL:SQLITE3_TEXT);$ins->bindValue(':now',time(),SQLITE3_INTEGER);$ins->execute(); }
        }
        $db->exec('COMMIT');
        $result=findOrder($event['externalId']); return ['status'=>$result['status'],'changed'=>$changed];
    } catch(Throwable $e) { try{$db->exec('ROLLBACK');}catch(Throwable){} throw $e; }
}

/** Messages queued with a paid/review transition: [recipient, subject, body, reply-to]. */
function paymentNotices(array $order, string $target, array $event): array {
    $s=secrets(); $merchant=(string)($s['MERCHANT_EMAIL']??''); $d=$order['data'];
    $shop=cfg()['shop']; $seller=(string)($shop['seller']??$shop['name']??'');
    $summary=(paymoveHosts()['sandbox'] ? "[SANDBOX — bez rzeczywistej płatności]\n\n" : '').orderSummary($order);
    $payment="ID płatności Paymove: ".($order['paymove_order_id'] ?: 'brak');
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

/** The buyer's mail carries the codes themselves, so it still works if the printable page does not. */
function voucherNotices(array $order,string $first,string $summary,string $payment,string $merchant,string $seller): array {
    $q=$order['data']['snapshot']; $codes=vouchersFor($order['external_id']);
    $list=implode("\n",array_map(fn($v)=>"  {$v['code']}  ·  ważny do ".formatDate((int)$v['expires_at']),$codes));
    $messages=[[(string)$order['data']['email'],(count($codes)>1 ? 'Twoje vouchery' : 'Twój voucher')." — {$q['name']}",
        voucherBuyerMail($order,$first,$list)."\n\n$summary\n\nPytania? Po prostu odpowiedz na tę wiadomość.".($seller!=='' ? "\n\n$seller" : ''),
        $merchant!=='' ? $merchant : null]];
    if ($merchant!=='') $messages[]=[$merchant,"Nowy opłacony voucher {$order['number']} — ".formatPln((int)$order['total']),
        "Opłacone zamówienie".($order['payment_method'] ? " ({$order['payment_method']})" : '').".\n\nKody:\n$list\n\n$summary\n\n$payment",
        (string)$order['data']['email']];
    return $messages;
}

function voucherBuyerMail(array $order,string $first,string $list): string {
    $demo=($order['data']['snapshot']['demo'] ?? false) === true;
    $intro=$demo ? 'To voucher demonstracyjny — kod nie uprawnia do masażu. Płatność była testowa. ' : 'dziękujemy! Płatność dotarła. ';
    return "Cześć $first,\n\n$intro".(substr_count($list,"\n")>0 ? 'Kody voucherów' : 'Kod vouchera').":\n\n$list\n\n"
        ."Voucher do wydruku lub wysłania dalej:\n".voucherPageUrl($order)."\n\n"
        .($demo ? "To wyłącznie przykład sposobu działania vouchera." : "Przy umawianiu terminu wystarczy podać kod.");
}

function processMailQueue(?callable $sender=null,int $limit=20): array {
    $limit=max(1,min(100,$limit)); $sender ??= static fn(string $to,string $subject,string $body,?string $reply):bool=>sendMail($to,$subject,$body,$reply);
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
        try { $accepted=$sender($msg['recipient'],$msg['subject'],$msg['body'],$msg['reply_to']); if($accepted!==true) throw new RuntimeException('mail not accepted');
            $upd=$db->prepare('UPDATE mail_outbox SET status="sent",sent_at=:now,lease_token=NULL,lease_until=NULL,last_error=NULL WHERE id=:id AND status="sending" AND lease_token=:token');$upd->bindValue(':now',time(),SQLITE3_INTEGER);$upd->bindValue(':id',(int)$msg['id'],SQLITE3_INTEGER);$upd->bindValue(':token',$token);$upd->execute(); if($db->changes()===1)$counts['sent']++;
        } catch(Throwable $e) {
            $attempt=(int)$msg['attempts']; $delay=min(86400,30*(2 ** min(11,max(0,$attempt-1))));
            $upd=$db->prepare('UPDATE mail_outbox SET status="pending",next_attempt_at=:next,lease_token=NULL,lease_until=NULL,last_error=:err WHERE id=:id AND status="sending" AND lease_token=:token');$upd->bindValue(':next',time()+$delay,SQLITE3_INTEGER);$upd->bindValue(':err','sender failed');$upd->bindValue(':id',(int)$msg['id'],SQLITE3_INTEGER);$upd->bindValue(':token',$token);$upd->execute(); if($db->changes()===1)$counts['failed']++;
        }
    }
    return $counts;
}
