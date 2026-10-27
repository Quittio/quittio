# Upgrading from 0.x to 1.0

Quittio 1.0 removes what the 0.x releases deprecated and fixes the public API: from now on only a major release breaks it ([Versioning and compatibility](compatibility.md)). This page lists every break and what to do.

If your application runs on 0.8 without deprecation notices, most of the steps below do not apply. Two ways to find the calls that are affected before you update:

- **Static analysis.** PHPStan with [phpstan-deprecation-rules](https://github.com/phpstan/phpstan-deprecation-rules) reports every call of deprecated code.
- **Runtime notices.** The old builder macros and `config/quittio-de.php` report an `E_USER_DEPRECATED` in 0.8. Laravel writes them to the `deprecations` log channel when one is configured.

## Composer

```bash
composer require quittio/quittio:^1.0
# without Laravel
composer require quittio/core:^1.0 quittio/country-de:^1.0
```

All packages are released together and carry the same version. The country packs for Austria, Switzerland, Belgium and France are not covered by the compatibility promise until a tax adviser of their country has reviewed them ([Country packs without tax review](compatibility.md#country-packs-without-tax-review)).

## Methods of `Quittio\Core\Quittio`

The `with…()` methods that changed the instance are gone. Elsewhere in Quittio, `with…()` returns a changed copy; these methods never did, so they were renamed in 0.2:

| Removed | Use instead |
|---|---|
| `withSerializer()` | `addSerializer()` |
| `withValidator()` | `addValidator()` |
| `withoutValidators()` | `clearValidators()` |
| `withRenderer()` | `setRenderer()` |
| `withTemplateEngine()` | `setTemplateEngine()` |
| `withPaymentQrGenerator()` | `setPaymentQrGenerator()` |
| `withFormatResolver()` | `setFormatResolver()` |

The new methods behave exactly like the old ones: they change the instance and return it.

## Formats

| Removed | Use instead |
|---|---|
| `FormatRegistry::XRECHNUNG_CII` | `Quittio\Country\DE\Formats\XRechnungFormats::CII` |
| `FormatRegistry::XRECHNUNG_UBL` | `Quittio\Country\DE\Formats\XRechnungFormats::UBL` |
| `DefaultFormatResolver::EU_COUNTRIES` | `Quittio\Core\Countries\VatTerritory::EU_MEMBER_STATES` |

The formats `xrechnung-cii` and `xrechnung-ubl` and the alias `xrechnung` come from the German country pack only. In Laravel nothing changes, because the pack is discovered automatically. Without Laravel, pass the pack to `Quittio::create()` if you render XRechnung:

```php
use Quittio\Core\Quittio;
use Quittio\Country\DE\CountryDE;

$quittio = Quittio::create(countryPacks: [new CountryDE]);
```

## German country pack

### Tax presets

`VatPreset::Standard19` and `VatPreset::Reduced7` are gone. `VatPreset::Standard` and `VatPreset::Reduced` replace them and take the rate of the supply date, e.g. 16 % and 5 % for supplies from July to December 2020 ([Tax presets](country-packs.md#tax-presets)).

```php
use Quittio\Country\DE\Tax\VatPreset;

// 0.x
$line->vat(VatPreset::Standard19);

// 1.0
$line->vat(VatPreset::Standard);
```

Invoices stored with 0.2 or earlier carry the preset keys `de.standard_19` and `de.reduced_7` on their lines. These keys stay readable: snapshots, `quittio.defaults.vat`, `(new CountryDE)->taxPresets()->get()` and `VatPreset::fromKey()` find `Standard` and `Reduced` for them. `VatPreset::from()` and `tryFrom()` no longer know them, though. If you keep preset keys in a column of your own and look them up with `from()`, switch to `fromKey()`:

```php
use Quittio\Country\DE\Tax\VatPreset;
use UnexpectedValueException;

// 0.x: throws a ValueError in 1.0 for "de.standard_19"
$preset = VatPreset::from($item->tax_preset);

// 1.0
$preset = VatPreset::fromKey($item->tax_preset) ?? throw new UnexpectedValueException('Unknown preset '.$item->tax_preset);
```

Or update the column once, e.g. `UPDATE order_items SET tax_preset = 'de.standard' WHERE tax_preset = 'de.standard_19'` (and `de.reduced` for `de.reduced_7`).

`VatPreset::canonical()` is gone as well: it turned the removed cases into `Standard` and `Reduced`, which `fromKey()` now returns directly. The old keys are available as `VatPreset::LEGACY_STANDARD_KEY` and `VatPreset::LEGACY_REDUCED_KEY`.

### Builder macros

The macros of 0.2 directly on the builder are gone. The German special cases are reached with `->de()`:

| Removed | Use instead |
|---|---|
| `->smallAmount()` | `->de()->smallAmount()` |
| `->propertyRelatedService()` | `->de()->propertyRelatedService()` |
| `->newVehicle()` | `->de()->newVehicle()` |
| `->selfBilled()` | `->de()->selfBilled()` |
| `->deductAdvance($advance)` | `->de()->deductAdvance($advance)` |
| `->consumerDefaultNotice()` | `->de()->consumerDefaultNotice()` |

Each method of `->de()` returns the builder, so the chain continues as before. The IDE stub `stubs/InvoiceBuilder.stub.php` describes `de()` only.

### Constants

| Removed | Use instead |
|---|---|
| `Flags::SMALL_AMOUNT_LIMIT` | `TaxTables::smallAmountLimitOn($date)`: the limit depends on the date (150.00 EUR before 2017) |
| `GermanInvoiceMacros::ADVANCE_TYPES` | `GermanInvoice::ADVANCE_TYPES` |

## Laravel configuration

### Environment variables

The names of 0.1.0 are no longer read. Rename them in your `.env`:

| Removed | Use instead |
|---|---|
| `QUITTIO_COUNTRY` | `QUITTIO_DEFAULT_COUNTRY` |
| `QUITTIO_VALIDATION` | `QUITTIO_VALIDATION_MODE` |
| `QUITTIO_GUARD` | `QUITTIO_MANDATORY_FIELD_GUARD` |
| `QUITTIO_DISK` | `QUITTIO_STORAGE_DISK` |
| `QUITTIO_PREVIEW` | `QUITTIO_PREVIEW_ENABLED` |

A published `config/quittio.php` of 0.1 still reads the old names. Compare it with the current file (`php artisan vendor:publish --tag=quittio-config --force` overwrites it) or rename the variables in both places.

### `config/quittio-de.php`

The German settings of 0.3 and earlier are no longer read. So that settings such as `prior_year_turnover_above_800k` are not lost unnoticed, the application refuses to start with a `RuntimeException` while `config/quittio-de.php` exists. Move its keys to `config/quittio/countries/de.php` and delete the old file:

```bash
php artisan vendor:publish --tag=quittio-de-config   # config/quittio/countries/de.php
rm config/quittio-de.php
rm -f bootstrap/cache/config.php                      # if the configuration is cached
```

See [Country pack Germany](configuration.md#country-pack-germany-quittiocountriesde) for the keys.

## Named arguments

`DocumentBuilder::creditTransfer()` names its first parameter `$accountId`, like `InvoiceBuilder::creditTransfer()`. Only calls with a named argument change:

```php
// 0.x
$document->creditTransfer(iban: 'DE02120300000000202051');

// 1.0
$document->creditTransfer(accountId: 'DE02120300000000202051');
```
