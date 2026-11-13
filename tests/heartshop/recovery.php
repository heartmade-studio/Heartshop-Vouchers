<?php
// Production recovery/state-machine functions, synthetic provider and no network/mail.
declare(strict_types=1);
$root=dirname(__DIR__,2);
$private=(getenv('TMPDIR')?:sys_get_temp_dir()).'/heartshop-recovery-'.bin2hex(random_bytes(6));
mkdir($private,0700,true);
file_put_contents($private.'/.env',"PAYMOVE_API_KEY=synthetic-only\nPAYMOVE_PRODUCT_ID=fixture\nPAYMOVE_WEBHOOK_SECRET=synthetic-only\nMAIL_FROM=shop@example.invalid\nMERCHANT_EMAIL=merchant@example.invalid\nSHOP_ORIGIN=https://shop.example.com\n");
chmod($private.'/.env',0600);putenv('HEARTSHOP_ENV_FILE='.$private.'/.env');
$_SERVER['DOCUMENT_ROOT']=$root;
require $root.'/public/heartshop/api/lib.php';
$checks=0;
function expect(bool $ok,string $label): void {
    global $checks;if (!$ok) throw new RuntimeException('FAIL '.$label);$checks++;
}
function held(callable $run,string $reason='payment_wait'): CheckoutProblem {
    try { $run(); } catch(CheckoutProblem $e) {
        expect($e->reason===$reason,'held: '.$reason);return $e;
    }
    throw new RuntimeException('Expected checkout hold');
}
function failure(callable $run,string $kind): PaymoveFailure {
    try { $run(); } catch(PaymoveFailure $e) { expect($e->kind===$kind,'diagnostic '.$kind);return $e; }
    throw new RuntimeException('Expected provider failure');
}
try {
    $input=['offer'=>'masaz-60','qty'=>1,'email'=>'synthetic@example.invalid','name'=>'Test Buyer',
        'recipient'=>'Test Recipient','dedication'=>'Test','invoice'=>false,'terms'=>true];
    $ready=fn()=>'INITIALIZED';$url='https://pay.sandbox.paymove.io/fixture/Ab12345678';
    $key=str_repeat('a',32);$now=1800000000;$calls=[];$payments=[];
    $provider=function($id,$number,$data,$total) use (&$calls,&$payments,$url) {
        $calls[]=[$id,$number,$data,$total];$payments[$id]??=$url;
        if (count($calls)===1) throw new PaymoveFailure('network',0,28); // created, response lost
        return $payments[$id];
    };
    $error=held(fn()=>prepareCheckout($input,$key,$provider,$now,$ready));$id=$error->orderId;
    expect(findOrder($id)['payment_state']==='uncertain','timeout stored');
    expect(json_decode(findOrder($id)['payment_error'],true)===['kind'=>'network','http'=>0,'curl'=>28],'safe transport diagnosis persisted');
    expect(held(fn()=>prepareCheckout($input,$key,$provider,$now+1,$ready))->retryAfter===2,'retry header reflects remaining cooldown');
    expect(count($calls)===1,'cooldown avoids parallel provider calls');
    held(fn()=>prepareCheckout(array_replace($input,['qty'=>2]),$key,$provider,$now+4,$ready),'attempt_conflict');
    $resumed=prepareCheckout($input,$key,$provider,$now+3,$ready);
    expect($resumed['orderId']===$id && $resumed['redirectUrl']===$url,'lost response recovered');
    expect(count($payments)===1 && count($calls)===2,'same provider resource on replay');
    expect($calls[0]===$calls[1],'same externalId, number, snapshot and amount');
    expect(db()->querySingle('SELECT COUNT(*) FROM orders')===1,'one local order');
    expect(findOrder($id)['payment_error']===null,'successful recovery clears diagnosis');

    foreach (['PENDING','WAITING_FOR_EXTERNAL_ACTION','COMPLETED'] as $status) {
        $r=prepareCheckout($input,$key,$provider,$now+5,fn()=>$status);
        expect($r===['orderId'=>$id,'status'=>'processing'],'no pay link while '.$status);
    }
    expect(findOrder($id)['status']==='pending' && !vouchersFor($id),'unsigned status never fulfils');
    expect(db()->querySingle('SELECT COUNT(*) FROM mail_outbox')===0,'unsigned status never mails');
    prepareCheckout($input,$key,$provider,$now+5,fn()=>['status'=>'PENDING','orderId'=>'active-resumed']);
    applyPaymentEvent(['externalId'=>$id,'status'=>'ERROR','orderId'=>'old-failed']);
    expect(findOrder($id)['status']==='pending' && orderDisplayStatus(findOrder($id))==='pending','late old failure cannot cancel a pending resumed transaction');
    expect(findOrder($id)['data']['payment_observation']['order_id']==='active-resumed','active observation survives old failure');
    expect(!canRecheckPayment(findOrder($id),$now+49),'fresh processing waits for webhook');
    expect(canRecheckPayment(findOrder($id),$now+50),'missing failure webhook permits bounded status recheck');
    held(fn()=>prepareCheckout($input,$key,$provider,$now+6,fn()=>'REFUNDED'),'payment_review');
    held(fn()=>prepareCheckout($input,$key,$provider,$now+6,fn()=>'UNRECOGNIZED'),'payment_review');
    held(fn()=>prepareCheckout($input,$key,$provider,$now+6,function(){throw new PaymoveFailure('http',503);}));
    expect(findOrder($id)['payment_state']==='ready','status outage preserves recovered URL');
    expect(prepareCheckout($input,$key,$provider,$now+7,$ready)['redirectUrl']===$url,'retry after status outage');
    expect(count($calls)===2,'ready retries never create another payment');

    applyPaymentEvent(['externalId'=>$id,'status'=>'CANCELED','orderId'=>'fixture-provider']);
    $processing=fn()=>['status'=>'PENDING','orderId'=>'resumed-provider'];
    expect(prepareCheckout($input,$key,$provider,$now+8,$processing)['status']==='processing','resumed processing does not show canceled');
    expect(findOrder($id)['status']==='canceled' && orderDisplayStatus(findOrder($id))==='pending','unsigned hint hides old cancellation only');
    applyPaymentEvent(['externalId'=>$id,'status'=>'CANCELED','orderId'=>'fixture-provider']);
    expect(orderDisplayStatus(findOrder($id))==='pending','old failed webhook does not erase resumed processing');
    applyPaymentEvent(['externalId'=>$id,'status'=>'ERROR','orderId'=>'resumed-provider']);
    expect(orderDisplayStatus(findOrder($id))==='canceled','new failure clears processing hint');
    $canceled=prepareCheckout($input,$key,$provider,$now+8,fn()=>'CANCELED');
    expect($canceled['orderId']===$id && $canceled['redirectUrl']===$url,'cancellation keeps the same resource');
    expect(count($payments)===1,'cancellation never rotates externalId');
    // Completion racing with the status read wins, including after a cancellation.
    $race=function() use($id) {
        applyPaymentEvent(['externalId'=>$id,'status'=>'COMPLETED','price'=>25000,'orderId'=>'successful-provider']);
        return 'INITIALIZED';
    };
    expect(prepareCheckout($input,$key,$provider,$now+9,$race)===['orderId'=>$id,'status'=>'paid'],'late webhook wins over status');
    expect(count(vouchersFor($id))===1,'late completion issues exactly one voucher');
    expect(findOrder($id)['paymove_order_id']==='successful-provider','successful transaction bound after failed ones');
    expect(prepareCheckout($input,$key,$provider,$now+10,fn()=>'INITIALIZED')['status']==='paid','paid retry never opens checkout');

    // A process died with payment_state=creating. Only one recovery owns the lease.
    $key2=str_repeat('b',32);$nestedCalls=0;
    $first=prepareCheckout($input,$key2,fn()=>$url,$now,$ready);$id2=$first['orderId'];
    db()->exec("UPDATE orders SET payment_state='creating',payment_url=NULL WHERE external_id='$id2'");
    expect(held(fn()=>prepareCheckout($input,$key2,fn()=>$url,$now+29,$ready))->retryAfter===1,'retry header reflects creation lease');
    $reentrant=function() use($input,$key2,$now,$ready,$url,&$nestedCalls) {
        $nestedCalls++;
        held(fn()=>prepareCheckout($input,$key2,function(){throw new RuntimeException('Must not create concurrently');},$now+31,$ready));
        return $url;
    };
    expect(prepareCheckout($input,$key2,$reentrant,$now+30,$ready)['orderId']===$id2,'stale creation reclaimed');
    expect($nestedCalls===1,'no overlapping creation and no DB lock during provider request');

    // Never send an old payment to a different provider product or environment.
    db()->exec("UPDATE orders SET data=json_set(data,'$.snapshot.payment_scope','different-account'),payment_state='uncertain' WHERE external_id='$id2'");
    held(fn()=>prepareCheckout($input,$key2,$reentrant,$now+40,$ready),'payment_review');
    expect($nestedCalls===1,'configuration drift blocks provider call');
    db()->exec("UPDATE orders SET data=json_remove(data,'$.snapshot.payment_scope') WHERE external_id='$id2'");
    expect(prepareCheckout($input,$key2,fn()=>$url,$now+40,$ready)['orderId']===$id2,'legacy sandbox demo recovers');
    db()->exec("UPDATE orders SET data=json_remove(json_set(data,'$.snapshot.demo',json('false')),'$.snapshot.payment_scope'),payment_state='uncertain' WHERE external_id='$id2'");
    held(fn()=>prepareCheckout($input,$key2,$reentrant,$now+50,$ready),'payment_review');

    // All replay and probe calls have shop-wide caps, in addition to the IP cap.
    $key3=str_repeat('c',32);$third=prepareCheckout($input,$key3,fn()=>$url,$now,$ready);$id3=$third['orderId'];
    $window=intdiv($now,600);
    db()->exec("INSERT OR REPLACE INTO request_limits(bucket,window,hits) VALUES('provider_status',$window,400)");
    $limited=held(fn()=>prepareCheckout($input,$key3,fn()=>$url,$now+3,$ready),'rate_limited');
    expect($limited->httpStatus===429 && $limited->orderId===$id3,'status cap holds same order');
    db()->exec("UPDATE orders SET payment_state='uncertain' WHERE external_id='$id3'");
    db()->exec("INSERT OR REPLACE INTO request_limits(bucket,window,hits) VALUES('provider_recovery',$window,200)");
    held(fn()=>prepareCheckout($input,$key3,function(){throw new RuntimeException('Provider cap bypassed');},$now+4,$ready),'rate_limited');
    expect(findOrder($id3)['payment_state']==='uncertain','cap does not strand a creation lease');
    expect(prepareCheckout($input,$key3,fn()=>$url,$now+600,$ready)['orderId']===$id3,'recovery resumes after cap window');

    $base='https://pay-api.sandbox.paymove.io/api/payment/product/fixture/subproduct/Ab12345678/status';
    expect(paymentStatusUrl($url)===$base,'PAY path hash');
    expect(paymentStatusUrl('https://checkout.sandbox.paymove.io/fixture?externalId=Ab12345678')===$base,'Checkout query hash');
    expect(paymentStatusUrl('https://pay.sandbox.paymove.io/fixture?externalId=Ab12345678')===$base,'PAY query hash');
    foreach (['https://checkout.sandbox.paymove.io/other?externalId=Ab12345678',
        'https://checkout.sandbox.paymove.io/fixture?externalId[]=Ab12345678',
        'https://pay.sandbox.paymove.io/fixture/hs_aaaaaaaaaaaaaaaaaaaaaaaa',
        'https://pay.sandbox.paymove.io.evil.invalid/fixture/Ab12345678'] as $bad) {
        expect(paymentStatusUrl($bad)===null,'wrong scope/shape refused');
    }
    expect(decodePaymoveStatusResponse('{"status":404,"message":"not found"}',404)===['status'=>'INITIALIZED'],'no transaction yet');
    failure(fn()=>decodePaymoveStatusResponse('No route found',404),'http');
    failure(fn()=>decodePaymoveResponse('secret buyer response',500),'http');
    failure(fn()=>decodePaymoveResponse(false,0,28),'network');
    failure(fn()=>decodePaymoveResponse('not JSON',200),'json');
    failure(fn()=>decodePaymoveResponse('[]',200),'json');
    failure(fn()=>decodePaymoveResponse('{}',201),'http');
    expect(decodePaymoveResponse('{"redirectUrl":"test"}',200)['redirectUrl']==='test','valid JSON');
    expect(!str_contains(json_encode((new PaymoveFailure('http',500))->diagnostic()),'secret'),'diagnostics contain no response data');
    echo "recovery: $checks assertions passed\n";
} finally {
    foreach(glob($private.'/*') as $file) unlink($file);
    unlink($private.'/.env');rmdir($private);
}
