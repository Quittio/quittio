<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\Plausibility\VatIdVerifier;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationResult;
use Quittio\Core\Validation\ValidationSubject;

final class Validate
{
    /**
     * @param  list<CountryPack>  $packs
     */
    public static function data(InvoiceData $invoice, Profile|string $profile = 'en16931', array $packs = [], string $locale = 'en', ?VatIdVerifier $verifier = null, bool $calculate = true): ValidationResult
    {
        $profile = ProfileRegistry::withDefaults()->get($profile);
        $invoice = $calculate ? (new En16931Calculator)->calculate($invoice) : $invoice;

        return (new PhpRulesDriver(vatIdVerifier: $verifier))->validate(
            new ValidationSubject($invoice, new ValidationContext($profile, $packs, $locale)),
        );
    }

    /**
     * @return list<string>
     */
    public static function codes(ValidationResult $result): array
    {
        return array_values(array_unique(array_map(static fn ($m): string => $m->code, $result->messages)));
    }
}
