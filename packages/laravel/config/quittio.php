<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    | country: country pack used when it cannot be derived from the seller (BT-40).
    | profile: null = default of the format (EN 16931 for ZUGFeRD/Factur-X).
    | format:  null or 'auto' = automatic choice by the format resolver; or one of
    |          zugferd, xrechnung-cii, xrechnung-ubl, cii, ubl, peppol, pdf, html, json.
    | vat:     tax preset of lines without ->vat(), e.g. 'de.reduced_7'; null = the standard
    |          rate of the seller's country pack (DE: 'de.standard_19'). Small business
    |          invoices use the small business preset of the pack (DE: 'de.small_business').
    */
    'defaults' => [
        'country' => env('QUITTIO_COUNTRY', 'DE'),
        'profile' => null,
        'format' => null,
        'locale' => null,
        'due_days' => 14,
        'vat' => env('QUITTIO_DEFAULT_VAT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Country packs
    |--------------------------------------------------------------------------
    | Installed country packs also register themselves through package discovery
    | (composer.json: extra.quittio.country-packs). List additional packs here.
    */
    'countries' => [
        // 'DE' => \Quittio\Country\DE\CountryDE::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Seller
    |--------------------------------------------------------------------------
    | Used by Seller::fromConfig(). With quittio/managed, issuer profiles replace it.
    | small_business: Seller::apply() marks invoices of a small business (DE:
    |                 section 19 UStG), invoices with VAT are then rejected. Use
    |                 VatPreset::SmallBusiness for the lines.
    | identifiers:    seller identifiers (BT-29) as ['value' => ..., 'scheme' => null].
    |                 Without a VAT identifier (e.g. a small business with tax number
    |                 only) EN 16931 needs BT-29 or legal_registration (BT-30), BR-CO-26.
    */
    'seller' => [
        'name' => env('QUITTIO_SELLER_NAME'),
        'vat_id' => env('QUITTIO_SELLER_VAT_ID'),
        'tax_number' => env('QUITTIO_SELLER_TAX_NUMBER'),
        'legal_registration' => null,
        'identifiers' => [],
        'additional_legal_info' => null,
        'address' => ['line1' => null, 'line2' => null, 'postcode' => null, 'city' => null, 'country' => 'DE'],
        'contact' => ['name' => null, 'phone' => null, 'email' => null],
        'electronic_address' => ['value' => null, 'scheme' => 'EM'],
        'bank_accounts' => [['iban' => null, 'bic' => null, 'name' => null]],
        'small_business' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF and design
    |--------------------------------------------------------------------------
    | renderer: mpdf, or a driver registered with Quittio::extend('renderer', ...).
    | template: Blade view (design level 3: your own view with the same view model).
    | templates: further views that ->template() or presentation data may select besides
    |           "template" and quittio::templates.* ("prefix.*" for every view below a prefix;
    |           make:quittio-template creates views under "invoices."). Others are refused.
    | paper:    null = default of the country pack (DE: A4); a size set here wins.
    | logo:     absolute path, http(s) URL or data URI of a PNG, JPEG or SVG (at most
    |           10 MB and 25 megapixels; URLs only from public hosts). For PDF/A (ZUGFeRD)
    |           logos are flattened to RGB PNG; SVG needs the imagick extension.
    | markdown: descriptions and notes as Markdown instead of plain text. They are
    |           also in the e-invoice XML; formatting can change the displayed text
    |           (e.g. "*" for emphasis), so it is off by default.
    | payment_qr: type auto | girocode | swiss-qr-bill | none; generator: a class implementing
    |           Quittio\Core\Pdf\Qr\PaymentQrGenerator, used for "auto" before the country
    |           pack's and required for "swiss-qr-bill" (Quittio ships no Swiss generator).
    | font_directories: searched for the files of "fonts"; resources/fonts/quittio and its
    |           subdirectories (--tag=quittio-fonts publishes Inter there) follow when they exist.
    */
    'pdf' => [
        'renderer' => 'mpdf',
        'template' => 'quittio::templates.default.layout',
        'templates' => ['invoices.*'],
        'layout' => null,
        'paper' => null,
        'pdfa' => true,
        'font' => 'inter',
        'fonts' => [],
        'font_directories' => [],
        'logo' => null,
        'colors' => ['primary' => '#1e40af', 'text' => '#111827', 'muted' => '#6b7280'],
        'sender_line' => null,
        'footer_columns' => [],
        'texts' => ['salutation' => null, 'intro' => null, 'closing' => null],
        'show_item_numbers' => false,
        'markdown' => false,
        'fold_marks' => false,
        'payment_qr' => ['type' => 'auto', 'girocode_amount' => 'due', 'generator' => null],
        'filename' => '{title}_{number}.{ext}',
        'cache_path' => storage_path('framework/cache/quittio'),
        'renderers' => [
            // simple_tables = true ignores the borders of table cells.
            'mpdf' => ['simple_tables' => false, 'pdfa_auto' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    | mode:   error (throw), warn (attach the result only) or off.
    | levels: php (L1-L3, L7), xsd (L4), kosit (L5), verapdf (L6).
    | mandatory_field_guard: exception, log or off; null = exception outside
    |         production, log in production.
    */
    'validation' => [
        'mode' => env('QUITTIO_VALIDATION', 'error'),
        'levels' => ['php', 'xsd'],
        'block_on_warnings' => false,
        'mandatory_field_guard' => env('QUITTIO_GUARD'),
        'drivers' => [
            'kosit' => [
                'mode' => 'daemon',
                // The validator listens on 8080 unless started with "-P 8081" (or mapped, e.g.
                // docker run -p 8081:8080); quittio:schemas:download prints the start command.
                'url' => env('QUITTIO_KOSIT_URL', 'http://127.0.0.1:8081'),
                'jar' => null,
                'scenarios' => null,
                'repository' => null,
                // Further scenario sets, e.g. Peppol BIS (printed by quittio:schemas:download).
                'configurations' => [],
                'java' => 'java',
                'timeout' => 30,
                'fail_on_unavailable' => true,
            ],
            'verapdf' => ['mode' => 'cli', 'binary' => 'verapdf', 'image' => 'verapdf/cli', 'docker' => 'docker', 'timeout' => 120, 'fail_on_unavailable' => true],
            'vies' => ['enabled' => false, 'cache_ttl' => 86400, 'timeout' => 5],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Corrections
    |--------------------------------------------------------------------------
    | cancellation_strategy: credit_note (type code 381, positive amounts) or
    | negative_invoice (type code 380, negative amounts); null = default of the
    | country pack (Germany: credit_note). Both are permitted in Germany.
    */
    'corrections' => [
        'cancellation_strategy' => env('QUITTIO_CANCELLATION_STRATEGY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshots
    |--------------------------------------------------------------------------
    | Upcasters (classes implementing SnapshotUpcaster) that bring stored invoice
    | snapshots of older versions to the current data model when they are read.
    */
    'snapshots' => [
        'upcasters' => [],
    ],

    'reader' => [
        'max_xml_bytes' => 10 * 1024 * 1024,
        'max_pdf_bytes' => 25 * 1024 * 1024,
        'max_decompressed_bytes' => 50 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Format resolver
    |--------------------------------------------------------------------------
    | driver:        default, or a resolver registered with Quittio::extend('format_resolver', ...)
    |                (its factory receives this array).
    | peppol_lookup: for the default resolver, an invokable class (resolved from the container)
    |                that receives the BuyerParty and returns true when the buyer is reachable
    |                via Peppol; the automatic choice then picks "peppol". null = no lookup.
    */
    'format_resolver' => [
        'driver' => 'default',
        'peppol_lookup' => null,
        'b2c_embed_xml' => false,
        'auto_upgrade_profile' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage, queue and preview
    |--------------------------------------------------------------------------
    */
    'storage' => [
        'disk' => env('QUITTIO_DISK', 'local'),
    ],

    'queue' => [
        'connection' => null,
        'queue' => 'quittio',
    ],

    'preview' => [
        'enabled' => env('QUITTIO_PREVIEW', null),
        'path' => '_quittio/preview',
        'middleware' => ['web'],
    ],
];
