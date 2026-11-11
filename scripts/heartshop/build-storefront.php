<?php
/** Build public, reviewable files from merchant config. No secrets, database or network. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require __DIR__.'/layout.php';
$c = layoutConfig($root); $seo = $c['indexing']; $o = offer(null);
$base = $c['shop']['base']; $mounted = ($c['shop']['mount'] ?? 'root') === 'subdirectory';
$enabled = ($seo['enabled'] ?? false) === true;
$origin = rtrim((string)$seo['origin'], '/');
if (!preg_match('#^https://[a-z0-9]+(?:[.-][a-z0-9]+)*(?::[0-9]+)?$#D', $origin)
    || !in_array($origin, $c['shop']['origins'], true)) {
    throw new RuntimeException('Indexing origin must be a configured HTTPS origin, never a request header');
}
if ($enabled && (($c['production_ready'] ?? false) !== true || ($o['demo'] ?? true) !== false)) {
    throw new RuntimeException('Indexing requires production_ready and a non-demo default offer');
}
if ($enabled && function_exists('invoiceTextNeedsApproval') && invoiceTextNeedsApproval($c)) {
    throw new RuntimeException('Indexing requires the seller-approved invoice_request_text');
}
if (!is_int($o['price']) || $o['price'] <= 0) throw new RuntimeException('Offer price must be positive integer grosze');
if (!in_array($seo['seller_type'], ['ProfessionalService', 'LocalBusiness'], true)) throw new RuntimeException('Invalid seller type');
$e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
function replaceBlock(string $html, string $label, string $content, string $before): string {
    $block = "<!-- BEGIN $label -->\n$content\n<!-- END $label -->";
    $pattern = '#<!-- BEGIN '.preg_quote($label, '#').' -->.*?<!-- END '.preg_quote($label, '#').' -->#s';
    return preg_match($pattern, $html) ? preg_replace_callback($pattern, fn() => $block, $html) : str_replace($before, $block."\n".$before, $html);
}
$pages = ['index.html'=>'pl'];
if (is_file($root.'/public/heartshop/en/index.html')) $pages['en/index.html']='en';
$files = []; $urls = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/public/heartshop', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'html') continue;
    $relative = substr($file->getPathname(), strlen($root.'/public/heartshop/'));
    $landing = isset($pages[$relative]); $lang = $pages[$relative] ?? (str_starts_with($relative, 'en/') ? 'en' : 'pl');
    $html = file_get_contents($file->getPathname());
    $robots = $enabled && $landing ? 'index, follow' : 'noindex, nofollow';
    $html = preg_replace('#<meta name="robots" content="[^"]*">#', '<meta name="robots" content="'.$robots.'">', $html);
    // Fallback prices are also correct without JavaScript. Checkout still prices on the server.
    $html = preg_replace_callback('#(<[^>]+\bdata-price(?:="[^"]*")?[^>]*>)[^<]*#', fn($m) => $m[1].$e(formatPln($o['price'])), $html);
    if ($landing) {
        $description = (string)$seo['description'][$lang];
        $title = $o['name'].' — '.$c['shop']['seller'];
        $path = $base.'/'.($lang === 'en' ? 'en/' : ''); $url = $origin.$path;
        $html = preg_replace_callback('#<title>.*?</title>#s', fn() => '<title>'.$e($title).'</title>', $html);
        $html = preg_replace_callback('#<meta name="description" content="[^"]*">#', fn() => '<meta name="description" content="'.$e($description).'">', $html);
        $head = '';
        if ($enabled) {
            $urls[] = $url;
            $offer = ['@type'=>'Offer','url'=>$url,'priceCurrency'=>'PLN','price'=>number_format($o['price']/100, 2, '.', '')];
            // Do not invent ratings, availability, discounts, delivery promises or service locations.
            $seller = ['@type'=>$o['type']==='voucher' ? $seo['seller_type'] : 'Organization',
                '@id'=>$origin.$base.'/#seller','name'=>$c['shop']['seller'],'url'=>$origin.$base.'/'];
            if ($seo['seller_address'] !== '') $seller['address']=$seo['seller_address'];
            $product = ['@type'=>$o['type']==='voucher' ? 'Service' : 'Product','@id'=>$url.'#offer',
                'name'=>$o['name'],'description'=>$description,'url'=>$url,'offers'=>$offer+['seller'=>['@id'=>$seller['@id']]]];
            if ($o['type']==='voucher') { $product['provider']=['@id'=>$seller['@id']]; $seller['makesOffer']=$offer+['itemOffered'=>['@id'=>$product['@id']]]; }
            $schema = ['@context'=>'https://schema.org','@graph'=>[$seller,$product]];
            $head = '<link rel="canonical" href="'.$e($url).'">'."\n";
            foreach (['og:type'=>'website','og:title'=>$title,'og:description'=>$description,'og:url'=>$url,'og:locale'=>$lang==='en'?'en_GB':'pl_PL'] as $key=>$value) {
                $head .= '<meta property="'.$key.'" content="'.$e($value).'">'."\n";
            }
            $head .= '<script type="application/ld+json">'.json_encode($schema, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>';
        }
        $html = replaceBlock($html, 'STOREFRONT METADATA', $head, '</head>');
        $visible = '<section class="wrap" aria-label="'.($lang==='en'?'Offer and seller':'Oferta i sprzedawca').'">'
            .'<h2>'.$e($o['name']).'</h2><p>'.$e($description).'</p><p>'.$e($c['shop']['seller'])
            .($seo['seller_address']!=='' ? ' · '.$e($seo['seller_address']) : '').'</p></section>';
        $html = replaceBlock($html, 'STOREFRONT FACTS', $visible, '</main>');
    }
    if (isset($c['invoice_request_text']) && str_contains($html, '<!-- BEGIN INVOICE REQUEST -->')) {
        $html = replaceBlock($html, 'INVOICE REQUEST', $e($c['invoice_request_text']), '</main>');
    }
    $files[$file->getPathname()] = $html;
}
// The only indexable URLs are the reviewed landing pages, on their exact production authority over TLS.
$rule = '  Header always set X-Robots-Tag "noindex, nofollow"';
if ($enabled) {
    $paths = '^'.preg_quote($base, '#').(count($pages)>1 ? '/(en/)?(index\\.html)?$' : '/(index\\.html)?$');
    $authority = substr($origin, strlen('https://'));
    $rule .= "\n<If \"%{HTTPS} == 'on' &amp;&amp; %{HTTP_HOST} == '$authority' &amp;&amp; %{REQUEST_URI} =~ m#$paths#\">\n  Header always set X-Robots-Tag \"index, follow\"\n</If>";
    $rule = str_replace('&amp;', '&', $rule);
}
$p = $root.'/public/heartshop/.htaccess';
$htaccess = replaceGenerated(file_get_contents($p), 'INDEXING', $rule);
$htaccess = replaceGenerated($htaccess, 'HOSTS', hostRules($c['shop']['origins'], 'shop'));
// With a separate panel host the shop directory exposes no panel entry point (release.php also omits the files).
$entries = panelSeparate() ? ['order', 'status', 'webhook'] : ['order', 'status', 'webhook', 'panel'];
$files[$p] = replaceGenerated($htaccess, 'ENTRY POINTS', entryPointRule($entries));
if (!$mounted) {
    // The shop owns the web root: root redirect, sitemap and robots follow the shop configuration.
    $files[$root.'/public/.htaccess'] = replaceGenerated(file_get_contents($root.'/public/.htaccess'), 'ROOT REDIRECT', "RewriteRule ^$ $base/ [R=308,L]");
    $files[$root.'/public/sitemap.xml'] = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
        .implode('', array_map(fn($url) => '  <url><loc>'.$e($url)."</loc></url>\n", $urls))."</urlset>\n";
    $files[$root.'/public/robots.txt'] = "User-agent: *\nAllow: /\n".($enabled ? "Sitemap: $origin/sitemap.xml\n" : '# Demo: pages carry noindex in HTTP headers and HTML; do not block crawling.' ."\n");
}
$stale = [];
foreach ($files as $path=>$content) {
    if (is_file($path) && file_get_contents($path)===$content) continue;
    $stale[] = substr($path, strlen($root)+1);
    if (!in_array('--check', $argv, true)) file_put_contents($path, $content);
}
if ($stale && in_array('--check', $argv, true)) { fwrite(STDERR, "Rebuild storefront: ".implode(', ', $stale)."\n"); exit(1); }
echo 'PASS storefront '.(in_array('--check', $argv, true) ? 'freshness' : 'build').'; indexing '.($enabled?'on':'off')."\n";
// A mounted shop never writes the host website's root files; that website lists the URLs in its own sitemap.
if ($mounted && $urls) echo "Add to the host website's sitemap.xml: ".implode(' ', $urls)."\n";
