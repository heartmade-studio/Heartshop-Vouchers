<?php
declare(strict_types=1);

ini_set('display_errors', '0');
umask(0077); // New private SQLite/WAL files are owner-only.

function cfg(): array {
    static $cfg = null;
    $cfg ??= require __DIR__ . '/config.php';
    if (($cfg['product_type'] ?? null) === 'voucher' && $cfg['invoice'] !== false) {
        throw new RuntimeException('Voucher invoice collection is not implemented');
    }
    validateLayout($cfg['shop']);
    return $cfg;
}

// The URL layout is deployment configuration: one base segment, explicit HTTPS origins, no wildcards.
function validateLayout(array $shop): void {
    if (!is_string($shop['base'] ?? null) || !preg_match('#^/[a-z0-9]+(?:-[a-z0-9]+)*$#D', $shop['base'])) {
        throw new RuntimeException('Shop base must be one lowercase path segment, e.g. /heartshop');
    }
    if (!in_array($shop['mount'] ?? 'root', ['root', 'subdirectory'], true)) throw new RuntimeException('Invalid shop mount');
    $panel = $shop['panel_origins'] ?? [];
    if (!is_array($panel) || !array_is_list($panel)) throw new RuntimeException('Invalid panel origins');
    foreach ($panel as $origin) {
        if (!is_string($origin) || !preg_match('#^https://[a-z0-9]+(?:[.-][a-z0-9]+)*(?::[0-9]+)?$#D', $origin)
            || in_array($origin, $shop['origins'], true)) {
            throw new RuntimeException('Panel origins must be explicit HTTPS origins, distinct from shop origins');
        }
    }
}

// True when the seller panel has its own host and document root (see release.php).
function panelSeparate(): bool {
    return (cfg()['shop']['panel_origins'] ?? []) !== [];
}

// The supplied invoice instruction is a sample: real buyers must only receive the seller's approved text.
function invoiceTextNeedsApproval(array $c): bool {
    if (($c['product_type'] ?? null) !== 'voucher') return false;
    $text = trim((string)($c['invoice_request_text'] ?? ''));
    return $text === '' || str_contains($text, 'przykładowa instrukcja');
}

// An offer from config by id, or null; a missing id means the shop's default offer.
function offer(mixed $id, ?array $config = null): ?array {
    $c = $config ?? cfg();
    $id ??= $c['default_offer'];
    if (!is_string($id) && !is_int($id)) return null;
    $offer = $c['offers'][(string)$id] ?? null;
    if (isset($c['product_type']) && ($offer['type'] ?? null) !== $c['product_type']) return null;
    return is_array($offer) ? $offer + ['id' => (string)$id] : null;
}

function privateConfigPath(string $path, string $root): string {
    $real=realpath($path);$web=realpath($root);
    if ($real===false || $web===false || !is_file($real) || !is_readable($real)
        || $real===$web || str_starts_with($real,$web.DIRECTORY_SEPARATOR)) {
        throw new InvalidArgumentException('Unsafe private configuration');
    }
    if ((fileperms($real) & 0077)!==0 || (fileperms(dirname($real)) & 0077)!==0) {
        throw new InvalidArgumentException('Private configuration permissions required');
    }
    return $real;
}
function privatePath(): string {
    return privateConfigPath(cfg()['env_file'], ($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__,2));
}

