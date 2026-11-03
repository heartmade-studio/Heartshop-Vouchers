<?php
declare(strict_types=1);

/** The seller's orders panel: one password (hash in the private .env), sessions in SQLite. */
const PANEL_COOKIE = 'hs_panel';
const PANEL_TTL = 14 * 86400; // sliding: every visit extends it

final class PanelProblem extends RuntimeException {
    public function __construct(public int $httpStatus, public string $reason, string $message) { parent::__construct($message); }
}

function panelSchema(SQLite3 $db): void {
    // Only a hash of the session token is stored: a copied database cannot log anyone in.
    $db->exec('CREATE TABLE IF NOT EXISTS panel_sessions (token_hash TEXT PRIMARY KEY, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL)');
}

// Generate with scripts/heartshop/panel-password.php; without it the panel stays closed.
function panelPasswordHash(): ?string {
    $hash = secrets()['PANEL_PASSWORD_HASH'] ?? '';
    return is_string($hash) && str_starts_with($hash, '$') && strlen($hash) >= 50 ? $hash : null;
}

/** Returns a new session token, or null for a wrong password. Every attempt counts against the limits. */
function panelLogin(mixed $password, string $address, ?int $now = null): ?string {
    $now ??= time();
    $hash = panelPasswordHash();
    if ($hash === null) throw new PanelProblem(503, 'not_configured', 'Panel nie jest skonfigurowany.');
    if (!consumeLimits(['login:' . hash('sha256', $address) => 10, 'login:all' => 50], $now)) {
        throw new PanelProblem(429, 'rate_limited', 'Zbyt wiele prób logowania. Odczekaj kilka minut.');
    }
    if (!is_string($password) || $password === '' || strlen($password) > 200 || !password_verify($password, $hash)) return null;
    $token = bin2hex(random_bytes(32));
    $db = db();
    $db->exec('DELETE FROM panel_sessions WHERE expires_at < ' . $now);
    $s = $db->prepare('INSERT INTO panel_sessions(token_hash,created_at,expires_at) VALUES(:h,:now,:exp)');
    $s->bindValue(':h', hash('sha256', $token));
    $s->bindValue(':now', $now, SQLITE3_INTEGER);
    $s->bindValue(':exp', $now + PANEL_TTL, SQLITE3_INTEGER);
    $s->execute();
    return $token;
}

function panelSessionValid(mixed $token, ?int $now = null): bool {
    $now ??= time();
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
    $s = db()->prepare('UPDATE panel_sessions SET expires_at=:exp WHERE token_hash=:h AND expires_at>=:now');
    $s->bindValue(':exp', $now + PANEL_TTL, SQLITE3_INTEGER);
    $s->bindValue(':h', hash('sha256', $token));
    $s->bindValue(':now', $now, SQLITE3_INTEGER);
    $s->execute();
    return db()->changes() === 1;
}

function panelLogout(mixed $token): void {
    if (!is_string($token)) return;
    $s = db()->prepare('DELETE FROM panel_sessions WHERE token_hash=:h');
    $s->bindValue(':h', hash('sha256', $token));
    $s->execute();
}

