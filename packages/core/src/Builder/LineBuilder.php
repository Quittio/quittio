<?php

declare(strict_types=1);

namespace Quittio\Core\Builder;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Contracts\ProvidesInvoiceLine;
use Quittio\Core\Data\AllowanceCharge\LineAllowance;
use Quittio\Core\Data\AllowanceCharge\LineCharge;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Extensions\LineExtendedData;
use Quittio\Core\Data\Identifier;
use Quittio\Core\Data\Lines\Classification;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Lines\Item;
use Quittio\Core\Data\Lines\ItemAttribute;
use Quittio\Core\Data\Lines\LineVat;
use Quittio\Core\Data\Lines\Price;
use Quittio\Core\Data\Presentation\LinePresentation;
use Quittio\Core\Data\References\DocumentReference;
use Quittio\Core\Enums\AllowanceReasonCode;
use Quittio\Core\Enums\ChargeReasonCode;
use Quittio\Core\Enums\IdentifierScheme;
use Quittio\Core\Enums\ItemClassificationScheme;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Enums\VatExemptionReasonCode;
use Quittio\Core\Exceptions\InvalidInvoiceData;
use Quittio\Core\Support\Amount;
use Quittio\Core\Support\Date;
use Quittio\Core\Support\Decimal;
use Quittio\Core\Support\Macroable;
use Quittio\Core\Tax\TaxPreset;

/**
 * Fluent builder for an invoice line (BG-25).
 */
final class LineBuilder
{
    use Macroable;

    private ?string $id = null;

    private ?string $name = null;

    private ?string $description = null;

    private ?string $note = null;

    private BigDecimal|int|string|null $quantity = null;

    private UnitCode|RawCode $unit = UnitCode::Unit;

    private BigDecimal|int|string|null $netPrice = null;

    private BigDecimal|int|string|null $grossPrice = null;

    private BigDecimal|int|string|null $priceDiscount = null;

    private BigDecimal|int|string|null $priceInclVat = null;

    private BigDecimal|int|string|null $baseQuantity = null;

    private UnitCode|RawCode|null $baseQuantityUnit = null;

    private ?TaxPreset $preset = null;

    private ?VatCategory $vatCategory = null;

    private BigDecimal|int|string|null $vatRate = null;

    private ?string $exemptionReason = null;

    private VatExemptionReasonCode|RawCode|null $exemptionReasonCode = null;

    private ?Period $period = null;

    /** @var list<array{charge: bool, amount: ?string, percent: ?string, base: ?string, reason: ?string, code: AllowanceReasonCode|ChargeReasonCode|RawCode|null}> */
    private array $allowanceCharges = [];

    private ?string $sellerId = null;

    private ?string $buyerId = null;

    private ?Identifier $standardId = null;

    /** @var list<Classification> */
    private array $classifications = [];

    private ?string $originCountry = null;

    /** @var list<ItemAttribute> */
    private array $attributes = [];

    private ?string $orderLineReference = null;

    private ?string $buyerAccountingReference = null;

    private ?Identifier $objectId = null;

    /** @var list<LineBuilder> */
    private array $subLines = [];

    /** @var list<DocumentReference> */
    private array $references = [];

    private ?LineExtendedData $extended = null;

    private ?LinePresentation $presentation = null;

    /**
     * Keys of a line given as array (InvoiceBuilder::line(), Line::fromArray()): the methods of
     * this builder that can be called with the value.
     */
    public const array ARRAY_KEYS = [
        'id', 'name', 'description', 'note', 'quantity', 'unit', 'netPrice', 'listPrice', 'priceInclVat', 'vat',
        'period', 'allowance', 'charge', 'sellerId', 'buyerId', 'standardId', 'classification', 'originCountry',
        'attribute', 'orderLineReference', 'buyerAccountingReference', 'objectId', 'group', 'highlight', 'showGrossPrice',
    ];