// Secrets from the private .env (outside the web root).
function secrets(): array {
    static $env = null;
    if ($env !== null) return $env;
    $values = @parse_ini_file(privatePath(), false, INI_SCANNER_RAW);
    if (!is_array($values) || empty($values['PAYMOVE_API_KEY']) || empty($values['PAYMOVE_PRODUCT_ID'])
        || empty($values['PAYMOVE_WEBHOOK_SECRET'])) {
        throw new RuntimeException('Missing private configuration');
    }
    foreach (['MAIL_FROM','MERCHANT_EMAIL'] as $key) {
        if (!filter_var($values[$key] ?? '', FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $values[$key])) {
            throw new RuntimeException('Missing mail configuration');
        }
    }
    if (!in_array($values['PAYMOVE_ENV'] ?? 'sandbox',['sandbox','production'],true)) throw new RuntimeException('Invalid payment environment');
    if (!in_array($values['SHOP_ORIGIN'] ?? '',cfg()['shop']['origins'],true)) throw new RuntimeException('Invalid shop origin');
    $panel = $values['PANEL_ORIGIN'] ?? '';
    if (panelSeparate() ? !in_array($panel, cfg()['shop']['panel_origins'], true) : $panel !== '') {
        throw new RuntimeException('Invalid panel origin');
    }
    return $env = $values;
}

function paymoveHosts(): array {
    $sandbox = (secrets()['PAYMOVE_ENV'] ?? 'sandbox') !== 'production';
    return $sandbox
        ? ['api' => 'https://gateway-api.sandbox.paymove.io', 'checkout' => 'checkout.sandbox.paymove.io', 'sandbox' => true]
        : ['api' => 'https://api.paymove.io', 'checkout' => 'checkout.paymove.io', 'sandbox' => false];
}

function db(): SQLite3 {
    static $db=null;
    if ($db) return $db;
    secrets(); // Fail closed before opening storage.
    $path=dirname(privatePath()).'/orders.sqlite';
    foreach ([$path,$path.'-wal',$path.'-shm'] as $file) {
        if (is_link($file) || (file_exists($file) && (!is_file($file) || (fileperms($file)&0077)!==0))) {
            throw new RuntimeException('Unsafe database permissions');
        }
    }
    $conn=new SQLite3($path);$conn->enableExceptions(true);$conn->busyTimeout(5000);
    $conn->exec('PRAGMA journal_mode=WAL');
    $conn->exec('BEGIN IMMEDIATE');
    try {
        $conn->exec('CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT, external_id TEXT UNIQUE NOT NULL,
            status TEXT NOT NULL DEFAULT "pending", total INTEGER NOT NULL, data TEXT NOT NULL,
            payment_method TEXT, paymove_order_id TEXT, created_at INTEGER NOT NULL, paid_at INTEGER)');
        $cols=[];$res=$conn->query('PRAGMA table_info(orders)');
        while ($col=$res->fetchArray(SQLITE3_ASSOC)) $cols[]=$col['name'];
        foreach (['tracking_number TEXT','courier_service TEXT','tracking_at INTEGER',
            'idempotency_key TEXT','request_hash TEXT','payment_state TEXT','payment_url TEXT',
            // NULL offer_type = an order from before typed offers, i.e. shipping.
            'offer_type TEXT','note TEXT','done_at INTEGER','invoice_done_at INTEGER'] as $def) {
            if (!in_array(strtok($def,' '),$cols,true)) $conn->exec("ALTER TABLE orders ADD COLUMN $def");
        }
        $conn->exec('CREATE UNIQUE INDEX IF NOT EXISTS orders_attempt_key ON orders(idempotency_key)');
        $conn->exec('CREATE TABLE IF NOT EXISTS request_limits (bucket TEXT NOT NULL,window INTEGER NOT NULL,hits INTEGER NOT NULL,PRIMARY KEY(bucket,window))');
        reliabilitySchema($conn);
        voucherSchema($conn);
        panelSchema($conn);
        $conn->exec('COMMIT');
    } catch(Throwable $e) { $conn->exec('ROLLBACK');$conn->close();throw $e; }
    return $db=$conn;
}

function findOrder(string $externalId): ?array {
    $stmt = db()->prepare('SELECT * FROM orders WHERE external_id = :id');
    $stmt->bindValue(':id', $externalId);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$row) return null;
    $row['data'] = json_decode($row['data'], true);
    $row['number'] = orderNumber((int)$row['id']);
    return $row;
}

