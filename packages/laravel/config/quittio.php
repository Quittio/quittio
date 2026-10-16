<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Quittio configuration
|--------------------------------------------------------------------------
| Every key is explained below. The full reference with all details is at
| https://docs.quittio.dev/configuration. After changing values at runtime
| (e.g. in tests), call Quittio::flush().
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    | Values used when an invoice or a render call does not set them itself.
    */
    'defaults' => [

        // Country pack used when the seller has no country code (BT-40), e.g. 'DE'. Normally
        // the seller's country decides; without an installed pack only EN 16931 rules apply.
        // null = the only installed country pack, if there is exactly one.
        'country' => env('QUITTIO_DEFAULT_COUNTRY', env('QUITTIO_COUNTRY')),

        // Profile of the e-invoice XML for every format that allows it, e.g. 'en16931',
        // 'extended', 'xrechnung'. null = the default of the format (ZUGFeRD: EN 16931).
        // ->profile() on a render call wins.
        'profile' => null,

        // Output format of ->render() without a format: 'zugferd' (PDF with XML),
        // 'xrechnung-cii', 'xrechnung-ubl', 'cii', 'ubl', 'peppol', 'pdf', 'html', 'json'.
        // null or 'auto' = automatic choice (XRechnung for public buyers with Leitweg-ID,
        // PDF for consumers, ZUGFeRD for businesses).
        'format' => null,

        // Language of the invoice (labels, generated texts, number and date formats), e.g.
        // 'de' or 'en', when neither the invoice (->locale()) nor the call sets one.
        // null = the language of the seller's country pack (Germany: 'de').
        'locale' => null,

        // Due date (BT-9) = issue date + these days, for invoices without due date and
        // without payment terms (->dueInDays()); never for credit notes. null = no due date.
        'due_days' => 14,

        // Tax preset of invoice lines without ->vat(), e.g. 'de.reduced' for a bookshop.
        // null = the standard rate of the seller's country (Germany: 'de.standard');
        // small businesses (seller.small_business) get the small business exemption.
        'vat' => env('QUITTIO_DEFAULT_VAT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Country packs
    |--------------------------------------------------------------------------
    | A country pack brings the tax presets, legal rules and texts of a country.
    | Installed packs register themselves (composer.json: extra.quittio.country-packs),
    | quittio/quittio includes Germany. List additional or replacing pack classes here.
    */
    'countries' => [
        // 'AT' => \App\Quittio\CountryAT::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Seller
    |--------------------------------------------------------------------------
    | Your company as it appears on every invoice. Quittio::invoice() and the document
    | methods (Quittio::quote() …) take seller, first bank account and small business
    | setting from here. With quittio/managed, issuer profiles replace this section.
    */
    'seller' => [

        // Legal name of the company (BT-27). Required.
        'name' => env('QUITTIO_SELLER_NAME'),

        // VAT identification number with country prefix, e.g. 'DE123456789' (BT-31).
        'vat_id' => env('QUITTIO_SELLER_VAT_ID'),

        // Tax number from the tax office, e.g. '21/815/08150' (BT-32). German invoices need
        // the VAT identification number or the tax number.
        'tax_number' => env('QUITTIO_SELLER_TAX_NUMBER'),

        // Commercial register number, e.g. 'HRB 12345' (BT-30).
        'legal_registration' => null,

        // Further identifiers of the company (BT-29), e.g. [['value' => '4000001000005',
        // 'scheme' => '0088']] for a GLN. Without a VAT identification number EN 16931
        // needs an identifier here or legal_registration, otherwise rendering fails (BR-CO-26).
        'identifiers' => [],

        // Further legal information printed on the invoice (BT-33), e.g. the court of
        // registration and the managing directors.
        'additional_legal_info' => null,

        // Postal address (BG-5). Country packs may require street, postcode and city (Germany
        // does). The country code also selects the country pack.
        'address' => [
            'line1' => null,        // street and number
            'line2' => null,        // additional address line
            'postcode' => null,
            'city' => null,
            'country' => null,      // ISO 3166-1 alpha-2, e.g. 'DE'; null = defaults.country
        ],

        // Contact person (BG-6), added when at least one value is set. XRechnung requires
        // name, phone and email.
        'contact' => ['name' => null, 'phone' => null, 'email' => null],

        // Electronic address of the seller (BT-34), required by XRechnung and Peppol.
        // scheme: 'EM' = e-mail address, '9930' = German VAT number (Peppol participant ID).
        'electronic_address' => ['value' => null, 'scheme' => 'EM'],

        // Bank accounts. The first entry with an IBAN is put on every invoice as SEPA credit
        // transfer (IBAN and payment QR code in the PDF); bic and name are optional.
        'bank_accounts' => [
            ['iban' => null, 'bic' => null, 'name' => null],
        ],

        // Small business exempt from VAT (e.g. § 19 UStG in Germany): invoices carry no VAT,
        // lines without ->vat() get the country pack's small business exemption with its legal
        // note, and VAT on a line is rejected.
        'small_business' => (bool) env('QUITTIO_SELLER_SMALL_BUSINESS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF and design
    |--------------------------------------------------------------------------
    | The look of the PDF and HTML. The design of a single invoice (->presentation())
    | overrides these values property by property.
    */
    'pdf' => [

        // PDF engine: 'mpdf' (bundled) or a renderer registered with Quittio::extend('renderer', …).
        'renderer' => 'mpdf',

        // Blade view of the invoice layout. Publish and change the default template with
        // php artisan make:quittio-template, or name your own view.
        'template' => 'quittio::templates.default.layout',

        // Further views that ->template() or an invoice may select ('prefix.*' allows every
        // view below a prefix). Views not listed here are refused.
        'templates' => ['invoices.*'],

        // Letter layout with the position of the address field: 'din5008a', 'din5008b',
        // 'free'. null = the default of the country pack (Germany: 'din5008b').
        'layout' => null,

        // Paper size, e.g. 'A4' or 'Letter'. null = the default of the country pack (A4).
        'paper' => null,

        // Create plain PDFs (format 'pdf', quotes, reminders) as PDF/A-3b for archiving.
        // ZUGFeRD is always PDF/A; PDFs with watermark (previews) never.
        'pdfa' => true,

        // Font family. Geist (default; Latin and Cyrillic) and Inter (also Greek) are bundled;
        // register further fonts below.
        'font' => 'geist',

        // Additional TrueType fonts: lower-case family => ['R' => 'Regular.ttf',
        // 'B' => 'Bold.ttf', 'I' => 'Italic.ttf', 'BI' => 'BoldItalic.ttf'].
        'fonts' => [],

        // Directories with the files of 'fonts'; resources/fonts/quittio is searched as well.
        'font_directories' => [],

        // Logo: absolute path, http(s) URL or data URI of a PNG, JPEG or SVG (at most 10 MB).
        // SVG logos in PDF/A need the PHP extension imagick.
        'logo' => null,

        // Colours as hex values: primary = issuer name, title and the total to pay; text = body
        // text and the rules below the column heads and above the total; muted = labels,
        // descriptions and the footer; secondary = section headings and the mark of highlighted
        // lines (null = the primary colour).
        'colors' => ['primary' => '#2741d9', 'text' => '#15171c', 'muted' => '#5f6470', 'secondary' => null],

        // Small sender line above the address field. null = generated from the seller.
        'sender_line' => null,

        // Footer columns, each a list of lines, e.g. [['Muster GmbH', 'Hauptstraße 1'],
        // ['IBAN DE02 …']]. [] = generated from the seller data.
        'footer_columns' => [],

        // Texts of the PDF: salutation above the intro, intro above the lines, closing text
        // below the totals (intro and closing as Markdown). null = no text.
        'texts' => ['salutation' => null, 'intro' => null, 'closing' => null],

        // Print the seller's item number (BT-155) next to the item name.
        'show_item_numbers' => false,

        // Render line descriptions and notes as Markdown. Off by default, because the same
        // texts are in the e-invoice XML, where "*" or "_" stay visible characters.
        'markdown' => false,

        // Print fold and punch marks for window envelopes (at the left edge of the first page).
        'fold_marks' => true,

        // Payment QR code in the PDF.
        // type: 'auto' (Germany: GiroCode, the EPC QR code for banking apps), 'girocode',
        //       'swiss-qr-bill' (needs a generator) or 'none'.
        // girocode_amount: 'due' = amount due, 'discounted' = amount after cash discount.
        // generator: class implementing Quittio\Core\Pdf\Qr\PaymentQrGenerator, or null.
        'payment_qr' => ['type' => 'auto', 'girocode_amount' => 'due', 'generator' => null],

        // File name of rendered results. Placeholders: {title} (e.g. Rechnung), {number},
        // {date} (issue date), {format} and {ext} (pdf, xml, html, json).
        'filename' => '{title}_{number}.{ext}',

        // Temporary directory of mPDF and the cache of prepared logos.
        'cache_path' => storage_path('framework/cache/quittio'),

        // Options of the PDF engines. mPDF: simple_tables drops the borders of table cells
        // (keep it off); pdfa_auto lets mPDF correct PDF/A errors instead of failing.
        'renderers' => [
            'mpdf' => ['simple_tables' => false, 'pdfa_auto' => false],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    | Every invoice is checked before it is created: EN 16931, the rules of the format
    | (XRechnung, Peppol) and of the seller's country pack (e.g. UStG for Germany).
    */
    'validation' => [

        // What happens with errors: 'error' = throw a ValidationException, 'warn' = create the
        // invoice anyway and attach the result ($result->validation()), 'off' = no checks.
        'mode' => env('QUITTIO_VALIDATION_MODE', env('QUITTIO_VALIDATION', 'error')),

        // Which checks run: 'php' (rules of EN 16931, format and country), 'xsd' (XML schema),
        // 'kosit' (the official KoSIT validator, needs Java) and 'verapdf' (PDF/A, needs veraPDF).
        'levels' => ['php', 'xsd'],

        // true = warnings block like errors (in mode 'error').
        'block_on_warnings' => false,

        // Language of the error messages: null = the language of the application
        // (app()->getLocale()), 'invoice' = the language of the invoice, or e.g. 'de'.
        'locale' => env('QUITTIO_VALIDATION_LOCALE'),

        // Checks that the PDF shows every legally required value: 'exception', 'log' or
        // 'off'. null = 'exception' outside production, 'log' in production.
        'mandatory_field_guard' => env('QUITTIO_MANDATORY_FIELD_GUARD', env('QUITTIO_GUARD')),

        // Single PHP rules by the code of their messages; a trailing * matches by prefix.
        // 'disabled' switches rules off, 'severity' changes the severity of their findings
        // (error, warning, info), e.g. ['DE-USTG-15' => 'warning']. Official EN 16931 and Peppol
        // rules (BR-*, PEPPOL-*) need 'allow_official' => true. Own implementations replace
        // a rule with Quittio::extend('rule', 'DE-USTG-15', fn () => new MyRule).
        'rules' => [
            'disabled' => [],
            'severity' => [],
            'allow_official' => false,
        ],

        'drivers' => [

            // KoSIT validator (level 'kosit'), the official validator for XRechnung and Peppol.
            // php artisan quittio:schemas:download installs it and prints these values.
            'kosit' => [
                // 'daemon' = a running validator via HTTP (fast), 'cli' = one Java call per file.
                'mode' => 'daemon',
                // Address of the daemon. The validator listens on 8080 unless started with
                // "-P 8081" (or docker run -p 8081:8080).
                'url' => env('QUITTIO_KOSIT_URL', 'http://127.0.0.1:8081'),
                // Mode 'cli': validator JAR, scenario file and repository of the configuration.
                'jar' => null,
                'scenarios' => null,
                'repository' => null,
                // Further scenario sets, e.g. Peppol BIS: [['scenarios' => …, 'repository' => …]].
                'configurations' => [],
                'java' => 'java',
                'timeout' => 30,
                // true = an unreachable validator is an error, false = a warning.
                'fail_on_unavailable' => true,
            ],

            // veraPDF (level 'verapdf') checks PDF/A: 'cli' = local binary, 'docker' = image.
            'verapdf' => [
                'mode' => 'cli',
                'binary' => 'verapdf',
                'image' => 'verapdf/cli',
                'docker' => 'docker',
                'timeout' => 120,
                'fail_on_unavailable' => true,
            ],

            // Online check of VAT identification numbers with the EU VIES service; answers are
            // cached for cache_ttl seconds.
            'vies' => ['enabled' => false, 'cache_ttl' => 86400, 'timeout' => 5],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Corrections
    |--------------------------------------------------------------------------
    */
    'corrections' => [

        // How cancellations ($result->cancel()) are written: 'credit_note' = type code 381
        // with positive amounts, 'negative_invoice' = type code 380 with negative amounts.
        // null = the default of the country pack (Germany: 'credit_note'); both are permitted.
        'cancellation_strategy' => env('QUITTIO_CANCELLATION_STRATEGY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Calculation
    |--------------------------------------------------------------------------
    | Invoices continued with Quittio::edit() or InvoiceBuilder::from(), and cancellations,
    | carry the declared amounts of the original, which another system may have rounded
    | differently (e.g. per line).
    */
    'calculation' => [

        // 'keep' = keep declared amounts within the tolerances of EN 16931, so that foreign
        // rounding is reversed exactly; 'recalculate' = always recalculate; 'reject' = throw
        // when declared amounts are outside the tolerances. Every mode reports replaced amounts
        // (QUITTIO-DECLARED-AMOUNTS).
        'declared_amounts' => env('QUITTIO_DECLARED_AMOUNTS', 'keep'),

        // Own calculator (class implementing Quittio\Core\Calculation\TotalsCalculator, resolved
        // from the container); null = the EN 16931 calculator with the mode above.
        'calculator' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Snapshots
    |--------------------------------------------------------------------------
    */
    'snapshots' => [

        // Classes implementing Quittio\Core\Serialization\Snapshots\SnapshotUpcaster that bring
        // stored invoices (queued jobs, quittio/managed) of older Quittio versions up to date.
        // Only needed after an update that changes the data model.
        'upcasters' => [],

        // The same for stored documents (queued quotes, reminders and other documents).
        'document_upcasters' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reader
    |--------------------------------------------------------------------------
    | Limits for reading incoming e-invoices (Quittio::read(), validateFile()), as
    | protection against oversized files.
    */
    'reader' => [
        'max_xml_bytes' => 10 * 1024 * 1024,            // largest XML file
        'max_pdf_bytes' => 25 * 1024 * 1024,            // largest PDF file
        'max_decompressed_bytes' => 50 * 1024 * 1024,   // largest unpacked content of a PDF
    ],

    /*
    |--------------------------------------------------------------------------
    | Format resolver
    |--------------------------------------------------------------------------
    | The automatic choice of the format when ->render() gets none.
    */
    'format_resolver' => [

        // 'default' = the built-in choice, or a resolver registered with
        // Quittio::extend('format_resolver', …), which receives this array.
        'driver' => 'default',

        // Invokable class that receives the BuyerParty and returns true when the buyer
        // receives invoices via Peppol; the automatic choice then picks 'peppol'. null = never.
        'peppol_lookup' => null,

        // true = embed the e-invoice XML also into plain PDFs, e.g. for consumers.
        'b2c_embed_xml' => false,

        // true = switch to the profile EXTENDED when an invoice uses its features (e.g. sub
        // lines); false = fail instead.
        'auto_upgrade_profile' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */
    'storage' => [

        // Filesystem disk of $result->save() and of queued renders (config/filesystems.php),
        // e.g. 'local' or 's3'. null or '' = the default disk of config/filesystems.php.
        'disk' => env('QUITTIO_STORAGE_DISK', env('QUITTIO_DISK', 'local')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    | Used by Quittio::make($invoice)->queue()->save(…), which renders in a job.
    */
    'queue' => [

        // Queue connection; null = the default connection.
        'connection' => null,

        // Queue name. Workers must listen on it: php artisan queue:work --queue=quittio,default
        'queue' => 'quittio',

        // Inside a database transaction the job is dispatched after the commit (and not at
        // all after a rollback); false = at once.
        'after_commit' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Preview
    |--------------------------------------------------------------------------
    | A browser preview of the sample invoices at /_quittio/preview, to check the design.
    */
    'preview' => [

        // true = register the preview route, false = never. null = only in the local environment.
        'enabled' => env('QUITTIO_PREVIEW_ENABLED', env('QUITTIO_PREVIEW')),

        // URL prefix of the preview.
        'path' => '_quittio/preview',

        // Middleware of the preview routes, e.g. ['web', 'auth'] outside the local environment.
        // Outside the local environment the gate "viewQuittioPreview" must allow the request:
        // Gate::define('viewQuittioPreview', fn (User $user) => $user->isAdmin());
        'middleware' => ['web'],

        // Rate limit of the preview (renders PDFs): "attempts,minutes"; null = none.
        'throttle' => '30,1',
    ],
];
