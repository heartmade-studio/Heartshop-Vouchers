<?php
declare(strict_types=1);

// Keep diagnostics useful without logging response bodies, URLs, API keys or buyer data.
final class PaymoveFailure extends RuntimeException {
    public function __construct(public string $kind, public int $http = 0, public int $curl = 0) {
        parent::__construct('Paymove request failed');
    }
    public function diagnostic(): array { return ['kind'=>$this->kind, 'http'=>$this->http, 'curl'=>$this->curl]; }
}

function paymentScope(): string {
    return hash('sha256', paymoveHosts()['api']."\n".secrets()['PAYMOVE_PRODUCT_ID']);
}

function decodePaymoveResponse(string|false $raw, int $http, int $curl = 0): array {
    if ($raw === false || $curl !== 0) throw new PaymoveFailure('network', $http, $curl);
    if ($http !== 200) throw new PaymoveFailure('http', $http);
    try { $body=json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new PaymoveFailure('json', $http); }
    if (!is_array($body) || array_is_list($body)) throw new PaymoveFailure('json', $http);
    return $body;
}

function decodePaymoveStatusResponse(string|false $raw, int $http, int $curl = 0): array {
    // An API-level 404 means no transaction yet. A proxy/router HTML or text
    // 404 is an outage, not evidence that it is safe to offer payment.
    if ($raw !== false && $curl===0 && $http===404) {
        $body=json_decode($raw,true);
        if (is_array($body) && ($body['status']??null)===404) return ['status'=>'INITIALIZED'];
    }
    return decodePaymoveResponse($raw,$http,$curl);
}

function paymoveRequest(string $url, ?array $payload = null, bool $statusQuery = false): array {
    $ch=curl_init($url);
    $options=[CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20,
        // A provider redirect must never forward the API key to another host.
        CURLOPT_FOLLOWLOCATION=>false];
    if ($payload !== null) $options += [CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'X-API-KEY: '.secrets()['PAYMOVE_API_KEY']],
        CURLOPT_POSTFIELDS=>json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    curl_setopt_array($ch, $options);
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);
    return $statusQuery ? decodePaymoveStatusResponse($raw,$http,$errno) : decodePaymoveResponse($raw,$http,$errno);
}

function paymentStatusUrl(string $url): ?string {
    if (!validPaymentUrl($url)) return null;
    $p=parse_url($url);$parts=explode('/',trim($p['path']??'','/'));
    parse_str($p['query']??'', $query);
    $hash=count($parts)===2 ? $parts[1] : ($query['externalId']??null);
    if (($parts[0]??'') !== secrets()['PAYMOVE_PRODUCT_ID'] || !is_string($hash)
        || !preg_match('/^[A-Za-z0-9]{10}$/D',$hash)) return null;
    return paymoveHosts()['status'].'/api/payment/product/'.rawurlencode($parts[0]).'/subproduct/'.$hash.'/status';
}

function paymovePaymentStatus(string $url): array {
    $endpoint=paymentStatusUrl($url);
    if ($endpoint===null) throw new PaymoveFailure('status_url');
    $body=paymoveRequest($endpoint,null,true);
    $status=$body['status']??null;
    if (!is_string($status) || !in_array($status,
        ['INITIALIZED','PENDING','COMPLETED','CANCELED','ERROR','REFUNDED','WAITING_FOR_EXTERNAL_ACTION'],true)) {
        throw new PaymoveFailure('status');
    }
    return ['status'=>$status,'orderId'=>is_string($body['orderId']??null)?$body['orderId']:null];
}
