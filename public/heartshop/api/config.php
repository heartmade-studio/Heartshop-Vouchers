<?php
// Runtime settings. Also review HTML/legal copy and the allowed hosts in .htaccess.
// Secrets (API keys, webhook secret, e-mail addresses, panel password hash) live in the private .env, never here.
// Amounts are integers in grosze: 4900 = 49,00 zł.
return [
    'shop' => [
        'name'   => 'Voucher na usługę',
        'seller' => 'Sklep demonstracyjny Heartmade',
        // SHOP_ORIGIN in the private .env picks this environment's origin; it must be listed here.
        'origins' => getenv('HEARTSHOP_ORIGIN') ? [getenv('HEARTSHOP_ORIGIN')] : ['https://shop.example.com', 'https://staging.shop.example.com'],
        // URL path of the shop directory: one segment, e.g. '/heartshop' or '/vouchers'. Rebuild after changing it.
        'base'   => '/heartshop',
        // 'root': public/ is the whole web root; the build owns its root redirect, robots.txt and sitemap.xml.
        // 'subdirectory': the shop directory is mounted inside another website, which keeps its own root files.
        'mount'  => 'root',
        // Optional separate seller-panel origin(s), e.g. ['https://panel.example.com'], served from a separate
        // document root built by scripts/heartshop/release.php. Empty: the panel stays at <base>/panel/ on the shop host.
        // PANEL_ORIGIN in the private .env picks this environment's panel origin; it must be listed here.
        'panel_origins' => [],
        'order_prefix' => 'HV', // order numbers: HS-1001, HS-1002…
    ],
    // Enable only after completing docs/production.md and replacing all sample content.
    'production_ready' => false,
    // One indexing switch. Rebuild with php scripts/heartshop/build-storefront.php.
    // Keep false for demos/staging. See docs/indexing.md before enabling.
    'indexing' => [
        'enabled' => false,
        'origin' => 'https://shop.example.com',
        'description' => [
            'pl' => 'Przykładowa oferta sklepu Heartshop. Lokalna symulacja, bez prawdziwej sprzedaży.',
            'en' => 'Example Heartshop offer. Local simulation without real sales.',
        ],
        // Only approved public facts; rendered visibly as well as in structured data.
        'seller_type' => 'ProfessionalService', // Vouchers: LocalBusiness or ProfessionalService
        'seller_address' => '', // Public business address, if approved for publication.
    ],
    'invoice_request_text' => 'Faktura na żądanie: odpowiedz na wiadomość z potwierdzeniem zamówienia i podaj numer zamówienia. Sprzedawca uzgodni z Tobą dane i ręcznie wystawi fakturę poza sklepem. To przykładowa instrukcja do zatwierdzenia przez sprzedawcę.',

    'product_type' => 'voucher',
    'terms_version' => 'demo-2026-11-03', // Change only with reviewed legal document version.
    // What the shop sells. Each offer has its own pages in <base>/<path> (zamowienie.html, dziekujemy.html).
    // This distribution accepts only voucher offers.
    //   type 'voucher':  a gift voucher; each unit bought gets its own code, valid for valid_months.
    // Never reuse an offer id for a different product: historical orders keep it.
    'default_offer' => 'masaz-60', // pages without data-offer and older open tabs order this one
    'offers' => [
        'masaz-60' => [
            'demo'         => true,
            'type'         => 'voucher',
            'name'         => 'Masaż relaksacyjny 60 min',
            'variant'      => 'Voucher podarunkowy, ważny 12 miesięcy',
            'price'        => 25000,
            'max_qty'      => 5,
            'valid_months' => 12,
            'path'         => '',
        ],
    ],
    'delivery' => [],
    'free_shipping_from' => null,
    'invoice' => false, // Invoice issuance is not implemented in Heartshop Vouchers.
    'tax_rate'           => 23,    // VAT % for product and shipping; null = seller exempt from VAT
    'paymove' => [
        'trigger_payment' => null, // e.g. 'BLIK' to open that method straight away
        'autoclose_ms'    => 4000, // modal closes itself after a successful payment
    ],
    // Private .env with secrets, outside the web root.
    'env_file' => getenv('HEARTSHOP_ENV_FILE') ?: dirname(__DIR__, 3) . '/private/heartshop/.env',
];
