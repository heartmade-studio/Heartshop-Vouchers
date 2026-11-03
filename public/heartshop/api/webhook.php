<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Contract remains Paymove's existing requestTemplate: externalId, orderId,
// price (integer grosze), status, paymentMethod. No real provider configuration changes.
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonOut(['error'=>'Method not allowed'],405);
    $raw=file_get_contents('php://input',false,null,0,65537) ?: '';
    if (strlen($raw)>65536) jsonOut(['error'=>'Request too large'],413);
    if (!verifySignature($_SERVER['HTTP_X_PAYMOVE_SIGNATURE'] ?? '',$raw)) jsonOut(['error'=>'Invalid signature'],401);
    $event=json_decode($raw,true);
    if (!is_array($event)) jsonOut(['error'=>'Invalid event'],400);
    jsonOut(applyPaymentEvent($event)); // 200 only after durable status AND outbox commit.
} catch (InvalidArgumentException $e) {
    jsonOut(['error'=>'Invalid event'],400);
} catch (Throwable $e) {
    logError($e,'webhook');
    jsonOut(['error'=>'Temporary failure'],500);
}
