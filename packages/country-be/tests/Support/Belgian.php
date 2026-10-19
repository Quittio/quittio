<?php

declare(strict_types=1);

namespace Quittio\Country\BE\Tests\Support;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Country\BE\CountryBE;
use Quittio\Country\BE\Tax\VatPreset;

final class Belgian
{
    /**
     * A complete invoice of a business in Bruges to a business in Ghent, in Dutch.
     */
    public static function invoice(): InvoiceBuilder
    {
        return self::base()
            ->line(fn ($l) => $l->name('Webontwikkeling')->quantity(10, UnitCode::Hour)->netPrice('95.00')->vat(VatPreset::Standard));
    }

    public static function base(): InvoiceBuilder
    {
        return InvoiceBuilder::make()
            ->number('F-2026-0042')
            ->issueDate('2026-10-05')
            ->currency('EUR')
            ->locale('nl_BE')
            ->seller(fn ($s) => $s
                ->name('Brugse Software BV')
                ->vatId('BE0477472701')
                ->address('Markt 1', '8000', 'Brugge', 'BE')
                ->contact('An Peeters', '+32 50 12 34 56', 'facturen@brugse-software.example'))
            ->buyer(fn ($b) => $b
                ->name('Gentse Handel NV')
                ->vatId('BE0403170701')
                ->address('Korenmarkt 10', '9000', 'Gent', 'BE'))
            ->deliveryDate('2026-09-30')
            ->paymentTerms(fn ($t) => $t->dueInDays(30))
            ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'BE68539007547034', remittanceInformation: '+++090/9337/55493+++');
    }

    public static function calculated(InvoiceBuilder $builder): InvoiceData
    {
        return (new En16931Calculator)->calculate($builder->build());
    }

    public static function validate(InvoiceBuilder $builder, string $profile = 'en16931', ?Syntax $syntax = null, ?string $locale = null): ValidationResult
    {
        $context = new ValidationContext(ProfileRegistry::withDefaults()->get($profile), [new CountryBE], $locale ?? 'nl_BE', null, $syntax === null ? [] : ['syntax' => $syntax]);

        return (new PhpRulesDriver)->validate(new ValidationSubject(self::calculated($builder), $context));
    }

    /**
     * @return list<string>
     */
    public static function codes(ValidationResult $result): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($m): string => $m->code, $result->messages),
            static fn (string $code): bool => str_starts_with($code, 'BE-'),
        )));
    }
}
