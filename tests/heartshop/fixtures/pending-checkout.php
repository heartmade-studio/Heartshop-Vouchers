<?php
// CLI-only bridge for the browser/PHP regression. All credentials and provider
// responses are synthetic; the caller disables curl_exec and mail as a backstop.
declare(strict_types=1);
if (PHP_SAPI!=='cli' || empty(getenv('HEARTSHOP_ENV_FILE'))) exit(2);
$_SERVER['DOCUMENT_ROOT']=$argv[1];
require $argv[1].'/public/heartshop/api/lib.php';
if (secrets()['PAYMOVE_API_KEY']!=='synthetic-only') throw new RuntimeException('Synthetic configuration required');
$private=dirname(privatePath());
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$provider=function(string $id) use($private): string {
    file_put_contents($private.'/provider-calls',$id."\n",FILE_APPEND);
    return 'https://checkout.sandbox.paymove.io/fixture?externalId=Ab12345678';
};
$probe=function() use($private): array {
    file_put_contents($private.'/status-probes',"PENDING\n",FILE_APPEND);
    return ['status'=>'PENDING','orderId'=>'synthetic-pending-transaction'];
};
try {
    switch ($input['action']) {
        case 'checkout':
            $out=prepareCheckout($input['input'],$input['key'],$provider,null,$probe);
            break;
        case 'status':
            $out=['status'=>orderDisplayStatus(findOrder($input['id']))];
            break;
        case 'complete':
            $order=findOrder($input['id']);
            $event=['externalId'=>$input['id'],'status'=>'COMPLETED','price'=>$order['total'],
                'orderId'=>'synthetic-pending-transaction'];
            $raw=json_encode($event,JSON_THROW_ON_ERROR);$stamp=(string)time();
            $signature='t='.$stamp.',v1='.base64_encode(hash_hmac('sha256',$stamp.'.'.$raw,
                secrets()['PAYMOVE_WEBHOOK_SECRET'],true));
            if (!verifySignature($signature,$raw)) throw new RuntimeException('Synthetic signature rejected');
            $out=['first'=>applyPaymentEvent($event),'duplicate'=>applyPaymentEvent($event)];
            break;
        case 'stats':
            $order=findOrder($input['id']);
            $out=['orders'=>(int)db()->querySingle('SELECT COUNT(*) FROM orders'),
                'paid'=>(int)db()->querySingle('SELECT COUNT(*) FROM orders WHERE status="paid"'),
                'successful_transactions'=>(int)db()->querySingle('SELECT COUNT(*) FROM payment_transactions WHERE status="COMPLETED"'),
                'outbox'=>(int)db()->querySingle('SELECT COUNT(*) FROM mail_outbox'),
                'provider_creates'=>is_file($private.'/provider-calls')?count(file($private.'/provider-calls')):0,
                'status_probes'=>is_file($private.'/status-probes')?count(file($private.'/status-probes')):0,
                'payment_url'=>$order['payment_url'],'total'=>(int)$order['total'],
                'observation'=>$order['data']['payment_observation']['status']??null];
            break;
        default:
            throw new InvalidArgumentException('Unknown fixture action');
    }
    echo json_encode(['http'=>200,'body'=>$out],JSON_THROW_ON_ERROR);
} catch(CheckoutProblem $e) {
    echo json_encode(['http'=>$e->httpStatus,'body'=>['code'=>$e->reason,'error'=>$e->getMessage(),
        'orderId'=>$e->orderId]],JSON_THROW_ON_ERROR);
}