function orderNumber(int $id): string {
    return cfg()['shop']['order_prefix'] . '-' . (1000 + $id);
}

function jsonOut(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function formatPln(int $grosze): string {
    return number_format($grosze / 100, 2, ',', "\u{00A0}") . "\u{00A0}zł";
}

function clean(mixed $value, int $max = 120): string {
    return is_string($value) ? trim(preg_replace('/\s+/u', ' ', mb_substr($value, 0, $max)) ?? '') : '';
}

// Returns [order data, errors keyed by field name].
function validateOrder(array $in): array {
    $c = cfg();
    $e = [];
    $o = [];

    $offer = offer($in['offer'] ?? null);
    if ($offer === null) return [$o, ['offer' => 'Ten produkt nie jest już dostępny. Odśwież stronę.']];
    $o['offer'] = $offer['id'];

    $o['qty'] = filter_var($in['qty'] ?? null, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => $offer['max_qty']]]);
    if ($o['qty'] === false) $e['qty'] = 'Wybierz od 1 do ' . $offer['max_qty'] . ' sztuk.';

    $o['email'] = clean($in['email'] ?? '', 120);
    if (!filter_var($o['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'Sprawdź adres e-mail.';

    $o['name'] = clean($in['name'] ?? '', 80);
    if (mb_strlen($o['name']) < 3 || !str_contains($o['name'], ' ')) $e['name'] = 'Podaj imię i nazwisko.';

    if ($offer['type'] === 'voucher') {
        // Nothing to ship: the voucher may carry a recipient name and a short dedication.
        $o['delivery'] = '';
        $o['recipient'] = clean($in['recipient'] ?? '', 60);
        $o['dedication'] = clean($in['dedication'] ?? '', 200);
    } else {
        validateShipping($in, $c, $o, $e);
    }

    $o['invoice'] = $c['invoice'] && !empty($in['invoice']);
    if ($o['invoice']) {
        $o['company'] = clean($in['company'] ?? '', 100);
        $o['nip'] = preg_replace('/\D/', '', clean($in['nip'] ?? '', 20));
        $o['company_address'] = clean($in['company_address'] ?? '', 200);
        if (mb_strlen($o['company']) < 2) $e['company'] = 'Podaj nazwę firmy.';
        if (!validNip($o['nip'])) $e['nip'] = 'Ten NIP jest niepoprawny.';
        if (!parseCompanyAddress($o['company_address'])) $e['company_address'] = 'Podaj adres: ulica i numer, 00-000 Miasto.';
    }

    if (($in['terms'] ?? null) !== true) $e['terms'] = 'Zaakceptuj regulamin, aby złożyć zamówienie.';

    return [$o, $e];
}

// $number (MS-1001) is what the customer sees in the Paymove checkout; $externalId matches the webhook.
function createPayment(string $externalId, string $number, array $order, int $total): string {
    $c = cfg();
    $s = secrets();
    $h = paymoveHosts();
    $details = [
        'returnUrl'   => shopOrigin() . $c['shop']['base'] . '/' . ($order['snapshot']['path'] ?? '') . 'dziekujemy.html?o=' . $externalId,
        'productName' => $order['snapshot']['name'] . ($order['qty'] > 1 ? ' × ' . $order['qty'] : ''),
        'email'       => $order['email'],
        'locale'      => 'pl-PL',
        'orderId'     => $number,
        'autoclose'   => $c['paymove']['autoclose_ms'],
    ];
    if ($c['paymove']['trigger_payment']) $details['triggerPayment'] = $c['paymove']['trigger_payment'];

    $ch = curl_init($h['api'] . '/api/pay/product/' . rawurlencode($s['PAYMOVE_PRODUCT_ID']) . '/subproduct/pricing');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-KEY: ' . $s['PAYMOVE_API_KEY']],
        CURLOPT_POSTFIELDS => json_encode(['price' => $total, 'externalId' => $externalId, 'details' => $details]),
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false || $code !== 200) throw new RuntimeException('Paymove HTTP ' . $code);
    $url = json_decode($raw, true)['redirectUrl'] ?? '';
    if (!is_string($url) || !validPaymentUrl($url, $h['checkout'])) {
        throw new RuntimeException('Unexpected Paymove redirect');
    }
    return $url;
}

function verifySignature(string $header, string $raw): bool {
    if (!preg_match('/^t=(\d+),v1=([A-Za-z0-9+\/=]+)$/', $header, $m)) return false;
    if (abs(time() - (int)$m[1]) > 300) return false;
    $expected = base64_encode(hash_hmac('sha256', $m[1] . '.' . $raw, secrets()['PAYMOVE_WEBHOOK_SECRET'], true));
    return hash_equals($expected, $m[2]);
}

function sendMail(string $to, string $subject, string $body, ?string $replyTo = null): bool {
    $from = secrets()['MAIL_FROM'] ?? '';
    $headers = [
        'From: ' . mb_encode_mimeheader(cfg()['shop']['name'], 'UTF-8') . " <$from>",
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if ($replyTo) $headers[] = "Reply-To: $replyTo";
    return mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $body, implode("\r\n", $headers));
}

function orderSummary(array $order): string {
    $d = $order['data'];
    $q = $d['snapshot'] ?? null;
    if (orderType($d) === 'voucher') {
        $lines = ["Zamówienie {$order['number']}", '', "{$q['name']} × {$q['qty']}    " . formatPln($q['items']),
            'Razem: ' . formatPln($order['total']), '', 'Kupujący:', $d['name'], $d['email']];
        if (($d['recipient'] ?? '') !== '') $lines[] = "Dla: {$d['recipient']}";
        if (($d['dedication'] ?? '') !== '') $lines[] = "Dedykacja: {$d['dedication']}";
        return implode("\n", array_merge($lines, invoiceLines($d)));
    }
    $lines = [
        "Zamówienie {$order['number']}",
        '',
        $q ? "{$q['name']} × {$q['qty']}    " . formatPln($q['items']) : 'Brak historycznego rozbicia cen — wymagana weryfikacja.',
        $q ? "{$q['delivery_label']}    " . ($q['shipping'] ? formatPln($q['shipping']) : 'gratis') : '',
        'Razem: ' . formatPln($order['total']),
        '',
        'Dostawa:',
        $d['name'],
    ];
    if (!empty($d['street'])) {
        $lines[] = $d['street'];
        $lines[] = "{$d['postcode']} {$d['city']}";
    }
    if ($d['delivery'] === 'paczkomat') {
        $lines[] = "Paczkomat {$d['point']}" . ($d['point_label'] ? " — {$d['point_label']}" : '');
    }
    $lines[] = "tel. {$d['phone']}, {$d['email']}";
    return implode("\n", array_merge($lines, invoiceLines($d)));
}

function invoiceLines(array $d): array {
    return $d['invoice'] ? ['', 'Faktura:', $d['company'], "NIP {$d['nip']}", $d['company_address']] : [];
}

// This environment's origin, from the private .env and checked against config.
function shopOrigin(): string {
    return secrets()['SHOP_ORIGIN'];
}

function sameOrigin(): bool {
    return ($_SERVER['HTTP_ORIGIN'] ?? '') === shopOrigin();
}

// The panel's own origin: PANEL_ORIGIN when the panel has a separate host, otherwise the shop origin.
function panelOrigin(): string {
    return panelSeparate() ? secrets()['PANEL_ORIGIN'] : shopOrigin();
}

function logError(Throwable $e, string $where): void {
    // Controlled category + class only; exception messages may contain provider/customer data.
    error_log("heartshop $where: " . get_class($e));
}

require_once __DIR__.'/checkout-state.php';
require_once __DIR__.'/reliability.php';
require_once __DIR__.'/vouchers.php';
require_once __DIR__.'/panel-lib.php';
