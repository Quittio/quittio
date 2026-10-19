<?php

declare(strict_types=1);

namespace Quittio\Country\CH\Tests\Support;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Country\CH\CountryCH;
use Quittio\Country\CH\Tax\VatPreset;

final class Swiss
{
    /** A QR-IBAN (institution identification 31999). */
    public const string QR_IBAN = 'CH4431999123000889012';

    /** An ordinary IBAN of PostFinance. */
    public const string IBAN = 'CH9300762011623852957';

    /**
     * A complete invoice of a Swiss seller to a Swiss business, CHF 1,818.00 net.
     */
    public static function invoice(string $iban = self::QR_IBAN): InvoiceBuilder
    {
        return self::base($iban)
            ->line(fn ($l) => $l->name('Softwareentwicklung')->quantity(12, UnitCode::Hour)->netPrice('145.00')->vat(VatPreset::Standard))
            ->line(fn ($l) => $l->name('Fachbuch')->quantity(2, UnitCode::Piece)->netPrice('39.00')->vat(VatPreset::Reduced));
    }

    public static function base(string $iban = self::QR_IBAN): InvoiceBuilder
    {
        return InvoiceBuilder::make()
            ->number('RE-2026-0042')
            ->issueDate('2026-10-05')
            ->currency('CHF')
            ->seller(fn ($s) => $s
                ->name('Bergsoft AG')
                ->vatId('CHE-123.456.788 MWST')
                ->address('Bahnhofstrasse 12', '8001', 'Zürich', 'CH')
                ->contact('Lea Meier', '+41 44 123 45 67', 'rechnung@bergsoft.example'))
            ->buyer(fn ($b) => $b->name('Muster GmbH')->address('Marktgasse 28', '3011', 'Bern', 'CH'))
            ->deliveryDate('2026-09-30')
            ->paymentTerms(fn ($t) => $t->dueInDays(30))
            ->payment(PaymentMeansCode::SepaCreditTransfer, iban: $iban);
    }

    public static function calculated(InvoiceBuilder $builder): InvoiceData
    {
        return (new En16931Calculator)->calculate($builder->build());
    }

    public static function validate(InvoiceBuilder $builder, string $locale = 'en'): ValidationResult
    {
        $context = new ValidationContext(ProfileRegistry::withDefaults()->get('en16931'), [new CountryCH], $locale);

        return (new PhpRulesDriver)->validate(new ValidationSubject(self::calculated($builder), $context));
    }

    /**
     * @return list<string>
     */
    public static function codes(ValidationResult $result): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($m): string => $m->code, $result->messages),
            static fn (string $code): bool => str_starts_with($code, 'CH-'),
        )));
    }
}