    /**
     * Calls the method of every key with its value: ['name' => 'Consulting', 'quantity' => 4,
     * 'netPrice' => '120.00']. An array value holds the arguments, as list or by name
     * (['quantity' => [4, UnitCode::Hour]], ['allowance' => ['percent' => '10', 'reason' => 'Early bird']]);
     * null values are skipped.
     *
     * @param  array<array-key, mixed>  $values
     */
    public function fill(array $values): self
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ARRAY_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown key "%s" of an invoice line. Known keys: %s.', $key, implode(', ', self::ARRAY_KEYS)));
            }
            if ($value === null) {
                continue;
            }
            // PHP checks the argument types of the method (strict types).
            call_user_func_array([$this, $key], is_array($value) ? $value : [$value]);
        }

        return $this;
    }

    /**
     * Whether the line or one of its sub lines has no VAT treatment yet.
     */
    public function lacksVat(): bool
    {
        if (! $this->hasVat()) {
            return true;
        }
        foreach ($this->subLines as $subLine) {
            if ($subLine->lacksVat()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether vat() was called.
     */
    public function hasVat(): bool
    {
        return $this->preset !== null || $this->vatCategory !== null;
    }

    /**
     * Whether a net price, list price or price including VAT was set.
     */
    public function hasPrice(): bool
    {
        return $this->netPrice !== null || $this->grossPrice !== null || $this->priceInclVat !== null;
    }

    public function fromModel(ProvidesInvoiceLine $model): self
    {
        $model->fillLine($this);

        return $this;
    }

    public function id(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Identifier set with id(); null when the invoice builder assigns one.
     */
    public function explicitId(): ?string
    {
        return $this->id;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function note(?string $note): self
    {
        $this->note = $note;

        return $this;
    }

    public function quantity(BigDecimal|int|float|string $quantity, UnitCode|RawCode|string|null $unit = null): self
    {
        $this->quantity = Decimal::rejectFloat($quantity);
        if ($unit !== null) {
            $this->unit($unit);
        }

        return $this;
    }

    public function unit(UnitCode|RawCode|string $unit): self
    {
        $this->unit = is_string($unit) ? (UnitCode::tryFrom($unit) ?? new RawCode($unit)) : $unit;

        return $this;
    }

    /**
     * Net price per base quantity (BT-146, BT-149, BT-150).
     */
    public function netPrice(BigDecimal|int|float|string $price, BigDecimal|int|float|string|null $baseQuantity = null, UnitCode|RawCode|string|null $baseQuantityUnit = null): self
    {
        $this->netPrice = Decimal::rejectFloat($price);
        $this->baseQuantity = Decimal::rejectFloat($baseQuantity);
        if ($baseQuantityUnit !== null) {
            $this->baseQuantityUnit = is_string($baseQuantityUnit) ? (UnitCode::tryFrom($baseQuantityUnit) ?? new RawCode($baseQuantityUnit)) : $baseQuantityUnit;
        }

        return $this;
    }

    /**
     * List price before price discount (BT-148) and the discount per unit (BT-147).
     * The net price is derived as list price minus discount unless set explicitly.
     */
    public function listPrice(BigDecimal|int|float|string $grossPrice, BigDecimal|int|float|string|null $discount = null): self
    {
        $this->grossPrice = Decimal::rejectFloat($grossPrice);
        $this->priceDiscount = Decimal::rejectFloat($discount);

        return $this;
    }

    /**
     * Price including VAT (gross price mode for B2C and shops).
     * The calculator derives the net price so that the customer pays exactly the gross amount.
     */
    public function priceInclVat(BigDecimal|int|float|string $price): self
    {
        $this->priceInclVat = Decimal::rejectFloat($price);

        return $this;
    }

    /**
     * Sets the VAT treatment, either via a preset of a country pack or as category and rate.
     */
    public function vat(TaxPreset|VatCategory $presetOrCategory, BigDecimal|int|float|string|null $rate = null, ?string $exemptionReason = null, VatExemptionReasonCode|RawCode|string|null $exemptionReasonCode = null): self
    {
        $rate = Decimal::rejectFloat($rate);
        if ($presetOrCategory instanceof TaxPreset) {
            $this->preset = $presetOrCategory;
            $this->vatCategory = null;

            return $this;
        }

        $this->preset = null;
        $this->vatCategory = $presetOrCategory;
        $this->vatRate = $rate;
        $this->exemptionReason = $exemptionReason;
        $this->exemptionReasonCode = is_string($exemptionReasonCode)
            ? (VatExemptionReasonCode::tryFrom($exemptionReasonCode) ?? new RawCode($exemptionReasonCode))
            : $exemptionReasonCode;

        return $this;
    }

    public function period(Date|string|null $start, Date|string|null $end = null): self
    {
        $this->period = Period::between($start, $end);

        return $this;
    }

    public function allowance(?string $amount = null, ?string $percent = null, ?string $reason = null, AllowanceReasonCode|RawCode|string|null $reasonCode = null, ?string $baseAmount = null): self
    {
        $this->allowanceCharges[] = [
            'charge' => false,
            'amount' => $amount,
            'percent' => $percent,
            'base' => $baseAmount,
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (AllowanceReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
        ];

        return $this;
    }

    public function charge(?string $amount = null, ?string $percent = null, ?string $reason = null, ChargeReasonCode|RawCode|string|null $reasonCode = null, ?string $baseAmount = null): self
    {
        $this->allowanceCharges[] = [
            'charge' => true,
            'amount' => $amount,
            'percent' => $percent,
            'base' => $baseAmount,
            'reason' => $reason,
            'code' => is_string($reasonCode) ? (ChargeReasonCode::tryFrom($reasonCode) ?? new RawCode($reasonCode)) : $reasonCode,
        ];

        return $this;
    }

    public function sellerId(?string $id): self
    {
        $this->sellerId = $id;

        return $this;
    }

    public function buyerId(?string $id): self
    {
        $this->buyerId = $id;

        return $this;
    }

    public function standardId(string $value, IdentifierScheme|RawCode|string $scheme = IdentifierScheme::Gtin): self
    {
        $this->standardId = Identifier::of($value, $scheme);

        return $this;
    }

    public function classification(string $code, ItemClassificationScheme|RawCode|string $scheme, ?string $version = null): self
    {
        $this->classifications[] = new Classification(
            $code,
            is_string($scheme) ? (ItemClassificationScheme::tryFrom($scheme) ?? new RawCode($scheme)) : $scheme,
            $version,
        );

        return $this;
    }

    public function originCountry(?string $country): self
    {
        $this->originCountry = $country === null ? null : strtoupper($country);

        return $this;
    }

    public function attribute(string $name, string $value): self
    {
        $this->attributes[] = new ItemAttribute($name, $value);

        return $this;
    }

    public function orderLineReference(?string $reference): self
    {
        $this->orderLineReference = $reference;

        return $this;
    }

    public function buyerAccountingReference(?string $reference): self
    {
        $this->buyerAccountingReference = $reference;

        return $this;
    }

    public function objectId(string $value, string|RawCode|null $scheme = null): self
    {
        $this->objectId = Identifier::of($value, $scheme);

        return $this;
    }

    /**
     * Adds a sub line (profile EXTENDED or XRechnung extension), in the forms of
     * InvoiceBuilder::line(): a name with the common values, Line::make(), an array, a model or
     * a callable.
     *
     * @param  LineBuilder|ProvidesInvoiceLine|array<array-key, mixed>|callable(LineBuilder): mixed|string  $line
     */
    public function subLine(
        self|ProvidesInvoiceLine|array|callable|string $line,
        BigDecimal|int|float|string|null $netPrice = null,
        BigDecimal|int|float|string|null $quantity = null,
        UnitCode|RawCode|string|null $unit = null,
        TaxPreset|VatCategory|null $vat = null,
        ?string $description = null,
        BigDecimal|int|float|string|null $priceInclVat = null,
    ): self {
        $this->subLines[] = Line::from($line, $netPrice, $quantity, $unit, $vat, $description, $priceInclVat);

        return $this;
    }

    public function reference(DocumentReference $reference): self
    {
        $this->references[] = $reference;

        return $this;
    }

    public function extended(LineExtendedData $extended): self
    {
        $this->extended = $extended;

        return $this;
    }

    /**
     * Assigns the line to a presentation group of the PDF (see InvoiceBuilder::group()).
     */
    public function group(string $groupId): self
    {
        $this->presentation = ($this->presentation ?? new LinePresentation)->with(['groupId' => $groupId]);

        return $this;
    }

    /**
     * Highlights the line in the PDF and HTML (the default templates shade it and mark it in
     * the secondary colour).
     */
    public function highlight(bool $highlight = true): self
    {
        $this->presentation = ($this->presentation ?? new LinePresentation)->with(['highlight' => $highlight]);

        return $this;
    }

    /**
     * Prints the price including VAT below the net unit price of this line (true) or not
     * (false), whatever Presentation::$showGrossPrices says for the invoice; null follows the
     * presentation again. It is printed only for lines with a price including VAT (priceInclVat()).
     */
    public function showGrossPrice(?bool $show = true): self
    {
        $this->presentation = ($this->presentation ?? new LinePresentation)->with(['showGrossPrice' => $show]);

        return $this;
    }

    /**
     * Builds the line; called by InvoiceBuilder. $vatDefault is a builder whose VAT treatment
     * applies to this line and its sub lines when they have none (InvoiceBuilder::defaultVat()).
     */
    public function build(string $currency, string $defaultId, ?string $locale = null, ?self $vatDefault = null): InvoiceLine
    {
        $name = $this->name ?? throw InvalidInvoiceData::missing('BT-153', sprintf('item name of line %s', $this->id ?? $defaultId));
        $id = $this->id ?? $defaultId;

        $grossPrice = Decimal::ofNullable($this->grossPrice);
        $discount = Decimal::ofNullable($this->priceDiscount);
        $netPrice = Decimal::ofNullable($this->netPrice);
        if ($netPrice === null && $grossPrice !== null) {
            $netPrice = $discount === null ? $grossPrice : $grossPrice->minus($discount);
        }

        $subLines = [];
        foreach ($this->subLines as $index => $subLine) {
            $subLines[] = $subLine->build($currency, $id.'.'.($index + 1), $locale, $vatDefault);
        }

        return new InvoiceLine(
            id: $id,
            quantity: Decimal::of($this->quantity ?? 1),
            unit: $this->unit,
            price: new Price(
                netPrice: $netPrice,
                discount: $discount,
                grossPrice: $grossPrice,
                baseQuantity: Decimal::ofNullable($this->baseQuantity),
                baseQuantityUnit: $this->baseQuantityUnit ?? ($this->baseQuantity !== null ? $this->unit : null),
                priceInclVat: Decimal::ofNullable($this->priceInclVat),
            ),
            vat: ($this->hasVat() || $vatDefault === null ? $this : $vatDefault)->buildVat($id, $locale),
            item: new Item(
                name: $name,
                description: $this->description,
                sellerId: $this->sellerId,
                buyerId: $this->buyerId,
                standardId: $this->standardId,
                classifications: $this->classifications,
                originCountry: $this->originCountry,
                attributes: $this->attributes,
            ),
            note: $this->note,
            objectId: $this->objectId,
            orderLineReference: $this->orderLineReference,
            buyerAccountingReference: $this->buyerAccountingReference,
            period: $this->period,
            allowances: array_values(array_map(
                static fn (array $a): LineAllowance => new LineAllowance(
                    amount: Amount::ofNullable($a['amount'], $currency),
                    baseAmount: Amount::ofNullable($a['base'], $currency),
                    percent: Decimal::ofNullable($a['percent']),
                    reason: $a['reason'],
                    reasonCode: $a['code'] instanceof ChargeReasonCode ? new RawCode($a['code']->value) : $a['code'],
                ),
                array_filter($this->allowanceCharges, static fn (array $a): bool => ! $a['charge']),
            )),
            charges: array_values(array_map(
                static fn (array $c): LineCharge => new LineCharge(
                    amount: Amount::ofNullable($c['amount'], $currency),
                    baseAmount: Amount::ofNullable($c['base'], $currency),
                    percent: Decimal::ofNullable($c['percent']),
                    reason: $c['reason'],
                    reasonCode: $c['code'] instanceof AllowanceReasonCode ? new RawCode($c['code']->value) : $c['code'],
                ),
                array_filter($this->allowanceCharges, static fn (array $c): bool => $c['charge']),
            )),
            subLines: $subLines,
            lineReferences: $this->references,
            extended: $this->extended,
            presentation: $this->presentation,
        );
    }

    private function buildVat(string $id, ?string $locale): LineVat
    {
        if ($this->preset !== null) {
            return new LineVat(
                category: $this->preset->category(),
                rate: $this->preset->rate(),
                exemptionReason: $this->preset->exemptionReason($locale),
                exemptionReasonCode: $this->preset->exemptionReasonCode(),
                presetKey: $this->preset->key(),
            );
        }

        if ($this->vatCategory === null) {
            throw InvalidInvoiceData::missing('BG-30', sprintf('VAT information of line %s', $id));
        }

        return new LineVat(
            category: $this->vatCategory,
            rate: Decimal::ofNullable($this->vatRate),
            exemptionReason: $this->exemptionReason,
            exemptionReasonCode: $this->exemptionReasonCode,
        );
    }
}
