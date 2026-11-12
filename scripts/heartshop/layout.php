<?php
/** Build-time URL layout helpers shared by build-storefront.php and release.php. CLI only; never deployed. */
declare(strict_types=1);

/** Load the merchant config exactly as committed: local-simulation overrides must not leak into a build. */
function layoutConfig(string $root): array {
    putenv('HEARTSHOP_ORIGIN'); putenv('HEARTSHOP_ENV_FILE');
    unset($_ENV['HEARTSHOP_ORIGIN'], $_ENV['HEARTSHOP_ENV_FILE'], $_SERVER['HEARTSHOP_ORIGIN'], $_SERVER['HEARTSHOP_ENV_FILE']);
    require_once $root.'/public/heartshop/api/lib.php';
    return cfg();
}

function authority(string $origin): string {
    if (!preg_match('#^https://([a-z0-9]+(?:[.-][a-z0-9]+)*(?::[0-9]+)?)$#D', $origin, $m)) {
        throw new RuntimeException('Origins must be explicit HTTPS origins');
    }
    return $m[1];
}

/**
 * HTTPS-only host rules. Listed hosts redirect to themselves over TLS, www. of the first host goes to it,
 * any other plain-HTTP Host is refused. Never derived from a request header.
 */
function hostRules(array $origins, string $label): string {
    if ($origins === []) throw new RuntimeException('At least one origin is required');
    $hosts = array_map('authority', $origins);
    $quote = static fn(string $h) => str_replace('.', '\\.', $h);
    $out = "# Only the listed $label hosts may be redirect targets; www goes to the apex host.\n";
    if (!str_starts_with($hosts[0], 'www.')) {
        $out .= 'RewriteCond %{HTTP_HOST} ^www\\.'.$quote($hosts[0])."$ [NC]\n"
            .'RewriteRule ^ https://'.$hosts[0]."%{REQUEST_URI} [R=308,L]\n";
    }
    return $out."RewriteCond %{HTTPS} !=on\n"
        .'RewriteCond %{HTTP_HOST} ^('.implode('|', array_map($quote, $hosts)).")$ [NC]\n"
        ."RewriteRule ^ https://%1%{REQUEST_URI} [R=308,L]\n"
        ."# Plain HTTP for any other Host has no trusted authority to redirect to.\n"
        ."RewriteCond %{HTTPS} !=on\n"
        ."RewriteRule ^ - [F]";
}

/** Only the reviewed entry points may be requested directly; every other PHP file is an include. */
function entryPointRule(array $names): string {
    return "<FilesMatch \"^(?!(?:".implode('|', $names).")\\.php$).*\\.php$\">\n  Require all denied\n</FilesMatch>";
}

function replaceGenerated(string $text, string $label, string $content): string {
    $pattern = '/# BEGIN GENERATED '.preg_quote($label, '/').'\n.*?# END GENERATED '.preg_quote($label, '/').'/s';
    if (preg_match_all($pattern, $text) !== 1) throw new RuntimeException("Missing generated block: $label");
    return preg_replace_callback($pattern, fn() => "# BEGIN GENERATED $label\n$content\n# END GENERATED $label", $text);
}
