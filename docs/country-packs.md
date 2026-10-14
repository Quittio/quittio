# Country packs

Everything that differs between countries lives in a country pack: tax presets, national rules and profiles, the automatic format choice, the values a PDF must show, legal texts, numbering and retention rules, dunning law, the cancellation and correction policy, payment QR codes, address formatting and letter layouts. The core stays country-neutral.

A country pack implements `Quittio\Core\Countries\CountryPack`, usually by extending `Quittio\Core\Countries\AbstractCountryPack`, which supplies neutral defaults for every hook. A pack may only add or tighten rules; it never relaxes EN 16931.

The first pack is Germany (`quittio/country-de`, [below](#germany-quittiocountry-de)). The legal wording and references of the packs are proposals that must be confirmed by a tax adviser (see the open decisions in the README).

## How a pack is selected

`DefaultCountryResolver` picks the packs for every run (render, check, prepare, documents, cancellations and the validation of incoming files):

1. **Leading pack (seller side).** The first of these that is set: the country passed to the run (`->country('AT')` on the pending invoice, `RenderOptions::$country`), the seller's country code (BT-40), the configured default country (`quittio.defaults.country`, core config key `country`), otherwise `XX`. The registry returns the installed pack for that code, or a `CountryGeneric` pack.
2. **Buyer pack.** If the buyer's country code (BT-55) is set and differs from the leading pack's code, the installed pack of the buyer's country is added (no generic pack is created for the buyer).

```php
$quittio->make($invoice)->country('DE')->render();   // force the leading pack
```

What each side contributes:

| Hook | Leading pack | Buyer pack (cross-border only) |
|---|---|---|
| `rules()` | applied | – |
| `buyerCountryRules()` | – | applied |
| `formatRules()` | domestic invoices | cross-border business invoices (see [automatic format choice](formats.md#automatic-choice)) |
| `mandatoryOutput()` | merged with the base fields of the mandatory field guard | – |
| `defaults()` (locale, paper, letter layout), `letterLayouts()`, `addressFormatter()`, `paymentQr()` | locale fallback and PDF | – |
| `correctionPolicy()` | used for cancellations (`Quittio::cancellation()`) | – |
| `translationPath()` | registered | registered |

"Domestic" means that seller and buyer lie in the same VAT territory (see [`VatTerritory`](#vatterritory)) or the buyer has no country code. A buyer in a territory excluded from the seller's VAT territory (e.g. Büsingen for Germany) is cross-border, but without an own buyer pack the format rules of the leading pack still apply to it.

Without a pack for the seller's country, `CountryGeneric` applies: EN 16931 rules only, international formatting, neutral defaults. Validation then reports the warning `QUITTIO-GENERIC-COUNTRY` (L3, BT-40): "No country pack is installed for the seller country :value. Only EN 16931 rules are checked; national mandatory information is not validated." `ResolvedCountries::isGeneric()` and `CountryGeneric::isGeneric()` tell this case apart.

With the Managed layer, the issuer's country selects the pack for numbering and retention; the leading pack of an issued invoice is stored with it (`country_pack`) and used again for re-rendering and corrections (see [Managed](managed.md)).

## Registering packs

### Laravel

Packs are found in three places; all of them are instantiated through the container, so constructors may use dependency injection:

1. **Composer metadata.** Every installed package and the root `composer.json` of your application may declare packs. `quittio/country-de` declares itself this way, so `composer require quittio/country-de` (or the meta package `quittio/quittio`) is all you need. Classes that do not exist are skipped.

   ```json
   "extra": { "quittio": { "country-packs": ["Acme\\CountryAT\\CountryAT"] } }
   ```

2. **Configuration.** `quittio.countries` lists further pack classes; keys are ignored (see [configuration](configuration.md)).

   ```php
   'countries' => [
       'AT' => \Acme\CountryAT\CountryAT::class,
   ],
   ```

3. **At runtime.** `Quittio::registerCountry(new CountryAT)` (facade `Quittio\Laravel\Facades\Quittio`), e.g. in a service provider. Packs registered this way win over packs with the same code from the other two sources.

Packs are keyed by `code()`: of two packs with the same code, the one registered last wins. Discovered packs come first, then the configured classes, then packs registered at runtime, so a subclass of `CountryDE` in `quittio.countries` replaces the discovered `CountryDE`.

`php artisan quittio:countries` lists the installed packs with code, class, profiles, number of rules and format rules and the defaults (currency, locale, profile). Without any pack it prints a warning (see [commands](commands.md)).

### Without Laravel

```php
use Quittio\Core\Quittio;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create(['country' => 'DE'], [new CountryDE]);
$quittio->registerCountry(new CountryAT);   // further packs at any time
```

Registering a pack does three things: it adds the pack to the `CountryRegistry` under its upper-case code, registers the pack's `profiles()` in the profile registry (so that formats, `->profile('xrechnung')` and the reader know them) and registers `translationPath()` as the translation namespace `quittio-{code}` (lower case, e.g. `quittio-de`).

`$quittio->countries()` returns the registry: `has($code)`, `get($code)` (installed pack or null), `getOrGeneric($code)` and `all()` (array keyed by code). Codes are case-insensitive.

## The `CountryPack` contract

| Method | Returns | Default in `AbstractCountryPack` | Used by |
|---|---|---|---|
| `code()` | ISO 3166-1 alpha-2 code, e.g. `DE` | – (abstract) | registry key, translation namespace `quittio-{code}` |
| `defaults()` | [`CountryDefaults`](#countrydefaults) | `new CountryDefaults` | locale fallback, paper size, default letter layout |
| `taxPresets()` | `TaxPresetCollection` | empty | lookup of presets by key; presets are never applied automatically |
| `rules()` | `list<Rule>` | `[]` | validation, when the pack leads |
| `buyerCountryRules()` | `list<Rule>` | `[]` | validation, when the pack is the buyer's pack of a cross-border invoice |
| `profiles()` | `list<Profile>` | `[]` | profile registry (national CIUS such as XRechnung) |
| `formatRules()` | `list<FormatRule>` | `[]` | automatic format choice |
| `mandatoryOutput(InvoiceData $invoice)` | [`MandatoryFieldList`](#mandatory-output) | empty | mandatory field guard (leading pack only) |
| `legalTexts()` | [`LegalTextCatalog`](#legaltextcatalog) | empty | the pack's own helpers and your code; Quittio itself does not read it |
| `numberingRules()` | [`NumberingRules`](#numberingrules) | `new NumberingRules` | Managed numbering |
| `retention()` | [`RetentionRule`](#retentionrule) | `new RetentionRule(10)` | Managed retention |
| `dunningLaw()` | [`DunningLaw`](#dunninglaw) | `new DunningLaw` | informational; not read by Quittio in this release |
| `correctionPolicy()` | [`CorrectionPolicy`](#correctionpolicy) | `new CorrectionPolicy` | `Quittio::cancellation()`, Managed corrections |
| `paymentQr()` | `?PaymentQrGenerator` | `null` | payment QR code in mode `auto` (after a generator registered with `withPaymentQrGenerator()`); null falls back to `CountryDefaults::$paymentQr` (EPC GiroCode for `girocode`, no code for `none`) |
| `addressFormatter()` | `AddressFormatter` | `InternationalAddressFormatter` | address lines of the view model |
| `letterLayouts()` | `array<string, LetterLayout>` | `['free' => LetterLayout::free()]` | position of the address field and marks |
| `exporters()` | `list<Exporter>` | `[]` | declared for accounting exports of the Managed layer (`Exporter::key()`); not read in this release |
| `readers()` | `list<FormatReader>` | `[]` | readers for national formats; `read()`, `readFile()` and `validateFile()` try them after the built-in CII and UBL readers, for every registered pack |
| `translationPath()` | `?string` | `null` | directory with `{locale}/{group}.php` files, registered as namespace `quittio-{code}` |

### CountryDefaults

`new CountryDefaults(currency: 'EUR', timezone: 'UTC', locale: 'en', paper: 'A4', letterLayout: 'free', profile: 'en16931', requiredLocales: [], paymentQr: 'girocode')`

| Property | Default | Effect |
|---|---|---|
| `locale` | `en` | Last fallback for the render and validation locale: run option, invoice locale (`->locale()`), `quittio.defaults.locale`, then this value. |
| `paper` | `A4` | Paper size unless the presentation or `pdf.paper` sets one. |
| `letterLayout` | `free` | Key of `letterLayouts()` used when the presentation sets none (`pdf.layout`). |
| `currency`, `profile` | `EUR`, `en16931` | Shown by `quittio:countries`; not applied to invoices. |
| `timezone`, `requiredLocales` | `UTC`, `[]` | Informational metadata for applications; not applied by the core. Set the time zone of dates with `->timezone()` on the builder. |
| `paymentQr` | `girocode` | QR code of the mode `auto` when the pack has no `paymentQr()` generator: `girocode` = EPC GiroCode, `none` = no code. A generator registered with `withPaymentQrGenerator()` comes first. |

### CorrectionPolicy

Default handling of cancellations and corrections (see [Managed: cancellations and corrections](managed.md#cancellations-and-corrections)).

| Member | Meaning |
|---|---|
| `CANCELLATION_CREDIT_NOTE` = `credit_note` | cancel with type code 381 and positive amounts |
| `CANCELLATION_NEGATIVE_INVOICE` = `negative_invoice` | cancel with type code 380 and negative amounts |
| `CORRECTION_CANCEL_AND_REISSUE` = `cancel_and_reissue` | correct by cancelling and issuing a new invoice |
| `CORRECTION_CORRECTED_INVOICE` = `corrected_invoice` | correct with a corrected invoice (type code 384) |
| `__construct(string $cancellationStrategy = 'credit_note', string $correctionStrategy = 'cancel_and_reissue', array $titles = [])` | `$titles`: document kind => [locale => title] |
| `title(string $kind, string $locale = 'en'): ?string` | title in the locale, else English, else null |
| `cancellationTitle(bool $selfBilled, string $locale = 'en'): ?string` | `cancellation_self_billed` for self-billed originals if defined, otherwise `cancellation` |

Kinds read by Quittio: `cancellation` and `cancellation_self_billed` (title of `Quittio::cancellation()`), `correction` (title of a Managed corrected invoice), `self_billed` (PDF/HTML title and file name of self-billed invoices, after a title set in the presentation). The sign strategy of a cancellation comes from the call, then `quittio.corrections.cancellation_strategy`, then the pack; the correction strategy from `quittio-managed.correction.strategy`, then the pack.

### DunningLaw

Statutory late payment values; amounts and percentage points are decimal strings.

| Property | Default | Meaning |
|---|---|---|
| `b2bInterestPoints` | `8.00` | points above the reference rate for business debtors |
| `b2cInterestPoints` | `0.00` | points above the reference rate for consumers |
| `b2bFlatRate` | `40.00` | flat rate for business debtors (null = none) |
| `dayCount` | `365` | day count basis |
| `consumerDefaultAfterDays` | `null` | days after which a consumer is in default |
| `referenceRateName` | `null` | name of the reference rate |

Quittio's documents do not read these values; country packs use them in their own interest calculators (Germany: [`DefaultInterest`](#default-interest)).

### NumberingRules

Used by Managed numbering (see [Managed: numbering](managed.md#numbering)).

| Property | Default | Meaning |
|---|---|---|
| `defaultPattern` | `INV-{Y}-{seq:4}` | pattern when neither the issuer nor the configuration sets one |
| `defaultReset` | `yearly` | reset when none is configured |
| `allowedResets` | `['never', 'yearly', 'monthly']` | other resets are rejected with a `ConfigurationException` |
| `gapless` | `true` | declared; not read in this release (see [gapless numbers](managed.md#gapless-numbers)) |
| `numberConsumedOnRejection` | `true` | declared; not read in this release |

### RetentionRule

`new RetentionRule(int $years, bool $fromEndOfCalendarYear = true)`. `until(Date $issueDate): Date` returns the last day of the retention period: 31 December of the issue year plus `$years`, or, with `fromEndOfCalendarYear: false`, the issue date plus `$years`. The Managed layer stores this date with every issued invoice; `quittio-managed.retention.years` may only lengthen it.

### LegalTextCatalog

`new LegalTextCatalog(array $texts = [], string $fallbackLocale = 'en')` with `$texts` as key => [locale => text].

- `has(string $key): bool`
- `get(string $key, ?string $locale = null, array $replace = []): ?string`: the text in `$locale`, else in the fallback locale, else the first variant; null for unknown keys. `:name` placeholders are replaced from `$replace`.

### Mandatory output

`mandatoryOutput()` names values the rendered PDF must show for one invoice, in addition to the base fields every PDF needs. After rendering, the mandatory field guard checks that the template printed them (see [templates: mandatory field guard](templates.md#mandatory-field-guard)).

`new MandatoryField(string $accessKey, string $description, ?string $legalBasis = null)`. The access key is a view model path:

- `|` separates alternatives, `&` combines paths that must all be printed;
- `[*]` stands for every index, e.g. `vatRows[*].amount`;
- a path counts only where the view model has a value: null values and empty lists are not required, and a field whose alternatives all have no value is skipped.

`MandatoryFieldList::merge()` combines two lists; for equal access keys the later field wins. `MandatoryFieldGuard::notePath($invoice, $matches)` returns the view model path of the first note matching a callback (`legalNotes[i]` for regulatory, legal and tax notes, `notes[i].content|notes[i].html` otherwise), so a pack can require a specific note.

### Letter layouts

`letterLayouts()` returns `Quittio\Core\View\LetterLayout` objects by name; `CountryDefaults::$letterLayout` names the default, the presentation property `letterLayout` (Laravel: `pdf.layout`) another one. `free` is always available; an unknown name throws a `ConfigurationException`. Values are millimetres from the top left corner of the first page:

| Property | Default | Meaning |
|---|---|---|
| `name` | – | layout key |
| `addressTop`, `addressLeft` | 45.0, 20.0 | position of the address field |
| `addressWidth`, `addressHeight` | 85.0, 45.0 | size of the address field |
| `senderLineHeight` | 5.0 | height of the sender line above the recipient |
| `addressZoneTop` | 17.7 | distance of the recipient address from the top of the field |
| `foldMarks` | `[]` | distances of the fold marks from the top edge |
| `punchMark` | null | distance of the punch mark |

`letterheadHeight(float $pageMarginTop)` returns the space above the address field (`addressTop` minus the top page margin of 20 mm in the default templates). See [templates: letter layouts](templates.md#letter-layouts) for how the default templates use them.

### VatTerritory

`Quittio\Core\Countries\VatTerritory` tells the EU VAT territory (Art. 5 to 7 of Directive 2006/112/EC) from a country code and a postcode. The format resolver, the German rules and the core plausibility checks use it; packs can use it in their own rules.

| Method / constant | Result |
|---|---|
| `EU_MEMBER_STATES` | the 27 member states (Greece is `GR`) |
| `NORTHERN_IRELAND` | `XI` |
| `vatCountry(string $country): string` | the country whose VAT territory a country belongs to: `MC` → `FR`, `IM` → `GB`, `EL` → `GR` |
| `isEuMemberState(string $country): bool` | member state after `vatCountry()` |
| `excludedTerritory(string $country, ?string $postcode): ?string` | name of an excluded territory the address lies in, or null |
| `isEuVatTerritory(string $country, ?string $postcode = null, bool $goods = true): bool` | address inside the EU VAT territory |
| `memberState(string $country, ?string $postcode = null, bool $goods = true): ?string` | member state of the address (`XI` for Northern Ireland and goods), null outside |
| `sameVatTerritory(string $country, ?string $postcode, string $otherCountry, ?string $otherPostcode): bool` | same VAT territory, e.g. `FR` and `MC`, but not `DE` and Büsingen |
| `isNorthernIreland(string $country, ?string $postcode = null): bool` | `XI`, or `GB` with a `BT` postcode |
| `memberStateOfVatId(string $vatId): ?string` | member state of a VAT identifier prefix (`EL` → `GR`, `XI` stays `XI`), null otherwise (also for the prefix `GR`) |

Northern Ireland belongs to the territory for goods only (`$goods = false` excludes it). Excluded territories recognised by postcode: Büsingen am Hochrhein (78266) and Heligoland (27498) in Germany; the Canary Islands (35xxx, 38xxx), Ceuta (51xxx) and Melilla (52xxx); Livigno (23041) and Campione d'Italia (22061); the Åland Islands (22xxx in FI); Mount Athos (63086); the French overseas territories (97xxx, 981xx to 989xx; Monaco 980xx stays France). Territories without a distinct postcode (e.g. the part of Cyprus not controlled by the government) cannot be detected.

## Writing your own country pack

1. Create a Composer package that requires `quittio/core`.
2. Extend `AbstractCountryPack` and override only what differs: `code()` is the one required method.
3. Add tax presets, rules, format rules, mandatory output, layouts and texts as needed.
4. Ship translations in `resources/lang/{locale}/rules.php` and return the directory from `translationPath()`.
5. Declare the pack in `composer.json` (`extra.quittio.country-packs`) so that the Laravel integration finds it.
6. Test it with example invoices and, where they exist, the official validation artefacts of the country, and have the legal content reviewed by a local tax adviser.

A minimal pack (values for illustration only):

```php
namespace Acme\CountryAT;

use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Countries\CountryDefaults;
use Quittio\Core\Countries\MandatoryField;
use Quittio\Core\Countries\MandatoryFieldList;
use Quittio\Core\Countries\RetentionRule;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Tax\CustomTaxPreset;
use Quittio\Core\Tax\TaxPresetCollection;
use Quittio\Core\Validation\Rules\CallbackRule;
use Quittio\Core\Validation\Rules\Inspect;
use Quittio\Core\Validation\Rules\RuleReporter;

final class CountryAT extends AbstractCountryPack
{
    public function code(): string
    {
        return 'AT';
    }

    public function defaults(): CountryDefaults
    {
        return new CountryDefaults(currency: 'EUR', timezone: 'Europe/Vienna', locale: 'de');
    }

    public function taxPresets(): TaxPresetCollection
    {
        return new TaxPresetCollection([
            CustomTaxPreset::make('at.standard_20', VatCategory::StandardRate, '20'),
        ]);
    }

    public function rules(): array
    {
        return [
            CallbackRule::l3('ACME-AT-01', 'The seller VAT identifier (BT-31) must be stated.', function (InvoiceData $i, RuleReporter $report): void {
                if (! Inspect::filled($i->seller->vatId)) {
                    $report->error('BT-31', 'seller.vatId');
                }
            }, 'quittio-at'),
        ];
    }

    public function formatRules(): array
    {
        return [new ConsumerPdfRule];
    }

    public function mandatoryOutput(InvoiceData $invoice): MandatoryFieldList
    {
        return new MandatoryFieldList([new MandatoryField('seller.vatId', 'VAT identifier of the seller')]);
    }

    public function retention(): RetentionRule
    {
        return new RetentionRule(7);
    }

    public function translationPath(): string
    {
        return dirname(__DIR__).'/resources/lang';
    }
}
```

```php
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Formats\FormatChoice;
use Quittio\Core\Formats\FormatRegistry;
use Quittio\Core\Formats\FormatRule;

final class ConsumerPdfRule implements FormatRule
{
    public function resolve(InvoiceData $invoice): ?FormatChoice
    {
        return $invoice->buyer->isConsumer
            ? new FormatChoice(FormatRegistry::PDF, null, 'Invoice to a consumer.')
            : null;   // null: the next rule or the default choice decides
    }
}
```

`resources/lang/de/rules.php`:

```php
return [
    'ACME-AT-01' => [
        'message' => 'Die UID des Verkäufers (BT-31) muss angegeben sein.',
        'hint' => 'Setzen Sie ->vatId() beim Verkäufer.',
    ],
];
```

### Presets of your pack

A preset implements `Quittio\Core\Tax\TaxPreset`:

| Method | Returns |
|---|---|
| `key(): string` | stable key prefixed with the country code, e.g. `at.standard_20` |
| `category(): VatCategory` | VAT category (BT-151) |
| `rate(): ?BigDecimal` | rate in percent, null for category O |
| `exemptionReasonCode(): VatExemptionReasonCode\|RawCode\|null` | VATEX code (BT-121) |
| `exemptionReason(?string $locale = null): ?string` | exemption reason text (BT-120), shown in XML and PDF |

`CustomTaxPreset::make(string $key, VatCategory $category, BigDecimal|int|string|null $rate = null, VatExemptionReasonCode|RawCode|string|null $code = null, array|string|null $reason = null)` builds one ad hoc; `$reason` is a text or locale => text (a plain string counts as English; without a locale the English text is used). For many presets a backed enum is convenient, as `Quittio\Country\DE\Tax\VatPreset` shows. `TaxPresetCollection` offers `add()`, `has($key)`, `get($key)` (throws `InvalidArgumentException` for unknown keys), `count()` and iteration.

A preset is applied per line with `->vat($preset)`; the line stores category, rate, reason, code and the preset key (`LineVat::$presetKey`), so that rules can recognise it (see [Building invoices](building-invoices.md)).

### Rules of your pack

A rule implements `Quittio\Core\Validation\Rule` (`code()`, `level()`, `check(InvoiceData, ValidationContext): iterable<ValidationMessage>`). `CallbackRule::l3($code, $fallback, $check, $namespace)` (business rules) and `CallbackRule::l7(...)` (plausibility) cover most cases. The closure receives the invoice and a `RuleReporter`:

- `error(?string $bt = null, ?string $path = null, array $replace = [])`, `warning(...)` and `info(...)` add a finding with business term and data path;
- `context()` returns the `ValidationContext`: `profile`, `countryPacks` (leading pack first), `locale`, `option($key)` (e.g. `option('syntax')` with the `Syntax` that will be written), `profileIs(...$keys)`, `leadingCountry()`, `hasCountry($code)`.

Messages come from the catalog key `{namespace}::rules.{code}` with the entries `message` and `hint`, in the validation locale or English; without an entry the English `$fallback` is used. `:name` placeholders are replaced from `$replace`. Use the namespace `quittio-{code}` that registration gives your `translationPath()`. Rules of the pack run in every format, also for a plain PDF; profile specific rules belong to a profile class (see [formats](formats.md)). See [validation](validation.md) for levels and results.

### Format rules of your pack

`Quittio\Core\Formats\FormatRule::resolve(InvoiceData $invoice): ?FormatChoice` returns a `FormatChoice(string $format, ?string $profile = null, ?string $reason = null)` or null. Rules run in order, the first non-null choice wins. The resolver consults the leading pack for domestic invoices and the buyer's pack for cross-border invoices to businesses; an explicit format always wins. A choice of `zugferd` or `cii` is upgraded to EXTENDED when EXTENDED features are used.

### Other hooks of your pack

- `paymentQr()`: a `Quittio\Core\Pdf\Qr\PaymentQrGenerator` (`key()`, `supports(InvoiceData)`, `payload(InvoiceData, array $options = []): ?string`) for the QR mode `auto`.
- `addressFormatter()`: a `Quittio\Core\View\AddressFormatter` (`format(Address $address, array $nameLines = [], ?string $senderCountry = null, ?string $locale = null): list<string>`).
- `profiles()`: national profiles, usually extending `Quittio\Core\Profiles\AbstractProfile`; they are registered with the pack.

## Germany (`quittio/country-de`)

`Quittio\Country\DE\CountryDE` (code `DE`, translation namespace `quittio-de`) covers the German VAT act (UStG, UStDV), XRechnung and German business letter conventions. It is discovered automatically in Laravel; without Laravel pass `new CountryDE` to `Quittio::create()`. The builder macros are registered as soon as Composer loads the package.

| Hook | Germany |
|---|---|
| `defaults()` | currency `EUR`, time zone `Europe/Berlin`, locale `de`, paper `A4`, letter layout `din5008b`, profile `en16931`, payment QR `girocode` |
| `profiles()` | [`XRechnung30`, `XRechnung30Extension`](#xrechnung-profiles) |
| `taxPresets()` | every case of [`VatPreset`](#tax-presets) |
| `rules()` | [UStG rules](#ustg-rules) `DE-USTG-01` to `DE-USTG-13` and the [Leitweg-ID checks](#leitweg-id) `DE-LEITWEG-ID` and `DE-LEITWEG-ID-SUSPECT` |
| `buyerCountryRules()` | none |
| `formatRules()` | [`GermanFormatRule`](#format-choice) |
| `mandatoryOutput()` | [mandatory PDF output](#mandatory-pdf-output) |
| `legalTexts()` | [`LegalTexts::catalog()`](#legal-texts) |
| `numberingRules()` | pattern `RE-{Y}-{seq:4}`, reset `yearly`, resets `never`, `yearly`, `monthly`, gapless |
| `retention()` | [8 years from the end of the calendar year](#retention) |
| `dunningLaw()` | [§ 288 BGB values](#dunning-law) |
| `correctionPolicy()` | [credit note, cancel and reissue, German titles](#cancellations-and-corrections) |
| `letterLayouts()` | [`din5008a`, `din5008b`, `free`](#din-5008-letter-layouts) |
| `paymentQr()`, `addressFormatter()` | defaults: EPC GiroCode, international address format |
| `translationPath()` | `resources/lang` of the package |
| `errorClassification()` | [BMF error classes](#bmf-error-classes) (additional method) |

### Tax presets

`Quittio\Country\DE\Tax\VatPreset` is a string-backed enum implementing `TaxPreset`. Apply it per line:

```php
use Quittio\Country\DE\Tax\VatPreset;

->line(fn ($l) => $l->name('Webentwicklung')->quantity(24, UnitCode::Hour)->netPrice('95.00')->vat(VatPreset::Standard19))
->line(fn ($l) => $l->name('Heilbehandlung')->quantity(1, UnitCode::Piece)->netPrice('90.00')->vat(VatPreset::exempt('§ 4 Nr. 14 UStG', 'VATEX-EU-132-1C')))
```

| Case | Key | Category | Rate | Code (BT-121) | Use |
|---|---|---|---|---|---|
| `Standard19` | `de.standard_19` | `S` | 19 | – | standard rate |
| `Reduced7` | `de.reduced_7` | `S` | 7 | – | reduced rate |
| `ZeroRatedPv` | `de.zero_rated_pv` | `Z` | 0 | – | 0 % rate for photovoltaic systems (section 12 (3) UStG); the rate itself is the statement, no exemption reason (BR-Z-10) |
| `SmallBusiness` | `de.small_business` | `E` | 0 | – | small business exemption (section 19 (1) UStG), see [small businesses](#small-businesses) |
| `ReverseChargeDomestic` | `de.reverse_charge_domestic` | `AE` | 0 | `VATEX-EU-AE` | domestic reverse charge (section 13b UStG) |
| `ReverseChargeEu` | `de.reverse_charge_eu` | `AE` | 0 | `VATEX-EU-AE` | service whose recipient in another member state owes the VAT; both VAT identifiers required (`DE-USTG-11`) |
| `IntraCommunitySupply` | `de.intra_community_supply` | `K` | 0 | `VATEX-EU-IC` | VAT-exempt intra-Community supply (section 4 no. 1b with section 6a UStG), also of new vehicles (`newVehicle()`) |
| `TriangularTransaction` | `de.triangular_transaction` | `AE` | 0 | `VATEX-EU-AE` | intra-community triangular transaction (section 25b UStG), wording of section 14a (7) UStG; both VAT identifiers required |
| `Export` | `de.export` | `G` | 0 | `VATEX-EU-G` | VAT-exempt export supply (section 4 no. 1a with section 6 UStG) |
| `NotTaxableInGermany` | `de.not_taxable` | `O` | none | `VATEX-EU-O` | supply not taxable in Germany |
| `MarginSecondHand` | `de.margin_second_hand` | `E` | 0 | `VATEX-EU-F` | margin scheme, second-hand goods |
| `MarginArt` | `de.margin_art` | `E` | 0 | `VATEX-EU-I` | margin scheme, works of art |
| `MarginAntiques` | `de.margin_antiques` | `E` | 0 | `VATEX-EU-J` | margin scheme, collectors' items and antiques |
| `TravelAgency` | `de.travel_agency` | `E` | 0 | `VATEX-EU-D` | special scheme for travel agents |
| `VatPreset::exempt(string $paragraph, $code = null)` | `de.exempt` | `E` | 0 | `$code` (enum, raw code or string) | any other exemption, e.g. `§ 4 Nr. 14 UStG` |

Exemption reason texts (BT-120), from `resources/lang/{de,en}/tax.php`:

| Key | German | English |
|---|---|---|
| `de.small_business` | Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer). | VAT-exempt supply under section 19 (1) UStG (small business exemption). |
| `de.reverse_charge_domestic` | Steuerschuldnerschaft des Leistungsempfängers (§ 13b UStG) | Reverse charge, the recipient is liable for VAT (section 13b UStG) |
| `de.reverse_charge_eu` | Steuerschuldnerschaft des Leistungsempfängers | Reverse charge, the recipient is liable for VAT |
| `de.intra_community_supply` | Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG) | VAT-exempt intra-Community supply (section 4 no. 1b in conjunction with section 6a UStG) |
| `de.triangular_transaction` | Innergemeinschaftliches Dreiecksgeschäft nach § 25b UStG; Steuerschuldnerschaft des letzten Abnehmers | Intra-Community triangular transaction (section 25b UStG); the last customer is liable for the VAT |
| `de.export` | Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i. V. m. § 6 UStG) | VAT-exempt export supply (section 4 no. 1a in conjunction with section 6 UStG) |
| `de.not_taxable` | Nicht im Inland steuerbare Leistung | Service not taxable in Germany |
| `de.margin_second_hand` | Gebrauchtgegenstände/Sonderregelung | Second-hand goods/special arrangements |
| `de.margin_art` | Kunstgegenstände/Sonderregelung | Works of art/special arrangements |
| `de.margin_antiques` | Sammlungsstücke und Antiquitäten/Sonderregelung | Collectors' items and antiques/special arrangements |
| `de.travel_agency` | Sonderregelung für Reisebüros | Special arrangements for travel agents |
| `de.exempt` | Steuerfrei nach :paragraph | Exempt from VAT according to :paragraph |

`Standard19`, `Reduced7` and `ZeroRatedPv` carry no text (BR-S-10, BR-Z-10). The English texts follow the terms of Directive 2006/112/EC ("exempt", "intra-Community supply", "Reverse charge"); the [legal texts](#legal-texts) of the same notices use the same wording.

How presets behave:

- The text is taken in the invoice locale (`->locale()` on the builder) when the line is built. The enum presets and `VatPreset::exempt()` (a `Quittio\Country\DE\Tax\CustomVatPreset`) default to German when the invoice has no locale; other locales without a text fall back to English.
- The preset key stays on the line (`$line->vat->presetKey`); `DE-USTG-09`, `DE-USTG-11` and small business detection rely on it.
- Category, rate, code and text flow into the VAT breakdown (BG-23). Different texts in one category and rate are joined in BT-120; BT-121 is kept only when the codes agree (warning `QUITTIO-EXEMPTION-CONFLICT`).
- In the PDF, the BT-120 texts of all non-standard categories are listed in `legalNotes`. Margin scheme and travel agency rows show no VAT amount (section 14a (6) UStG).
- Document level allowances and charges take a category and rate, not a preset: `->charge(VatCategory::StandardRate, '19', amount: '10.00', reason: 'Versand')`.
- Look presets up by key, e.g. from a database column: `VatPreset::from('de.standard_19')` or `(new CountryDE)->taxPresets()->get('de.standard_19')`. `de.exempt` is not part of the collection.

### UStG rules

`Quittio\Country\DE\Rules\UstgRules::all()` (level L3) applies to every invoice of a German seller, in every format including a plain PDF. Messages and hints are in German and English.

| Rule | Severity (BT, path) | Reported when | Legal basis named | Fix |
|---|---|---|---|---|
| `DE-USTG-01` | error (BG-5 `seller.address`, BG-8 `buyer.address`) | The seller lacks street (address line 1), postcode or city; or the buyer lacks street or city, or a buyer in `DE` lacks the postcode. The buyer part is skipped for small amount invoices. | § 14 Abs. 4 Nr. 1 UStG | Add street, postcode and city for seller and buyer. |
| `DE-USTG-02` | error (BT-31 `seller.vatId`) | The seller has neither a VAT identifier (BT-31) nor a tax number (BT-32) and there is no tax representative (BG-11). Skipped for small amount invoices. | § 14 Abs. 4 Nr. 2 UStG | `->vatId()` or `->taxNumber()` on the seller. |
| `DE-USTG-03` | error (BT-72 `delivery.actualDeliveryDate`); warning for prepayment invoices (386) | No delivery date (BT-72), no invoicing period (BG-14) and not every line has a period (BG-26). Skipped for small amount invoices (§ 33 UStDV) and small business invoices (§ 34a UStDV). | § 14 Abs. 4 Nr. 6 UStG | `->deliveryDate()` (also when it equals the invoice date), `->invoicingPeriod()` or a `->period()` on every line. |
| `DE-USTG-04` | error (BT-22 `notes`) | A self-billed type code (389, 261, 527) and no note contains "Gutschrift" or one of: self-billing, self billing, self-billed invoice, autofacturation, autofactura, autofatturazione, autofacturação, zelffacturering, samofakturowanie (case-insensitive). | § 14 Abs. 4 Nr. 10 UStG; other EU languages: UStAE 14.5 Abs. 24 | `->selfBilled()` adds the note. |
| `DE-USTG-05` | error (BT-22 `notes`) | The flag `de.property_related_service` is set and no note contains "14b". | § 14 Abs. 4 Nr. 9 UStG | `->propertyRelatedService()` adds the note. |
| `DE-USTG-06` | warning per VAT row (BT-120 `vatBreakdown[i].exemptionReason`) | A VAT breakdown row of category `AE` whose exemption reason contains neither "Steuerschuldnerschaft" nor the text of one of the presets `ReverseChargeDomestic`, `ReverseChargeEu` and `TriangularTransaction` in a locale the pack ships (`de`, `en`), the invoice locale or the validation locale (case-insensitive). | § 14a Abs. 1 und 5 UStG | Use `ReverseChargeDomestic`, `ReverseChargeEu` or `TriangularTransaction`. |
| `DE-USTG-07` | error per line (BG-32 `lines[i].item.attributes`) | The flag `de.new_vehicle` is set and a line has no item attributes. | § 14a Abs. 4 UStG | `->attribute('Erstzulassung', …)`, `->attribute('Kilometerstand', …)` on every line. |
| `DE-USTG-08` | error (BT-112 `totals.grossTotal`) | The flag `de.small_amount` is set and the gross total exceeds 250.00 EUR. Other currencies count with their EUR equivalent (gross total × exchange rate of `->taxCurrency('EUR', $rate)`); without that rate the rule always fails. | § 33 UStDV | Remove `->smallAmount()` and issue a full invoice. |
| `DE-USTG-09` | error (BT-151 `lines`, or BT-102 `charges` for document level entries) | A line uses `VatPreset::SmallBusiness` or the invoice has the small business flag, and a line (sub lines included) or a document level allowance or charge has category `S`. | § 19 UStG; VAT shown on charges counts too (§ 14c Abs. 2 UStG) | `VatPreset::SmallBusiness` on every line, no standard rated charges. |
| `DE-USTG-10` | warning (BT-6 `taxCurrency`) | The invoice currency is not EUR, the VAT total is not zero and the VAT accounting currency (BT-6) is not EUR. | – | `->taxCurrency('EUR', $rate, $date)`. |
| `DE-USTG-11` | error (BT-31 `seller.vatId`, BT-48 `buyer.vatId`) | A line uses `ReverseChargeEu` or `TriangularTransaction`, or a line of category `AE` without preset has a buyer in the VAT territory of another member state (services: Monaco counts as France, Northern Ireland does not count), and the seller's VAT identifier (or the tax representative's) or the buyer's VAT identifier is missing. A tax number is not sufficient. | § 14a Abs. 1 und 7 UStG | `->vatId()` for seller and buyer. |
| `DE-USTG-12` | error (BT-151 `lines`); warning for possible distance sales | The flag `de.small_amount` is set and category `AE` or `K` is used (error), or the buyer is a consumer and the destination (deliver-to address if it has a country, otherwise the buyer's address) lies in another member state (warning: a distance sale only if goods are transported there). | § 33 Satz 3 UStDV; § 3c UStG | Remove `->smallAmount()` and issue a full invoice. |
| `DE-USTG-13` | warning (BT-151 `lines`) | The flag `de.new_vehicle` is set and no line, allowance or charge uses category `K`. A warning, as the exemption may not apply in the individual case. | § 14a Abs. 4 UStG | `VatPreset::IntraCommunitySupply` on the vehicle lines, or remove `->newVehicle()`. |

Helpers used by the rules and available to you: `UstgRules::isSmallAmountInvoice($invoice)`, `UstgRules::isSmallBusinessInvoice($invoice)` and `UstgRules::hasServiceDate($invoice)`.

#### Leitweg-ID

`UstgRules::plausibility()` (level L7) checks buyer references (BT-10 `buyerReference`) shaped like a Leitweg-ID whose check digits are wrong:

| Rule | Severity | Reported when |
|---|---|---|
| `DE-LEITWEG-ID` | warning | the buyer is a public buyer: the profile is `xrechnung` or `xrechnung-extension` or the buyer's electronic address (BT-49) uses scheme `0204`. "The check digits of the Leitweg-ID ":value" (BT-10) are wrong." |
| `DE-LEITWEG-ID-SUSPECT` | info | any other buyer in Germany (country `DE` or none) that is not a consumer. "The buyer reference ":value" (BT-10) is shaped like a Leitweg-ID, but its check digits are wrong. It is not treated as a Leitweg-ID, so the invoice is not issued as XRechnung automatically." |

A mistyped Leitweg-ID thus does not lead to ZUGFeRD unnoticed; an order number of the same shape (e.g. `4711-01`) gets the same info, which you may ignore. Neither finding changes the format.

`Quittio\Country\DE\Support\LeitwegId`:

| Method | Result |
|---|---|
| `looksLike(?string $value): bool` | coarse address of 2 to 12 digits, optional fine address of up to 30 letters or digits, two check digits, separated by hyphens |
| `isValid(?string $value): bool` | shape and check digits (ISO 7064 Mod 97-10) are correct |
| `checkDigits(string $address): string` | the two check digits for coarse and fine address without hyphens, e.g. `checkDigits('0401100012345')` → `03` |

#### Tax number

`UstgRules::plausibility()` also checks the tax number (Steuernummer, BT-32) of a seller in Germany:

| Rule | Severity | Reported when |
|---|---|---|
| `DE-TAX-NUMBER` | warning (BT-32 `seller.taxNumber`) | the seller's country is `DE` and the tax number is neither a valid 13-digit ELSTER number, a 12-digit federal number nor the notation of a federal state, or its check digit is wrong; also when a VAT identifier (`DE…`) was given as tax number. A Bavarian notation without the tax office number (`815/08152`) cannot be checked and is accepted. |

The checks follow "Prüfung der Steuer- und Steueridentifikationsnummer" of ELSTER (Bayerisches Landesamt für Steuern): the ELSTER format `FFFF0BBBUUUUP` (NRW `FFFF0BBBBUUUP`) with the 0 at the 5th position, the excluded districts (000, 998, 999; at least 100 in the Bavarian group; NRW 0000, 0998, 0999 and `UUUP` above 0009) and the check digit procedure of the state: modified 11 procedure (Rhineland-Palatinate), 2 procedure (Baden-Württemberg, Hesse, Schleswig-Holstein), 11 procedure with the factors of the state (all others; Berlin with the variants A and B by tax office and district). Whether the tax office exists is not checked. A notation without the state (e.g. `93/815/08150`) is valid when it is valid for any state that uses this notation.

`Quittio\Country\DE\Support\TaxNumber`:

| Method | Result |
|---|---|
| `TaxNumber::parse(string $value, ?string $state = null): ?TaxNumber` | the valid number in any notation (`198/815/08152`, `9198081508152`, `919881508152`, `053 815 08158` …), or null; `$state` (ISO 3166-2:DE, e.g. `BY`) restricts a state notation to that state |
| `TaxNumber::validate(string $value, ?string $state = null): ?bool` | `true` valid, `false` invalid, `null` plausible but not checkable |
| `TaxNumber::looksLikeVatId(string $value): bool` | a VAT identifier (`DE` + 9 digits) |
| `->elster`, `->state`, `->formatted()` | 13-digit ELSTER format, state, notation on tax notices (e.g. `198/815/08152`, `400/8150/8159`) |

### XRechnung rules

`Quittio\Country\DE\Rules\XRechnungRules` (level L3) are the rules of the XRechnung profiles: they apply whenever the profile is `xrechnung` or `xrechnung-extension`, whatever the seller's country. Severity follows the flags of the official Schematron: "muss" rules (`fatal`) are errors, "soll" rules (`warning`) warnings and `BR-DE-TMP-32` (`information`) an info; the KoSIT validator remains authoritative (see [validation](validation.md)).

| Rule | Severity | Reported when |
|---|---|---|
| `BR-DE-1` | error | no payment instructions (BG-16) |
| `BR-DE-2` | error | no seller contact (BG-6) |
| `BR-DE-3`, `BR-DE-4` | error | seller city (BT-37) or postcode (BT-38) missing |
| `BR-DE-5`, `BR-DE-6`, `BR-DE-7` | error | seller contact without name (BT-41), phone (BT-42) or e-mail (BT-43) |
| `BR-DE-8`, `BR-DE-9` | error | buyer city (BT-52) or postcode (BT-53) missing |
| `BR-DE-10`, `BR-DE-11` | error | a deliver-to address (BG-15) without city (BT-77) or postcode (BT-78) |
| `BR-DE-15` | error | no buyer reference (BT-10); for public buyers the Leitweg-ID |
| `BR-DE-16` | error | a category other than `O` is used and the seller has no VAT identifier (BT-31), tax number (BT-32) or tax representative (BG-11) |
| `BR-DE-17` | warning | type code (BT-3) not one of 326, 380, 384, 389, 381, 875, 876, 877 |
| `BR-DE-18` | error | a line of the payment terms text (BT-20) starts with `#` but does not match `#SKONTO#TAGE=n#PROZENT=n.nn#` (optionally `BASISBETRAG=n.nn#`) |
| `BR-DE-19` | warning | SEPA credit transfer (58) with an invalid IBAN (BT-84) |
| `BR-DE-20` | warning | SEPA direct debit (59) with an invalid debited IBAN (BT-91) |
| `BR-DE-22` | error | attachment file names (BT-125-2) not unique |
| `BR-DE-23-a`, `BR-DE-23-b` | error | credit transfer (30, 58) without BG-17, or with card (BG-18) or direct debit (BG-19) data |
| `BR-DE-24-a`, `BR-DE-24-b` | error | card payment (48, 54, 55) without BG-18, or with BG-17 or BG-19 data |
| `BR-DE-25-a`, `BR-DE-25-b` | error | direct debit (59) without BG-19, or with BG-17 or BG-18 data |
| `BR-DE-26` | warning | corrected invoice (384) without preceding invoice reference (BG-3) |
| `BR-DE-27` | warning | seller phone (BT-42) with fewer than three digits |
| `BR-DE-28` | warning | seller e-mail (BT-43) without exactly one `@` surrounded by at least two characters |
| `BR-DE-30`, `BR-DE-31` | error | direct debit (BG-19) without creditor identifier (BT-90) or debited account (BT-91) |
| `BR-DE-TMP-32` | info (flag `information` in the Schematron) | no delivery date (BT-72), invoicing period (BG-14) or period on every line (BG-26) |
| `BR-DEX-15` | error | sub lines in XRechnung CII (sub lines need the extension in UBL) |
| `DE-XRECHNUNG-EXT-01` | error | a credit note in profile `xrechnung-extension` with syntax UBL; the official validator configuration has no scenario for it. Cancel with the strategy `negative_invoice` (type code 380, negative amounts). |

Quittio writes structured cash discounts itself (`#SKONTO#TAGE=7#PROZENT=2.00#`), so `BR-DE-18` only concerns lines you put into the payment terms text.

### Format choice

`Quittio\Country\DE\Formats\GermanFormatRule` decides only where German law prescribes a format, and returns null otherwise:

| Condition | Format | Profile |
|---|---|---|
| The buyer reference (BT-10) is a Leitweg-ID with valid check digits, or the buyer's electronic address (BT-49) uses scheme `0204` (`GermanFormatRule::LEITWEG_SCHEME`) | `xrechnung-cii` | `xrechnung` |
| Small amount invoice whose simplifications apply (`UstgRules::isSmallAmountInvoice()`), seller and buyer in the German Inland (section 1 (2) UStG, i.e. without Büsingen and Heligoland; an address without country code counts as domestic) | `pdf` | – |
| otherwise | – (null) | – |

Everything else follows the [general order of the resolver](formats.md#automatic-choice):

- Domestic business invoices: the Peppol lookup of the resolver (if one is configured), then `zugferd` with the configured profile (`en16931` by default; EXTENDED when EXTENDED features are used).
- Consumers: `pdf`.
- The e-invoicing obligation of section 14 (2) UStG covers supplies between businesses established in Germany only. Sellers outside the EU invoicing a German business get `pdf` from the resolver, sellers in other member states `zugferd`; buyers in Büsingen or Heligoland are outside the EU VAT territory and get `pdf`.
- German public buyers receive XRechnung whatever the seller's country: for cross-border invoices to a buyer in Germany the rule runs as the buyer's pack rule.

```php
->buyerReference('04011000-12345-03')                                    // Leitweg-ID: XRechnung
->buyer(fn ($b) => $b->electronicAddress('04011000-12345-03', '0204'))  // or by endpoint scheme
```

- Order numbers shaped like a Leitweg-ID (e.g. `4711-01`) do not switch to XRechnung. A Leitweg-ID with a typo does not either; validation reports it as `DE-LEITWEG-ID-SUSPECT` (info, see [Leitweg-ID](#leitweg-id)). Correct it, set the `0204` endpoint as well, or choose the format explicitly (`->format('xrechnung-ubl')`).
- An explicit `->format()` or configured `quittio.defaults.format` always wins.

### XRechnung profiles

| | `XRechnung30` | `XRechnung30Extension` |
|---|---|---|
| Key, name | `xrechnung`, XRechnung 3.0 | `xrechnung-extension`, XRechnung 3.0 Extension |
| Specification identifier (BT-24) | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0` | `urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0#conformant#urn:xeinkauf.de:kosit:extension:xrechnung_3.0` |
| Level, syntaxes | EN 16931; CII and UBL | as XRechnung 3.0; sub lines only in UBL |
| Business process (BT-23) default | `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` | same |
| Additionally required | BT-10, BT-34, BT-49, BG-6, BG-16 | same |
| Embedded in a PDF | `xrechnung.xml`, XMP conformance level `XRECHNUNG`, `fx:Version` `3.0` | same |
| Extras | VAT rate written in every breakdown, 0 for category `O` (BR-DE-14); cash discounts as `#SKONTO#` lines in BT-20; rules [above](#xrechnung-rules) | sub lines, third party payments (BG-DEX-09, UBL invoices only), identifier schemes `XR01` to `XR03` in the ICD and EAS code lists |

The profiles exist only when the pack is registered; without it the formats `xrechnung-cii` and `xrechnung-ubl` cannot be rendered and incoming XRechnung files are reported as an unknown profile (`QUITTIO-READER-UNKNOWN-PROFILE`).

```php
$quittio->make($invoice)->format('xrechnung-ubl')->render();                                   // XRechnung 3.0, UBL
$quittio->make($invoice)->format('xrechnung-ubl')->profile('xrechnung-extension')->render();   // extension
$quittio->make($invoice)->format('zugferd')->profile('xrechnung')->render();                   // PDF/A-3 with xrechnung.xml
```

The automatic choice always uses `xrechnung`, also when the invoice carries extension data; the specification identifier never switches by itself. Choose `xrechnung-extension` explicitly for extension data; with `xrechnung`, third party payments fail with `QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY`.

### Builder macros

`Quittio\Country\DE\Builder\GermanInvoiceMacros` adds these methods to `InvoiceBuilder`:

| Macro | Writes into the invoice |
|---|---|
| `smallAmount()` | flag `de.small_amount` |
| `propertyRelatedService(string $locale = 'de')` | flag `de.property_related_service` and the note `LegalTexts::PROPERTY_RETENTION` (subject `REG`) |
| `newVehicle(string $locale = 'de')` | flag `de.new_vehicle` and the note `LegalTexts::NEW_VEHICLE` (subject `REG`); the vehicle details are your line attributes (`DE-USTG-07`), the vehicle lines use `VatPreset::IntraCommunitySupply` (`DE-USTG-13`) |
| `selfBilled(string $locale = 'de')` | type code 389 and the note `LegalTexts::SELF_BILLING` (subject `REG`) |
| `deductAdvance(InvoiceData $advance, string $locale = 'de')` | preceding invoice (BG-3), prepaid amount (BT-113) and the note `LegalTexts::ADVANCE_DEDUCTION` (subject `REG`) |
| `consumerDefaultNotice(string $locale = 'de')` | the note `LegalTexts::CONSUMER_DEFAULT` (subject `PMT`) |

The `$locale` parameter selects the text; it is independent of the invoice locale. A locale with a region uses the text of its language (`en_GB` → English); locales without a text of the pack get the German text (`LegalTexts::text()`). Notes with subject `REG` are listed in the PDF's `legalNotes`, `PMT` notes with the other notes.

#### Final invoices (`deductAdvance`)

A final invoice (section 14 (5) sentence 2 UStG) states the full service with its full VAT and deducts the advance payments received:

```php
use Quittio\Core\Calculation\En16931Calculator;

$advance = (new En16931Calculator)->calculate($advanceInvoice);   // or $rendered->data()

$final = InvoiceBuilder::make()
    // number, dates, parties, payment …
    ->line(fn ($l) => $l->name('Website')->quantity(1, UnitCode::Piece)->netPrice('3000.00')->vat(VatPreset::Standard19))
    ->deductAdvance($advance);
```

With an advance invoice RE-2026-0040 of 1,000.00 EUR net plus 19 % VAT, the final invoice shows a gross total of 3,570.00 EUR, VAT 570.00 EUR, prepaid 1,190.00 EUR and an amount due of 2,380.00 EUR, with the note:

> Abzüglich Anzahlung gemäß Rechnung RE-2026-0040 vom 01.09.2026: Entgelt 1.000,00 € zzgl. 19 % Umsatzsteuer 190,00 € (§ 14 Abs. 5 Satz 2 UStG).

- The advance invoice must be calculated (`totals` set), have a positive gross total and be in the currency of the final invoice; otherwise an `InvalidArgumentException` is thrown. Set `->currency()` before calling the macro.
- Only documents that can be advance invoices are accepted (`GermanInvoiceMacros::ADVANCE_TYPES`): type codes 380 (commercial invoice), 386 (prepayment invoice), 326 (partial invoice), 875 (partial construction invoice) and 389 (self-billed invoice). Credit notes, debit notes, corrected invoices (384), final invoices (876, 877) and cancellations by negative invoice are rejected.
- The deducted amount is the advance invoice's gross total plus its cash rounding (BT-114).
- Every VAT row of the advance invoice is itemised: standard rated rows as "Entgelt … zzgl. … Umsatzsteuer …" ("net … plus … VAT …", `LegalTexts::ADVANCE_DEDUCTION_TAXED`), other rows as "Entgelt … ohne Umsatzsteuer" ("net … without VAT", `LegalTexts::ADVANCE_DEDUCTION_UNTAXED`); rows are separated by semicolons.
- The whole note is in one language: the language of `$locale` if the pack has it (`de`, `en`), otherwise German. Amounts and dates are formatted in `$locale` when the note is in its language (e.g. `de_AT`), otherwise in the language of the note (`fr` gives the German note with German formatting).
- Call it once per advance invoice; the prepaid amounts add up.
- The macro does not check whether the advance invoice was paid; deduct only payments received.

### Small amount invoices

`->smallAmount()` marks an invoice of at most 250.00 EUR gross (`Flags::SMALL_AMOUNT_LIMIT`) under section 33 UStDV. As long as no reverse charge (`AE`) or intra-community supply (`K`) is involved, it relaxes:

- `DE-USTG-01`: the buyer's address is not required (the seller's still is);
- `DE-USTG-02`: no tax number or VAT identifier;
- `DE-USTG-03`: no service date;
- the mandatory PDF output for the tax number and the service date;
- the format: `pdf` instead of `zugferd` when seller and buyer are in the German Inland (a Leitweg-ID still leads to XRechnung; see [format choice](#format-choice)).

`DE-USTG-08` enforces the limit and `DE-USTG-12` excludes reverse charge and intra-community supplies (section 33 sentence 3 UStDV); such invoices need all mandatory information and are not rendered as plain PDF. Possible distance sales keep the simplifications with a warning.

### Small businesses

- Use `VatPreset::SmallBusiness` on every line. An invoice whose lines all use it is a small business invoice under section 34a UStDV: no service date is required (`DE-USTG-03`) or checked in the PDF; the tax number or VAT identifier is still required (`DE-USTG-02`), and the preset's text (BT-120) appears in the PDF.
- Mark the seller as a small business so that VAT is rejected (`DE-USTG-09`): `quittio.seller.small_business = true` (applied by `Seller::apply($builder)`), the Managed issuer setting `tax_settings.small_business`, or `->flag(Meta::FLAG_SMALL_BUSINESS)`. `Flags::SMALL_BUSINESS` is the same flag (`small_business`). The flag does not set presets or texts.
- Without a VAT identifier, EN 16931 needs a seller identifier (BT-29) or legal registration (BT-30) (BR-CO-26); in Laravel set `quittio.seller.identifiers` or `legal_registration` (see [configuration](configuration.md)).

```php
use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Laravel\Seller;

$builder = Seller::apply(InvoiceBuilder::make())   // quittio.seller.small_business = true
    ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Hour)->netPrice('80.00')->vat(VatPreset::SmallBusiness));
```

### Reverse charge, intra-community supplies and VAT identifiers

- Reverse charge rows (`AE`) must name "Steuerschuldnerschaft des Leistungsempfängers" (`DE-USTG-06`, warning). The texts of the reverse charge presets are accepted in every locale the pack ships, so English invoices with the presets pass as well; own texts must contain "Steuerschuldnerschaft".
- Reverse charge in another member state and triangular transactions need the VAT identifiers of seller and buyer (`DE-USTG-11`); the rule does not apply to `ReverseChargeDomestic` (section 13b UStG).
- The PDF must show the reverse charge or exemption note and the VAT identifiers (see [mandatory PDF output](#mandatory-pdf-output)).
- A seller tax representative (BG-11, `->taxRepresentative($name, $vatId, $address)`) satisfies `DE-USTG-02` and `BR-DE-16`; its VAT identifier satisfies `DE-USTG-11` and the mandatory output.
- The pack does not check the format of a German tax number (Steuernummer). VAT identifiers are checked by the core plausibility rules (`QUITTIO-VATID-FORMAT`, for `DE` nine digits; optionally `QUITTIO-VIES`), which also warn about category `K` with a domestic or non-EU destination and `G` within the EU (see [validation](validation.md)).

### Self-billing (Gutschrift)

`->selfBilled()` sets type code 389 and the note "Gutschrift" (English: "Gutschrift (self-billed invoice)"). In German, a self-billed invoice is titled "Gutschrift" (English "Self-billed invoice"); the PDF shows the buyer as issuer. `DE-USTG-04` requires the label on every self-billed type code (389, 261, 527), and the mandatory output requires the title and the "Gutschrift" note in the PDF. "Gutschrift" is used only for self-billing; commercial credit notes and cancellations carry other titles.

### Cancellations and corrections

`CountryDE::correctionPolicy()`: cancellation strategy `credit_note`, correction strategy `cancel_and_reissue`, with the titles

| Kind | German | English |
|---|---|---|
| `cancellation` | Stornorechnung | Cancellation invoice |
| `cancellation_self_billed` | Storno-Gutschrift | Cancellation of the self-billed invoice (Storno-Gutschrift) |
| `correction` | Rechnungskorrektur | Corrected invoice |
| `self_billed` | Gutschrift | Self-billed invoice (Gutschrift) |

`cancellation` and `cancellation_self_billed` title the documents of `Quittio::cancellation()`; `correction` titles a Managed corrected invoice (384). `self_billed` titles self-billed invoices (389, 261, 527) unless the presentation sets a title; locales without an own title fall back to English ("Self-billed invoice (Gutschrift)"), which keeps the word "Gutschrift" required by section 14 (4) no. 10 UStG. Both cancellation strategies are permitted in Germany; change them with `quittio.corrections.cancellation_strategy` and `quittio-managed.correction.strategy`. For the XRechnung extension in UBL use `negative_invoice` (`DE-XRECHNUNG-EXT-01`).

### Mandatory PDF output

`CountryDE::mandatoryOutput()` adds these values to the base fields of the mandatory field guard (paths without a value are skipped):

| Condition | Access key | Description | Legal basis |
|---|---|---|---|
| not a small amount invoice, and the seller has a VAT identifier or tax number, or a tax representative with VAT identifier | `seller.vatId\|seller.taxNumber\|taxRepresentative.vatId` | tax number or VAT identifier of the seller | § 14 Abs. 4 Nr. 2 UStG |
| not a small amount or small business invoice, with BT-72 or BG-14 | `servicePeriod\|deliveryDate\|delivery.date` | date of the supply or service | § 14 Abs. 4 Nr. 6 UStG |
| the same, without BT-72 and BG-14 | `servicePeriod\|lineGroups[*].lines[*].period\|lines[*].period` | date of the supply or service | § 14 Abs. 4 Nr. 6 UStG |
| self-billed type code | `title`, and the path of the first note containing "Gutschrift" | label and note "Gutschrift" | § 14 Abs. 4 Nr. 10 UStG |
| flag `de.property_related_service` | path of the retention note (its text or a note containing "14b") | retention notice for property related services | § 14 Abs. 4 Nr. 9 UStG |
| each VAT row of category `AE` or `K` | `vatRows[i].label` or the legal note with its BT-120 text | reverse charge or exemption note | § 14a UStG |
| category `AE` or `K` used | `buyer.vatId` | VAT identifier of the buyer | § 14a Abs. 1 und 3 UStG |
| category `K` used, or `AE` with a buyer country different from the seller's | `seller.vatId\|taxRepresentative.vatId` | VAT identifier of the seller | § 14a Abs. 1 und 3 UStG |

The default templates print all of them.

### Legal texts

`Quittio\Country\DE\Texts\LegalTexts::catalog()` (also `CountryDE::legalTexts()`), fallback locale `de` (`LegalTexts::FALLBACK_LOCALE`):

| Constant (key) | German | Added by |
|---|---|---|
| `SELF_BILLING` (`self_billing`) | Gutschrift | `selfBilled()` |
| `PROPERTY_RETENTION` (`property_retention`) | Sie sind gesetzlich verpflichtet, diese Rechnung, einen Zahlungsbeleg oder eine andere beweiskräftige Unterlage zwei Jahre lang aufzubewahren (§ 14b Abs. 1 Satz 5 UStG). | `propertyRelatedService()` |
| `REVERSE_CHARGE` (`reverse_charge`) | Steuerschuldnerschaft des Leistungsempfängers | – (text of `VatPreset::ReverseChargeEu`) |
| `INTRA_COMMUNITY_SUPPLY` (`intra_community_supply`) | Steuerfreie innergemeinschaftliche Lieferung (§ 4 Nr. 1b i. V. m. § 6a UStG) | – (text of `VatPreset::IntraCommunitySupply`) |
| `EXPORT` (`export`) | Steuerfreie Ausfuhrlieferung (§ 4 Nr. 1a i. V. m. § 6 UStG) | – (text of `VatPreset::Export`) |
| `SMALL_BUSINESS` (`small_business`) | Steuerfreie Leistung nach § 19 Abs. 1 UStG (Steuerbefreiung für Kleinunternehmer). | – (text of `VatPreset::SmallBusiness`) |
| `PREPAYMENT` (`prepayment`) | Die Umsatzsteuer entsteht mit Vereinnahmung der Anzahlung. Die Leistung wird voraussichtlich am :date erbracht. | – |
| `ADVANCE_DEDUCTION` (`advance_deduction`) | Abzüglich Anzahlung gemäß Rechnung :number vom :date: :amounts (§ 14 Abs. 5 Satz 2 UStG). | `deductAdvance()` |
| `ADVANCE_DEDUCTION_TAXED` (`advance_deduction_taxed`) | Entgelt :net zzgl. :rate Umsatzsteuer :vat | `deductAdvance()`, part of `:amounts` |
| `ADVANCE_DEDUCTION_UNTAXED` (`advance_deduction_untaxed`) | Entgelt :net ohne Umsatzsteuer | `deductAdvance()`, part of `:amounts` |
| `CONSUMER_DEFAULT` (`consumer_default`) | Sie kommen spätestens in Verzug, wenn Sie nicht innerhalb von 30 Tagen nach Fälligkeit und Zugang dieser Rechnung zahlen (§ 286 Abs. 3 BGB). | `consumerDefaultNotice()` |
| `NEW_VEHICLE` (`new_vehicle`) | Innergemeinschaftliche Lieferung eines neuen Fahrzeugs (§ 14a Abs. 4 UStG); die Fahrzeugmerkmale nach § 1b Abs. 2 und 3 UStG sind bei der Position angegeben. | `newVehicle()` |

Every text also exists in English. `REVERSE_CHARGE`, `INTRA_COMMUNITY_SUPPLY`, `EXPORT` and `SMALL_BUSINESS` are the exemption reasons of the named [tax presets](#tax-presets) in every locale the pack ships (`CountryDE::locales()`), so a notice has one wording in BT-120 and in your notes, including overrides of `tax.php`. No text is added automatically by validation or rendering. `LegalTexts::text($key, $locale, $replace)` returns a text in the language of `$locale` (`en_GB` → English) or in German; `LegalTexts::localeOf($key, $locale)` names the catalog locale it uses. Texts marked "–" are for your own notes:

```php
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Country\DE\Texts\LegalTexts;

$builder->type('386')->note(
    LegalTexts::catalog()->get(LegalTexts::PREPAYMENT, 'de', ['date' => '01.11.2026']),
    NoteSubjectCode::RegulatoryInformation,
);
```

Prepayment invoices (386) without a service date get a `DE-USTG-03` warning instead of an error; name the expected date as above.

### Meta flags

`Quittio\Country\DE\Support\Flags`:

| Constant | Value | Set by | Effect |
|---|---|---|---|
| `SMALL_AMOUNT` | `de.small_amount` | `smallAmount()` | small amount invoice: relaxed rules, PDF format, `DE-USTG-08`, `DE-USTG-12` |
| `PROPERTY_RELATED_SERVICE` | `de.property_related_service` | `propertyRelatedService()` | `DE-USTG-05`, mandatory output |
| `NEW_VEHICLE` | `de.new_vehicle` | `newVehicle()` | `DE-USTG-07`, `DE-USTG-13` |
| `SMALL_BUSINESS` | `small_business` (`Meta::FLAG_SMALL_BUSINESS`) | seller configuration, Managed issuer, `->flag()` | `DE-USTG-09` |
| `SMALL_AMOUNT_LIMIT` | `250.00` | – | limit of `DE-USTG-08` in EUR |

### Retention

`CountryDE::retention()` returns `new RetentionRule(8, fromEndOfCalendarYear: true)`: invoices are kept for 8 years from the end of the calendar year of issue (section 14b UStG, section 147 AO as amended in 2025). An invoice issued on 3 October 2026 is kept until 31 December 2034. The Managed layer stores this date (`retention_until`); `quittio-managed.retention.years` may only lengthen it.

### Dunning law

`CountryDE::dunningLaw()`: base rate plus 9 points for business debtors and 5 points for consumers, flat rate 40.00 EUR for business debtors, day count 365, consumer default after 30 days (with notice), reference rate "Basiszinssatz (§ 247 BGB)". Quittio's documents do not apply these values themselves; use `DefaultInterest` and pass the results to a payment reminder (see [documents: payment reminders](documents.md#payment-reminders)).

#### Base rate table

`Quittio\Country\DE\Dunning\BaseRateTable` holds the base rate (§ 247 BGB), which changes on 1 January and 1 July. `BaseRateTable::PUBLISHED`:

| Valid from | Rate in % |
|---|---|
| 2023-01-01 | 1.62 |
| 2023-07-01 | 3.12 |
| 2024-01-01 | 3.62 |
| 2024-07-01 | 3.37 |
| 2025-01-01 | 2.27 |
| 2025-07-01 | 1.27 |
| 2026-01-01 | 1.27 |
| 2026-07-01 | 1.52 |

| Method | Result |
|---|---|
| `__construct(array $rates = self::PUBLISHED)` | ISO date => rate in percent, sorted by date |
| `withRate(Date\|string $from, string $rate): self` | new table with an additional or replaced rate |
| `rateOn(Date $date): BigDecimal` | rate on a day; `InvalidArgumentException` before the first entry and from `validUntil()` on |
| `periods(Date $from, Date $until): list<array{from: Date, until: Date, rate: BigDecimal}>` | periods with a constant rate (start inclusive, end exclusive), split where the rate changes; a table entry that keeps the rate (e.g. 2026-01-01) starts no new period; `InvalidArgumentException` if `$until` is after `validUntil()` |
| `lastChange(): Date` | date of the last entry, e.g. to warn when the table is outdated |
| `validUntil(): Date` | first day without a known rate: the next 1 January or 1 July after the last entry (with the published table: 2027-01-01) |

Rates after the last entry are unknown until they are published: interest for days from 1 January 2027 is rejected instead of silently using the old rate. There are three ways to add new rates, so that no new Quittio release is needed:

1. **Laravel configuration** (`config/quittio-de.php`, publish it with `php artisan vendor:publish --tag=quittio-de-config`):

   ```php
   'base_rates' => ['2027-01-01' => '1.77'],
   ```

2. **Automatic update from the Deutsche Bundesbank** (Laravel): `php artisan quittio:base-rate:update` fetches the series `BBIN1.M.DE.BBK.BBKBAS2.EUR.ME` (Basiszinssatz gemäß BGB) from the statistics API and stores the rates the shipped table does not contain in `quittio-de.base_rate_file` (default `storage/app/quittio/base-rates.json`), also those before 2023 for older claims. It writes the file only when something changed; `--dry-run` shows the rates without writing. A value that differs from the shipped table is reported, and the Bundesbank value is used. Schedule it, e.g. weekly, so that the rate of 1 January and 1 July is there as soon as the Bundesbank has published it:

   ```php
   // routes/console.php
   Schedule::command('quittio:base-rate:update')->weekly();
   ```

3. **Without Laravel:** `BaseRates::register(['2027-01-01' => '1.77'])` once at start-up, `BundesbankBaseRates::fetch()` to fetch the rates yourself, or a table for one calculation:

   ```php
   $rates = (new BaseRateTable)->withRate('2027-01-01', '1.77');
   $interest = new DefaultInterest($rates);
   ```

`DefaultInterest` without a table uses `BaseRates::table()`: the shipped table, then the rates of the file, then those of `quittio-de.base_rates` (the configuration wins for the same date). The service provider `Quittio\Country\DE\Laravel\GermanyServiceProvider` registers file and configuration at boot; Laravel discovers it automatically.

`Quittio\Country\DE\Dunning\BaseRates`:

| Method | Result |
|---|---|
| `register(array $rates): void` | adds or replaces rates (ISO date => percent); only 1 January and 1 July, values such as `'1.77'` or `'-0.88'`, otherwise `InvalidArgumentException` |
| `table(): BaseRateTable` | the shipped table with the registered rates |
| `registered(): array` / `reset(): void` | the registered rates; removes them (tests) |

`Quittio\Country\DE\Dunning\BundesbankBaseRates`:

| Method | Result |
|---|---|
| `__construct(?Closure $http = null, int $timeout = 15)` | `$http` receives URL and timeout and returns the response body (for tests or an own HTTP client); default `file_get_contents()` |
| `fetch(string $since = '2002-01'): array` | the January and July values from the given month on, ISO date => percent; `RuntimeException` when the API cannot be reached or returns no rates |
| `parse(string $csv): array` | reads the CSV download (`2026-07;1,52;…`) |

#### Default interest

`Quittio\Country\DE\Dunning\DefaultInterest`:

| Method | Result |
|---|---|
| `__construct(?BaseRateTable $rates = null, ?DunningLaw $law = null)` | without a table, `BaseRates::table()` is used (shipped and registered rates); without a law, `CountryDE::dunningLaw()` (points, flat rate and day count) |
| `calculate(Money $amount, Date $from, Date $until, bool $consumer = false, string $locale = 'de'): list<InterestCharge>` | one charge per base rate period from `$from` (first day with interest) to `$until` (exclusive); rate = base rate + 9 points, or + 5 points with `$consumer`; empty for a non-positive amount or `$until` not after `$from`; `InvalidArgumentException` for days the table does not cover |
| `flatRate(string $currency = 'EUR'): ?Money` | 40.00 flat rate for business debtors (§ 288 (5) BGB), once per claim |

```php
use Brick\Money\Money;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Country\DE\Dunning\DefaultInterest;

$interest = new DefaultInterest;
$reminder = DocumentBuilder::paymentReminder(1)
    ->number('M-2026-0003')
    ->seller(Seller::fromConfig())
    ->buyer($buyer)
    ->openItem('RE-2026-0042', '2026-06-01', '1190.00', dueDate: '2026-06-15');

foreach ($interest->calculate(Money::of('1190.00', 'EUR'), Date::parse('2026-06-16'), Date::parse('2026-10-04')) as $charge) {
    $reminder->interest(Amount::format($charge->base), $charge->ratePercent, $charge->from, $charge->until, $charge->description);
}
$reminder->fee('Pauschale nach § 288 Abs. 5 BGB', Amount::format($interest->flatRate()));
```

The descriptions name the rate, the base rate, the points and the last day with interest, e.g. "Verzugszinsen 10,27 % p. a. (Basiszinssatz 1,27 % + 9,00 Prozentpunkte) vom 16.06.2026 bis 30.06.2026"; any other locale gives "Default interest 10.27 % p.a. (base rate 1.27 % + 9.00 points) from 2026-06-16 to 2026-06-30". The charges use the day count of the dunning law (`InterestCharge::$dayCount`; 365 for `CountryDE::dunningLaw()`, so a leap year yields 366/365 of the annual rate). A charge covers a whole period with the same base rate: interest from 1 December 2025 to 28 February 2026 is one charge, although the table has an entry for 1 January 2026.

The flat rate is due only from business debtors, once per claim, and the documents layer does not track which claims already carried it. For consumers, `->consumerDefaultNotice()` adds the notice on default after 30 days to the invoice.

### BMF error classes

`Quittio\Country\DE\Rules\BmfErrorClass` maps findings to the error classes of the BMF letter of 15 October 2025:

| Case | Value | Findings | `label('de')` | `label('en')` |
|---|---|---|---|---|
| `Format` | `format` | levels L1, L2, L4, L6 | Formatfehler (Rz. 6a) | Format error (marginal no. 6a) |
| `BusinessRule` | `business_rule` | levels L3, L5 | Geschäftsregelfehler (Rz. 6b) | Business rule error (marginal no. 6b) |
| `Content` | `content` | every `DE-USTG-*` finding and level L7 | Inhaltsfehler (Rz. 35a) | Content error (marginal no. 35a) |

`BmfErrorClass::of(ValidationMessage $message)` classifies one finding; `label()` returns German for `de`, English otherwise; `voidsEInvoice()` is true for `Format` only (the document is then an "other invoice"). `(new CountryDE)->errorClassification($result)` groups the errors and warnings of a `ValidationResult` by class value:

```php
$classes = (new CountryDE)->errorClassification($result);   // ['format' => [...], 'business_rule' => [...], 'content' => [...]]
```

### DIN 5008 letter layouts

`Quittio\Country\DE\Layouts\Din5008` provides `formA()`, `formB()` and `all()` (form A, form B and `free`). Form B is the German default. Values in millimetres, as in DIN 5008:2020 (unchanged since the 2011 edition, which enlarged the address field of both forms from 40 to 45 mm and moved the Rücksendeangabe into the Zusatz- und Vermerkzone):

| | `din5008a` (`Din5008::FORM_A`) | `din5008b` (`Din5008::FORM_B`) |
|---|---|---|
| Address field from top / left | 27 / 20 | 45 / 20 |
| Address field width × height | 85 × 45 | 85 × 45 |
| Zusatz- und Vermerkzone (above the recipient) | 17.7 (five lines, filled from the bottom), its last 5 mm hold the sender line (Rücksendeangabe) | same |
| Anschriftzone (recipient, six lines) | 27.3, from 44.7 | 27.3, from 62.7 |
| Fold marks | 87 and 192 | 105 and 210 |
| Punch mark | 148.5 | 148.5 |
| Letterhead height in the default templates (top margin 20) | 7 | 25 |

The address field keeps its position with a logo. Select a layout per invoice with `->presentation(fn ($p) => $p->with(['letterLayout' => 'din5008a']))` or for all invoices with `quittio.pdf.layout`; fold and punch marks are printed with `quittio.pdf.fold_marks = true` (core option `fold_marks`). See [templates](templates.md#letter-layouts).

### Translations

The pack ships German and English catalogs in `resources/lang/{de,en}/`:

- `rules.php`: message and hint of every rule (`BR-DE-*`, `BR-DEX-15`, `DE-XRECHNUNG-EXT-01`, `DE-USTG-*`, `DE-LEITWEG-ID`, `DE-LEITWEG-ID-SUSPECT`);
- `tax.php`: the exemption reason texts of the presets, including `de.exempt` for `VatPreset::exempt()` (placeholder `:paragraph`).

They are registered as the namespace `quittio-de` (e.g. `quittio-de::rules.DE-USTG-03.message`); `CountryDE::registerTranslations()` registers them lazily, so presets work without a `Quittio` instance. Other locales fall back to English. With Laravel, Laravel's translator is used, so the usual override files apply: `lang/vendor/quittio-de/{locale}/rules.php` and `tax.php`, also for additional locales.

`CountryDE::locales()` lists the locales the pack ships (the directories of `resources/lang`). Texts defined in PHP are not translatable through files: the legal texts (German and English, fallback German; the four notices taken from the presets follow `tax.php`), the correction policy titles (fallback English), the BMF labels and the default interest descriptions (German for `de`, English otherwise).

### Known limitations

- The legal wording and references of presets, rules and texts are proposals that must be confirmed by a tax adviser (see the open decisions in the README); the default correction strategy and the cancellation of self-billed invoices are open decisions as well.
- Final invoices: only the EN 16931 variant (`deductAdvance()`) exists; the EXTENDED variant and the handling of unpaid advance invoices are open.
- The shipped base rate table ends with 1 July 2026; later rates come from `quittio-de.base_rates` or `php artisan quittio:base-rate:update` (see [Base rate table](#base-rate-table)).
- Default dunning fees and flat rates are an open decision; the documents layer does not track the § 288 (5) BGB flat rate per claim.
- Distance sales (section 3c UStG) cannot be told from the data; `DE-USTG-12` only warns about possible ones.
- Tax numbers are checked for format and check digit, not against the list of tax offices; territories without a distinct postcode cannot be detected.
- The pack defines no buyer country rules, exporters (e.g. DATEV) or readers yet.
