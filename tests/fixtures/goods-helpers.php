<?php
// Legacy cross-edition algorithms. Loaded only in a temporary test copy, never by public runtime.
// Furgonetka needs street, postcode and city separately on the invoice.
function parseCompanyAddress(string $address): ?array {
    if (!preg_match('/^(.+?),?\s+(\d{2}-\d{3})\s+(.+)$/u', $address, $m)) return null;
    return ['street' => $m[1], 'postcode' => $m[2], 'city' => $m[3]];
}

function validNip(string $nip): bool {
    if (!preg_match('/^\d{10}$/', $nip)) return false;
    $w = [6, 5, 7, 2, 3, 4, 5, 6, 7];
    $sum = 0;
    for ($i = 0; $i < 9; $i++) $sum += $w[$i] * (int)$nip[$i];
    return $sum % 11 === (int)$nip[9];
}

// The point's address from InPost's public points API (Furgonetka requires an address on every order).
// false = no such point; null = InPost unreachable, so the order is not blocked.
function inpostPoint(string $code): array|false|null {
    $ch = curl_init('https://api-shipx-pl.easypack24.net/v1/points/' . rawurlencode($code));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5]);
    $raw = curl_exec($ch);
    if ($raw === false) return null;
    $p = json_decode($raw, true);
    if (($p['key'] ?? '') === 'point_not_found') return false;
    $a = $p['address_details'] ?? null;
    if (!is_array($a) || empty($a['city'])) return null;
    return [
        'street'   => trim(($a['street'] ?? '') . ' ' . ($a['building_number'] ?? '')),
        'postcode' => (string)($a['post_code'] ?? ''),
        'city'     => (string)$a['city'],
    ];
}

// Phone, delivery method, Paczkomat and address: only a shipped product needs them.
function validateShipping(array $in, array $c, array &$o, array &$e): void {
    $phone = preg_replace('/[\s\-()]/', '', clean($in['phone'] ?? '', 30));
    $phone = preg_replace('/^(\+48|0048)/', '', $phone);
    $o['phone'] = $phone;
    if (!preg_match('/^\d{9}$/', $phone)) $e['phone'] = 'Podaj 9-cyfrowy numer telefonu.';

    $o['delivery'] = is_string($in['delivery'] ?? null) && isset($c['delivery'][$in['delivery']]) ? $in['delivery'] : '';
    if ($o['delivery'] === '') $e['delivery'] = 'Wybierz formę dostawy.';

    if ($o['delivery'] === 'paczkomat') {
        $o['point'] = strtoupper(clean($in['point'] ?? '', 20));
        $o['point_label'] = clean($in['point_label'] ?? '', 160);
        if (!preg_match('/^[A-Z0-9-]{4,20}$/', $o['point'])) {
            $e['point'] = 'Wybierz Paczkomat.';
        } else {
            $p = inpostPoint($o['point']);
            if ($p === false) $e['point'] = 'Nie ma Paczkomatu o tym kodzie.';
            if (is_array($p)) {
                $o['point_address'] = $p;
                $o['point_label'] = "{$p['street']}, {$p['postcode']} {$p['city']}";
            }
        }
    }
    // The recipient's address is required for both delivery methods: the carrier needs it on every label.
    $o['street'] = clean($in['street'] ?? '', 120);
    $o['postcode'] = clean($in['postcode'] ?? '', 6);
    $o['city'] = clean($in['city'] ?? '', 60);
    if (mb_strlen($o['street']) < 3 || !preg_match('/\d/', $o['street'])) $e['street'] = 'Podaj ulicę i numer.';
    if (!preg_match('/^\d{2}-\d{3}$/', $o['postcode'])) $e['postcode'] = 'Kod w formacie 00-000.';
    if (mb_strlen($o['city']) < 2) $e['city'] = 'Podaj miejscowość.';
}

function furgonetkaOrder(array $row): array {
    $d=$row['data'];$snapshot=$d['snapshot']??null;
    if (!is_array($snapshot)) throw new LegacyOrderException('Legacy order needs historical reconciliation');
    $tz=new DateTimeZone('Europe/Warsaw');
    $iso=static fn($ts)=>(new DateTimeImmutable('@'.(int)$ts))->setTimezone($tz)->format('Y-m-d\TH:i:s');
    [$first,$last]=array_pad(explode(' ',$d['name'],2),2,'');
    $invoice=null;
    if ($d['invoice']) {
        $address=parseCompanyAddress($d['company_address']) ?? ['street'=>$d['company_address'],'postcode'=>'','city'=>''];
        $invoice=['company'=>$d['company'],'nip'=>$d['nip'],...$address,
            'countryCode'=>'PL','phone'=>$d['phone'],'email'=>$d['email']];
    }
    return [
        'sourceOrderId'=>$row['number'], 'datetimeOrder'=>$iso($row['created_at']),
        'sourceDatetimeChange'=>$iso(max((int)$row['paid_at'],(int)($row['tracking_at']??0))),
        'service'=>$d['delivery']==='paczkomat'?'inpost':'inpostkurier',
        'serviceDescription'=>$snapshot['delivery_label'], 'status'=>'paid',
        'totalPrice'=>$row['total']/100,'shippingCost'=>$snapshot['shipping']/100,
        'shippingTaxRate'=>$snapshot['tax_rate']??null,'totalPaid'=>$row['total']/100,'codAmount'=>0,
        'point'=>$d['delivery']==='paczkomat'?$d['point']:null,
        'shippingAddress'=>['name'=>$first,'surname'=>$last,'street'=>$d['street'],
            'postcode'=>$d['postcode'],'city'=>$d['city'],'countryCode'=>'PL','phone'=>$d['phone'],'email'=>$d['email']],
        'invoiceAddress'=>$invoice,
        'products'=>[['sourceProductId'=>$snapshot['product_id'],'name'=>$snapshot['name'],
            'priceGross'=>$snapshot['unit_price']/100,'taxRate'=>$snapshot['tax_rate']??null,'quantity'=>$snapshot['qty'],'unit'=>'szt.']],
        'paymentDatetime'=>$iso($row['paid_at']),
    ];
}
