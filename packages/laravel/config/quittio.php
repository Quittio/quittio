<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    | country: country pack used when it cannot be derived from the seller (BT-40).
    | profile: null = default of the format (EN 16931 for ZUGFeRD/Factur-X).
    | format:  null = automatic choice by the format resolver (zugferd, xrechnung-cii,
    |          xrechnung-ubl, cii, ubl, peppol, pdf, html, json).
    */
    'defaults' => [
        'country' => env('QUITTIO_COUNTRY', 'DE'),
        'standard' => 'en16931-2017',
        'profile' => null,
        'format' => null,
        'currency' => null,
        'locale' => null,
        'timezone' => null,
        'due_days' => 14,
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
    | Networks and platforms
    |--------------------------------------------------------------------------
    | Transmission via Peppol or national platforms is provided by separate
    | packages. The entries are kept here so that the configuration stays stable.
    */
    'networks' => [
        'mail' => ['enabled' => true],
        'peppol' => ['enabled' => false, 'provider' => null, 'participant_ids' => [], 'lookup_cache_ttl' => 86400],
        'fr-platform' => ['enabled' => false, 'provider' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Seller
    |--------------------------------------------------------------------------
    | Used by Seller::fromConfig() when the Managed layer is not in use.
    */
    'seller' => [
        'name' => env('QUITTIO_SELLER_NAME'),
        'vat_id' => env('QUITTIO_SELLER_VAT_ID'),
        'tax_number' => env('QUITTIO_SELLER_TAX_NUMBER'),
        'legal_registration' => null,
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
    | logo:     absolute path, URL or data URI of a PNG or SVG in target size.
    */
    'pdf' => [
        'renderer' => 'mpdf',
        'template' => 'quittio::templates.default.layout',
        'layout' => null,
        'paper' => 'A4',
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
        'fold_marks' => false,
        'payment_qr' => ['type' => 'auto', 'girocode_amount' => 'due'],
        'filename' => '{title}_{number}.{ext}',
        'cache_path' => storage_path('framework/cache/quittio'),
        'renderers' => [
            'mpdf' => ['simple_tables' => true, 'pdfa_auto' => false],
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
            'verapdf' => ['mode' => 'cli', 'binary' => 'verapdf', 'image' => 'verapdf/cli', 'timeout' => 120, 'fail_on_unavailable' => true],
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

    'reader' => [
        'max_xml_bytes' => 10 * 1024 * 1024,
        'max_pdf_bytes' => 25 * 1024 * 1024,
        'max_decompressed_bytes' => 50 * 1024 * 1024,
    ],

    'format_resolver' => [
        'driver' => 'default',
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

    /*
    |--------------------------------------------------------------------------
    | Managed layer
    |--------------------------------------------------------------------------
    | Numbering, lifecycle, payments, dunning and archive. Not part of this
    | release; the block is kept so that enabling it later needs no new keys.
    */
    'managed' => [
        'enabled' => false,
        'table_prefix' => 'quittio_',
    ],
];