function panelCookie(string $value, int $expires): void {
    setcookie(PANEL_COOKIE, $value, ['expires' => $expires, 'path' => cfg()['shop']['base'] . '/api/',
        'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
}

/** Every order, newest first, with what the seller needs to act on it. */
function panelOrders(int $limit = 2000): array {
    $db = db();
    $codes = [];
    $res = $db->query('SELECT * FROM vouchers ORDER BY created_at, code');
    while ($v = $res->fetchArray(SQLITE3_ASSOC)) {
        $codes[$v['order_external_id']][] = ['code' => $v['code'], 'status' => $v['status'],
            'expires_at' => (int)$v['expires_at'], 'used_at' => $v['used_at'] === null ? null : (int)$v['used_at']];
    }
    $s = $db->prepare('SELECT * FROM orders ORDER BY id DESC LIMIT :limit');
    $s->bindValue(':limit', max(1, min(10000, $limit)), SQLITE3_INTEGER);
    $res = $s->execute();
    $out = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $d = json_decode($row['data'], true) ?: [];
        $q = $d['snapshot'] ?? [];
        $out[] = [
            'id' => $row['external_id'], 'number' => orderNumber((int)$row['id']),
            'status' => $row['status'], 'payment_state' => $row['payment_state'],
            'type' => orderType($d), 'product' => $q['name'] ?? null, 'qty' => $q['qty'] ?? ($d['qty'] ?? null),
            'total' => (int)$row['total'], 'payment_method' => $row['payment_method'],
            'created_at' => (int)$row['created_at'], 'paid_at' => $row['paid_at'] === null ? null : (int)$row['paid_at'],
            'name' => $d['name'] ?? '', 'email' => $d['email'] ?? '', 'phone' => $d['phone'] ?? '',
            'delivery' => $q['delivery_label'] ?? null, 'point' => $d['point'] ?? null,
            'address' => isset($d['street']) ? trim("{$d['street']}, {$d['postcode']} {$d['city']}", ', ') : null,
            'tracking' => $row['tracking_number'],
            'recipient' => $d['recipient'] ?? '', 'dedication' => $d['dedication'] ?? '',
            'invoice' => !empty($d['invoice']), 'company' => $d['company'] ?? '', 'nip' => $d['nip'] ?? '',
            'company_address' => $d['company_address'] ?? '',
            'note' => $row['note'] ?? '', 'done_at' => $row['done_at'] === null ? null : (int)$row['done_at'],
            'invoice_done_at' => $row['invoice_done_at'] === null ? null : (int)$row['invoice_done_at'],
            'vouchers' => $codes[$row['external_id']] ?? [],
        ];
    }
    return $out;
}

/** The seller's own bookkeeping on an order: a note and two "done" ticks. Payment state is never touched. */
function panelUpdate(mixed $id, array $in, ?int $now = null): void {
    $now ??= time();
    if (!is_string($id) || !preg_match('/^hs_[a-f0-9]{24}$/D', $id) || !findOrder($id)) {
        throw new PanelProblem(404, 'unknown_order', 'Nie ma takiego zamówienia.');
    }
    $sets = [];
    $values = [];
    if (array_key_exists('note', $in)) {
        if (!is_string($in['note']) || mb_strlen($in['note']) > 1000) throw new PanelProblem(422, 'invalid', 'Notatka może mieć do 1000 znaków.');
        $sets[] = 'note=:note'; $values[':note'] = trim($in['note']);
    }
    foreach (['done' => 'done_at', 'invoice_done' => 'invoice_done_at'] as $key => $column) {
        if (!array_key_exists($key, $in)) continue;
        if (!is_bool($in[$key])) throw new PanelProblem(422, 'invalid', 'Nieprawidłowa wartość.');
        $sets[] = "$column=:$key"; $values[":$key"] = $in[$key] ? $now : null;
    }
    if (!$sets) throw new PanelProblem(422, 'invalid', 'Brak zmian.');
    $s = db()->prepare('UPDATE orders SET ' . implode(',', $sets) . ' WHERE external_id=:id');
    foreach ($values as $k => $v) $s->bindValue($k, $v, $v === null ? SQLITE3_NULL : (is_int($v) ? SQLITE3_INTEGER : SQLITE3_TEXT));
    $s->bindValue(':id', $id);
    $s->execute();
}

/** Queues the buyer's confirmation again (same content, today's link). Paid orders only, a few per hour. */
function panelResend(mixed $id, ?int $now = null): void {
    $order = is_string($id) && preg_match('/^hs_[a-f0-9]{24}$/D', $id) ? findOrder($id) : null;
    if (!$order) throw new PanelProblem(404, 'unknown_order', 'Nie ma takiego zamówienia.');
    if ($order['status'] !== 'paid') throw new PanelProblem(409, 'not_paid', 'Potwierdzenie wysyłamy tylko dla opłaconych zamówień.');
    if (!consumeLimits(['resend:' . $id => 3], $now)) throw new PanelProblem(429, 'rate_limited', 'To potwierdzenie wysłano już kilka razy. Spróbuj za chwilę.');
    [$to, $subject, $body, $reply] = paymentNotices($order, 'paid', [])[0];
    $s = db()->prepare('INSERT INTO mail_outbox(order_external_id,recipient,subject,body,reply_to,created_at) VALUES(:id,:to,:subject,:body,:reply,:now)');
    $s->bindValue(':id', $id); $s->bindValue(':to', $to); $s->bindValue(':subject', $subject); $s->bindValue(':body', $body);
    $s->bindValue(':reply', $reply, $reply === null ? SQLITE3_NULL : SQLITE3_TEXT);
    $s->bindValue(':now', $now ?? time(), SQLITE3_INTEGER);
    $s->execute();
}

/** Semicolon CSV with a BOM, so Polish Excel opens it with the right columns and letters. */
function panelCsv(array $orders): string {
    $statuses = ['paid' => 'opłacone', 'review' => 'do sprawdzenia', 'pending' => 'nieopłacone', 'canceled' => 'anulowane'];
    $cell = function (mixed $v): string {
        $v = (string)$v;
        if ($v !== '' && str_contains('=+-@', $v[0])) $v = "'" . $v; // no formulas from customer input
        return '"' . str_replace('"', '""', $v) . '"';
    };
    $date = fn(?int $ts) => $ts ? (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i') : '';
    $rows = [['Numer', 'Data', 'Status', 'Produkt', 'Ilość', 'Kwota', 'Kupujący', 'E-mail', 'Telefon', 'Dostawa', 'Adres',
        'Dla', 'Kody voucherów', 'Faktura', 'Firma', 'NIP', 'Adres firmy', 'Faktura wystawiona', 'Obsłużone', 'Notatka']];
    foreach ($orders as $o) {
        $rows[] = [$o['number'], $date($o['paid_at'] ?? $o['created_at']), $statuses[$o['status']] ?? $o['status'],
            $o['product'] ?? '', $o['qty'] ?? '', number_format($o['total'] / 100, 2, ',', ''), $o['name'], $o['email'], $o['phone'],
            $o['delivery'] ?? '', $o['address'] ?? '', $o['recipient'],
            implode(', ', array_map(fn($v) => $v['code'] . ($v['status'] === 'used' ? ' (wykorzystany)' : ''), $o['vouchers'])),
            $o['invoice'] ? 'tak' : '', $o['company'], $o['nip'], $o['company_address'],
            $date($o['invoice_done_at']), $date($o['done_at']), $o['note']];
    }
    return "\u{FEFF}" . implode("\r\n", array_map(fn($r) => implode(';', array_map($cell, $r)), $rows)) . "\r\n";
}
