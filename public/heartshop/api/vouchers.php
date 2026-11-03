<?php
declare(strict_types=1);

/** Gift vouchers: one code per unit bought, issued in the same transaction that marks the order paid. */
function voucherSchema(SQLite3 $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS vouchers (
        code TEXT PRIMARY KEY,
        order_external_id TEXT NOT NULL,
        product_id TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "active" CHECK(status IN ("active","used")),
        expires_at INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        used_at INTEGER
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS vouchers_order ON vouchers(order_external_id)');
}

// XXXX-XXXX without 0/O/1/I/L: easy to read aloud over the phone or type from paper. 31^8 ≈ 8.5·10^11.
function voucherCode(): string {
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 8; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

// Valid through the end of the same calendar day, valid_months after payment (Polish time).
function voucherExpiry(int $paidAt, int $months): int {
    $tz = new DateTimeZone('Europe/Warsaw');
    return (new DateTimeImmutable('@' . $paidAt))->setTimezone($tz)
        ->modify("+$months months")->setTime(23, 59, 59)->getTimestamp();
}

/** Caller holds the write transaction. Idempotent: an order that already has codes gets no more. */
function issueVouchers(SQLite3 $db, array $order): void {
    $q = $order['data']['snapshot'];
    $s = $db->prepare('SELECT COUNT(*) FROM vouchers WHERE order_external_id=:id');
    $s->bindValue(':id', $order['external_id']);
    if ((int)$s->execute()->fetchArray(SQLITE3_NUM)[0] > 0) return;
    $expires = voucherExpiry((int)$order['paid_at'], (int)$q['valid_months']);
    $ins = $db->prepare('INSERT OR IGNORE INTO vouchers(code,order_external_id,product_id,expires_at,created_at)
        VALUES(:code,:id,:product,:expires,:now)');
    for ($issued = 0; $issued < (int)$q['qty'];) {
        $ins->bindValue(':code', voucherCode());
        $ins->bindValue(':id', $order['external_id']);
        $ins->bindValue(':product', (string)$q['product_id']);
        $ins->bindValue(':expires', $expires, SQLITE3_INTEGER);
        $ins->bindValue(':now', time(), SQLITE3_INTEGER);
        $ins->execute();
        if ($db->changes() === 1) $issued++; // a collision just draws another code
    }
}

function vouchersFor(string $externalId): array {
    $s = db()->prepare('SELECT code,status,expires_at,used_at FROM vouchers WHERE order_external_id=:id ORDER BY created_at, code');
    $s->bindValue(':id', $externalId);
    $res = $s->execute();
    $out = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $out[] = $row;
    return $out;
}

// Accepts the code as typed: lower case, spaces, missing or extra dashes.
function normalizeVoucherCode(mixed $code): ?string {
    if (!is_string($code)) return null;
    $raw = strtoupper(preg_replace('/[\s-]/', '', $code) ?? '');
    return preg_match('/^[A-Z0-9]{8}$/D', $raw) ? substr($raw, 0, 4) . '-' . substr($raw, 4) : null;
}

/** Marks a code used (or back to active, to undo a mistake). Returns [ok, reason, voucher]. */
function setVoucherUsed(mixed $input, bool $used, ?int $now = null): array {
    $now ??= time();
    $code = normalizeVoucherCode($input);
    if ($code === null) return [false, 'invalid', null];
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $s = $db->prepare('SELECT v.*, o.status AS order_status FROM vouchers v JOIN orders o ON o.external_id=v.order_external_id WHERE v.code=:code');
        $s->bindValue(':code', $code);
        $v = $s->execute()->fetchArray(SQLITE3_ASSOC);
        $reason = match (true) {
            !$v => 'unknown',
            $v['order_status'] !== 'paid' => 'not_paid',
            $used && $v['status'] === 'used' => 'already_used',
            !$used && $v['status'] === 'active' => 'not_used',
            $used && (int)$v['expires_at'] < $now => 'expired',
            default => null,
        };
        if ($reason === null) {
            $u = $db->prepare('UPDATE vouchers SET status=:status, used_at=:at WHERE code=:code');
            $u->bindValue(':status', $used ? 'used' : 'active');
            $u->bindValue(':at', $used ? $now : null, $used ? SQLITE3_INTEGER : SQLITE3_NULL);
            $u->bindValue(':code', $code);
            $u->execute();
            $v['status'] = $used ? 'used' : 'active';
            $v['used_at'] = $used ? $now : null;
        }
        $db->exec('COMMIT');
        return [$reason === null, $reason ?? 'ok', $v ?: null];
    } catch (Throwable $e) { try { $db->exec('ROLLBACK'); } catch (Throwable) {} throw $e; }
}

function formatDate(int $ts): string {
    return (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('j.m.Y');
}

// Link to the printable vouchers of one order. The order id is the same bearer token as the status link.
function voucherPageUrl(array $order): string {
    $path = $order['data']['snapshot']['path'] ?? '';
    return shopOrigin() . cfg()['shop']['base'] . '/' . $path . 'twoj-voucher.html?o=' . $order['external_id'];
}
