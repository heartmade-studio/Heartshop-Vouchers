<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Contract remains Paymove's existing requestTemplate: externalId, orderId,
// price (integer grosze), status, paymentMethod. No real provider configuration changes.
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['error'=>'Method not allowed'],405);
    $raw=file_get_contents('php://input',false,null,0,65537) ?: '';
    if (strlen($raw)>65536) jsonOut(['error'=>'Request too large'],413);
    $signature=$_SERVER['HTTP_X_PAYMOVE_SIGNATURE'] ?? '';
    $transaction=verifySignature($signature,$raw,'PAYMOVE_TRANSACTION_SECRET');
    if (!$transaction && !verifySignature($signature,$raw)) jsonOut(['error'=>'Invalid signature'],401);
    $event=json_decode($raw,true);
    if (!is_array($event)) jsonOut(['error'=>'Invalid event'],400);
    if ($transaction) {
        // One contract: signed PLN PAYMENT and REFUND transactions only.
        if (!in_array($event['type'] ?? '',['PAYMENT','REFUND'],true) || ($event['currency'] ?? '') !== 'PLN') {
            jsonOut(['error'=>'Invalid payment'],400);
        }
        if ($event['type']==='REFUND') jsonOut(applyRefundEvent($event));
        $event['price']=$event['amountMinor'] ?? null;
        if (($event['externalId'] ?? '') === '') {
            if (($event['status'] ?? '') !== 'COMPLETED') jsonOut(['status'=>'ignored']);
            recordSignedCompletion($event);
            jsonOut(['status'=>'received']);
        }
    }
    jsonOut(applyPaymentEvent($event)); // 200 only after durable status AND outbox commit.
} catch (InvalidArgumentException $e) {
    jsonOut(['error'=>'Invalid event'],400);
} catch (Throwable $e) {
    logError($e,'webhook');
    jsonOut(['error'=>'Temporary failure'],500);
}
