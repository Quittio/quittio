<?php

declare(strict_types=1);

namespace Quittio\Country\AT\Tests\Support;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Enums\PaymentMeansCode;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Country\AT\CountryAT;
use Quittio\Country\AT\Tax\VatPreset;

final class Austrian
{
    /**
     * A complete invoice of an Austrian seller to an Austrian business, 1,200 EUR net.
     */
    public static function invoice(?string $deliveryDate = '2026-09-30'): InvoiceBuilder
    {
        return self::base($deliveryDate)
            ->line(fn ($l) => $l->name('Softwareentwicklung')->quantity(12, UnitCode::Hour)->netPrice('100.00')->vat(VatPreset::Standard));
    }

    /**
     * The invoice without lines.
     */
    public static function base(?string $deliveryDate = '2026-09-30'): InvoiceBuilder
    {
        $builder = InvoiceBuilder::make()
            ->number('RE-2026-0001')
            ->issueDate('2026-10-05')
            ->currency('EUR')
            ->seller(fn ($s) => $s
                ->name('Alpen Software GmbH')
                ->vatId('ATU12345678')
                ->address('Mariahilfer Straße 1', '1060', 'Wien', 'AT')
                ->contact('Anna Huber', '+43 1 234567', 'rechnung@alpen-software.example')
                ->electronicAddress('rechnung@alpen-software.example', 'EM'))
            ->buyer(fn ($b) => $b
                ->name('Tiroler Handel KG')
                ->vatId('ATU87654321')
                ->address('Maria-Theresien-Straße 10', '6020', 'Innsbruck', 'AT')
                ->electronicAddress('einkauf@tiroler-handel.example', 'EM'))
            ->paymentTerms(fn ($t) => $t->dueInDays(14))
            ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'AT611904300234573201', bic: 'BKAUATWW');

        return $deliveryDate === null ? $builder : $builder->deliveryDate($deliveryDate);
    }

    public static function profiles(): ProfileRegistry
    {
        $registry = ProfileRegistry::withDefaults();
        foreach ((new CountryAT)->profiles() as $profile) {
            $registry->register($profile);
        }

        return $registry;
    }

    /**
     * The findings of the PHP rules for the calculated invoice, with the Austrian pack leading.
     */
    public static function validate(InvoiceBuilder $builder, string $profile = 'en16931', ?Syntax $syntax = null, string $locale = 'en'): ValidationResult
    {
        $invoice = (new En16931Calculator)->calculate($builder->build());
        $context = new ValidationContext(self::profiles()->get($profile), [new CountryAT], $locale, null, $syntax === null ? [] : ['syntax' => $syntax]);

        return (new PhpRulesDriver)->validate(new ValidationSubject($invoice, $context));
    }

    /**
     * The codes of the Austrian findings (AT-…), in order and without repetition.
     *
     * @return list<string>
     */
    public static function codes(ValidationResult $result): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($m): string => $m->code, $result->messages),
            static fn (string $code): bool => str_starts_with($code, 'AT-'),
        )));
    }
}
