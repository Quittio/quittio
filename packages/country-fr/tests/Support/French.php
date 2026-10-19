<?php

declare(strict_types=1);

namespace Quittio\Country\FR\Tests\Support;

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
use Quittio\Country\FR\CountryFR;
use Quittio\Country\FR\Tax\VatPreset;

final class French
{
    /**
     * A complete invoice of a business in Lyon to a business in Paris after the reform, with the
     * mentions the pack adds (complete()).
     */
    public static function invoice(): InvoiceBuilder
    {
        return self::base()
            ->line(fn ($l) => $l->name('Développement web')->quantity(10, UnitCode::Hour)->netPrice('90.00')->vat(VatPreset::Standard));
    }

    public static function base(): InvoiceBuilder
    {
        return InvoiceBuilder::make()
            ->number('F-2026-00042')
            ->issueDate('2026-10-05')
            ->currency('EUR')
            ->locale('fr')
            ->businessProcess('S1')
            ->seller(fn ($s) => $s
                ->name('Lyon Logiciels SAS')
                ->vatId('FR44732829320')
                ->legalRegistration('732829320', '0002')
                ->address('12 rue de la République', '69002', 'Lyon', 'FR')
                ->contact('Claire Martin', '+33 4 72 00 00 00', 'factures@lyon-logiciels.example'))
            ->buyer(fn ($b) => $b
                ->name('Paris Commerce SARL')
                ->vatId('FR96552100554')
                ->legalRegistration('552100554', '0002')
                ->address('5 avenue de l\'Opéra', '75001', 'Paris', 'FR'))
            ->deliveryDate('2026-09-30')
            ->paymentTerms(fn ($t) => $t->dueInDays(30))
            ->payment(PaymentMeansCode::SepaCreditTransfer, iban: 'FR1420041010050500013M02606');
    }

    /**
     * The invoice completed by the pack and calculated.
     */
    public static function calculated(InvoiceBuilder $builder, array $settings = []): InvoiceData
    {
        return (new En16931Calculator)->calculate((new CountryFR)->complete($builder->build(), $settings));
    }

    public static function validate(InvoiceBuilder $builder, ?Syntax $syntax = null, array $settings = [], string $locale = 'fr'): ValidationResult
    {
        $context = new ValidationContext(ProfileRegistry::withDefaults()->get('en16931'), [new CountryFR], $locale, null, $syntax === null ? [] : ['syntax' => $syntax]);

        return (new PhpRulesDriver)->validate(new ValidationSubject(self::calculated($builder, $settings), $context));
    }

    /**
     * The findings for invoice data as it is, completed by the pack and calculated.
     */
    public static function validateData(InvoiceData $invoice): ValidationResult
    {
        $context = new ValidationContext(ProfileRegistry::withDefaults()->get('en16931'), [new CountryFR], 'fr');

        return (new PhpRulesDriver)->validate(new ValidationSubject((new En16931Calculator)->calculate((new CountryFR)->complete($invoice, [])), $context));
    }

    /**
     * @return list<string>
     */
    public static function codes(ValidationResult $result): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn ($m): string => $m->code, $result->messages),
            static fn (string $code): bool => str_starts_with($code, 'FR-'),
        )));
    }
}
