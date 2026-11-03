<?php
declare(strict_types=1);
// Isolated executable contract tests; no real mail is possible.
$db = new SQLite3(':memory:'); $db->enableExceptions(true); $db->busyTimeout(100);
function db(): SQLite3 { global $db; return $db; }
function paymoveHosts(): array { return ['sandbox'=>true]; }
function cfg(): array { return ['shop'=>['name'=>'Shop']]; }
function secrets(): array { return ['MERCHANT_EMAIL'=>'merchant@example.test']; }
function orderNumber(int $id): string { return 'T-'.$id; }
function formatPln(int $n): string { return (string)$n; }
function mailsFor(string $id): array { $r=db()->query("SELECT recipient,subject,body,reply_to FROM mail_outbox WHERE order_external_id='$id' ORDER BY id"); $m=[]; while($x=$r->fetchArray(SQLITE3_ASSOC))$m[]=$x; return $m; }
function sendMail(string $to,string $subject,string $body,?string $replyTo=null): bool { throw new RuntimeException('unexpected real mail'); }
function findOrder(string $id): ?array { $s=db()->prepare('SELECT * FROM orders WHERE external_id=:id'); $s->bindValue(':id',$id); $r=$s->execute()->fetchArray(SQLITE3_ASSOC); if(!$r)return null; $r['data']=json_decode($r['data'],true); $r['number']=orderNumber((int)$r['id']); return $r; }
function orderSummary(array $order): string { return 'SUMMARY '.$order['number'].' '.json_encode($order['data']); }
require __DIR__.'/../../public/heartshop/api/reliability.php';
$checks=0;
function ok(bool $v,string $m):void { global $checks; if(!$v) throw new RuntimeException($m); $checks++; }
$db->exec('CREATE TABLE orders(id INTEGER PRIMARY KEY, external_id TEXT UNIQUE, status TEXT, total INTEGER, data TEXT, payment_method TEXT, paymove_order_id TEXT, created_at INTEGER, paid_at INTEGER)');
reliabilitySchema($db);
function seed(string $id='x',string $status='pending',int $total=1200,array $extra=[]):void { $s=db()->prepare('INSERT INTO orders(external_id,status,total,data,created_at) VALUES(:x,:s,:t,:d,1)'); $s->bindValue(':x',$id);$s->bindValue(':s',$status);$s->bindValue(':t',$total);$s->bindValue(':d',json_encode(['email'=>'buyer@example.test','name'=>'Buyer','qty'=>1,'delivery'=>'courier','phone'=>'1','invoice'=>false]+$extra));$s->execute(); }
function event(string $id,string $status,int $amount):array{return ['externalId'=>$id,'status'=>$status,'price'=>$amount,'paymentMethod'=>'card','orderId'=>'pm-1'];}
seed();
$r=applyPaymentEvent(event('x','COMPLETED',1200)); ok(($r['status']??'')==='paid','paid transition'); ok((int)$db->querySingle('SELECT COUNT(*) FROM mail_outbox')===2,'paid queues both recipients');
applyPaymentEvent(event('x','COMPLETED',1200)); ok((int)$db->querySingle('SELECT COUNT(*) FROM mail_outbox')===2,'duplicate paid no notices');
seed('bad'); $r=applyPaymentEvent(event('bad','COMPLETED',999)); ok(($r['status']??'')==='review','amount mismatch review'); ok((int)$db->querySingle("SELECT COUNT(*) FROM mail_outbox WHERE order_external_id='bad'")==1,'review merchant-only');
seed('paid','paid'); applyPaymentEvent(event('paid','CANCELED',1200)); ok($db->querySingle("SELECT status FROM orders WHERE external_id='paid'")==='paid','paid cannot regress');
$db->exec('DELETE FROM mail_outbox');
seed('retry'); applyPaymentEvent(event('retry','COMPLETED',1200)); $sent=[]; $sender=function($to,$sub,$body)use(&$sent){$sent[]=$to;return count($sent)>1;};
$a=processMailQueue($sender,1); ok(($a['failed']??0)===1,'failed sender recorded');
$db->exec("UPDATE mail_outbox SET next_attempt_at=0"); $b=processMailQueue($sender,1); ok(($b['sent']??0)===1,'retry succeeds'); $db->exec("UPDATE mail_outbox SET next_attempt_at=0"); processMailQueue(function(){return true;},1); ok((int)$db->querySingle("SELECT COUNT(*) FROM mail_outbox WHERE order_external_id='retry' AND status='sent'")===2,'both eventually sent');
seed('lease'); applyPaymentEvent(event('lease','COMPLETED',1200)); $db->exec("UPDATE mail_outbox SET next_attempt_at=0 WHERE order_external_id='lease'");
$one=processMailQueue(function(){return true;},1); ok(($one['sent']??0)===1,'first lease worker sends one');
$two=processMailQueue(function(){return true;},20); ok(($two['sent']??0)===1,'concurrent lease excludes claimed item');
seed('rollback'); $db->exec("CREATE TRIGGER fail_enqueue BEFORE INSERT ON mail_outbox WHEN NEW.order_external_id='rollback' BEGIN SELECT RAISE(ABORT,'blocked'); END");
try { applyPaymentEvent(event('rollback','COMPLETED',1200)); throw new RuntimeException('expected rollback failure'); } catch (Exception $e) {}
ok($db->querySingle("SELECT status FROM orders WHERE external_id='rollback'")==='pending','enqueue failure rolls status back');
ok((int)$db->querySingle("SELECT COUNT(*) FROM mail_outbox WHERE order_external_id='rollback'")===0,'rollback leaves no mail');
try { applyPaymentEvent(['externalId'=>[], 'status'=>'COMPLETED','amount'=>1200]); throw new RuntimeException('bad types accepted'); } catch (InvalidArgumentException $e) {}
seed('large-price');$large=event('large-price','COMPLETED',1200);$large['price']='999999999999';
ok(applyPaymentEvent($large)['status']==='review','maximum accepted decimal price cannot match normal total');
seed('leading-zero');$zero=event('leading-zero','COMPLETED',1200);$zero['price']='000000001200';
ok(applyPaymentEvent($zero)['status']==='paid','decimal strings compare canonically');
seed('missing-id');$missing=event('missing-id','COMPLETED',1200);unset($missing['orderId']);
ok(applyPaymentEvent($missing)['status']==='review','missing provider ID held for review');
seed('late'); applyPaymentEvent(['externalId'=>'late','status'=>'ERROR']);
ok(findOrder('late')['status']==='canceled','ERROR cancels pending without price');
$late=event('late','COMPLETED',1200);$late['price']='1200';applyPaymentEvent($late);
ok(findOrder('late')['status']==='paid','late signed completion after cancellation is preserved');
$before=findOrder('late');$duplicate=event('late','CANCELED',0);$duplicate['orderId']='different';applyPaymentEvent($duplicate);
ok(findOrder('late')===$before,'terminal metadata immutable');
seed('identity');applyPaymentEvent(event('identity','CANCELED',1200));
$other=event('identity','COMPLETED',1200);$other['orderId']='pm-other';applyPaymentEvent($other);
ok(findOrder('identity')['status']==='review','different bound payment held for review');
ok(applyPaymentEvent(event('unknown','COMPLETED',1200))['status']==='ignored','unknown signed event ignored');
foreach ([true,1200.5,-1,'1e3','9999999999999','9223372036854775808',[],null] as $invalid) {
    $bad=event('x','COMPLETED',1200);$bad['price']=$invalid;
    try { applyPaymentEvent($bad);throw new RuntimeException('invalid price accepted'); } catch (InvalidArgumentException) { ok(true,'invalid price rejected'); }
}
$db->exec('DELETE FROM mail_outbox');seed('overlap');applyPaymentEvent(event('overlap','COMPLETED',1200));
$nested=null;
$outer=processMailQueue(function()use(&$nested){$nested=processMailQueue(fn()=>true,20);return true;},1);
ok($outer['sent']===1 && $nested['sent']===1,'overlapping worker excludes active lease');
$db->exec("UPDATE mail_outbox SET status='sending',lease_until=1,lease_token='old' WHERE id=(SELECT MIN(id) FROM mail_outbox)");
ok(processMailQueue(fn()=>true,1)['sent']===1,'expired crash lease recoverable');
seed('content','pending',1200,['snapshot'=>['delivery_eta'=>'2 dni robocze']]);applyPaymentEvent(event('content','COMPLETED',1200));
[$buyer,$seller]=mailsFor('content');
ok(str_contains($buyer['body'],'Wysyłamy w ciągu 2 dni robocze.'),'buyer mail keeps the dispatch time promised at purchase');
ok(str_contains($buyer['body'],'odpowiedz na tę wiadomość') && str_contains($buyer['body'],'Shop'),'buyer mail invites a reply and is signed');
ok($buyer['reply_to']==='merchant@example.test' && $seller['reply_to']==='buyer@example.test','replies reach the other party');
ok(str_contains($seller['body'],'(card)') && str_contains($seller['body'],'pm-1') && str_contains($seller['subject'],'1200'),'merchant mail names method, payment ID and amount');
seed('no-eta');applyPaymentEvent(event('no-eta','COMPLETED',1200));
ok(!str_contains(mailsFor('no-eta')[0]['body'],'Wysyłamy'),'no snapshot ETA means no dispatch promise');
seed('mismatch');applyPaymentEvent(event('mismatch','COMPLETED',999));$review=mailsFor('mismatch');
ok(count($review)===1 && str_contains($review[0]['body'],'999 gr') && str_contains($review[0]['body'],'1200 gr') && str_contains($review[0]['body'],'Nie wysyłaj'),'review mail shows reported and expected amounts');
echo "outbox: $checks assertions passed\n";
