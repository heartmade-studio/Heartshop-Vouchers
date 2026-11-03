<?php
declare(strict_types=1);
// Development harness only; outside public/. Never deploy or proxy this server.
if (PHP_SAPI!=='cli-server' || getenv('HEARTSHOP_LOCAL_SIMULATION')!=='1'
    || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)
    || ($_SERVER['HTTP_HOST']??'')!==parse_url(getenv('HEARTSHOP_ORIGIN'),PHP_URL_HOST).':'.parse_url(getenv('HEARTSHOP_ORIGIN'),PHP_URL_PORT)) {
    http_response_code(403);exit('Local simulation only');
}
$root=dirname(__DIR__,2);$web=$root.'/public';
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/');
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
if ($path==='/') { header('Location: /heartshop/');exit; }
if ($path==='/__dev/paymove.js') { header('Content-Type: application/javascript');readfile(__DIR__.'/paymove.js');exit; }
if ($path==='/__dev/settle' || ($path==='/heartshop/api/order.php')) {
    require $web.'/heartshop/api/lib.php';
    try {
        if ($_SERVER['REQUEST_METHOD']==='GET' && $path==='/heartshop/api/order.php') {
            $c=cfg();$offers=[];foreach(array_keys($c['offers']) as $id) $offers[$id]=offer($id);
            jsonOut(['offers'=>(object)$offers,'defaultOffer'=>$c['default_offer'],'product'=>offer(null),
                'delivery'=>$c['delivery'], 'freeShippingFrom'=>$c['free_shipping_from'],
                'invoice'=>$c['invoice'],'sandbox'=>true,'paymoveSdk'=>'/__dev/paymove.js','geowidgetToken'=>'','geowidgetSandbox'=>true]);
        }
        if ($_SERVER['REQUEST_METHOD']!=='POST') jsonOut(['error'=>'Method not allowed'],405);
        if (!sameOrigin()) jsonOut(['error'=>'Invalid origin'],403);
        $raw=file_get_contents('php://input',false,null,0,8193) ?: '';
        if (strlen($raw)>8192) jsonOut(['error'=>'Request too large'],413);
        $in=json_decode($raw,true);
        if (!is_array($in)) jsonOut(['error'=>'Invalid input'],400);
        if ($path==='/__dev/settle') {
            $order=findOrder((string)($in['orderId']??''));
            if (!$order) jsonOut(['error'=>'Unknown order'],404);
            jsonOut(applyPaymentEvent(['externalId'=>$order['external_id'],'orderId'=>'local-'.$order['external_id'],
                'price'=>$order['total'],'status'=>($in['cancel']??false)?'CANCELED':'COMPLETED','paymentMethod'=>'LOCAL_SIMULATION']));
        }
        if (($in['delivery']??'')==='paczkomat') jsonOut(['error'=>'Use courier in the offline simulation'],422);
        jsonOut(prepareCheckout($in,$_SERVER['HTTP_IDEMPOTENCY_KEY']??'',fn($id)=>'https://checkout.sandbox.paymove.io/local/'.$id));
    } catch(CheckoutProblem $e) {
        jsonOut(['error'=>$e->getMessage(),'code'=>$e->reason,'orderId'=>$e->orderId,'errors'=>$e->errors],$e->httpStatus);
    } catch(Throwable $e) { logError($e,'local_simulation');jsonOut(['error'=>'Local simulation failed'],500); }
}
if (str_starts_with($path,'/heartshop/api/')) {
    if (!in_array($path,['/heartshop/api/status.php','/heartshop/api/webhook.php','/heartshop/api/panel.php'],true)) { http_response_code(403);exit; }
    require $web.$path;exit;
}
$file=realpath($web.$path);
if ($file===false || !str_starts_with($file,$web.'/') || preg_match('#(^|/)\.[^/]*#',$path) || str_ends_with($file,'.php')) { http_response_code(404);exit; }
return false;
