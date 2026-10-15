<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\Drivers\PhpRulesDriver;
use Quittio\Core\Validation\ValidationContext;
use Quittio\Core\Validation\ValidationSubject;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Rules\UstgRules;

/**
 * @return array<string, string> DE-* code => severity
 */
function round3UstgFindings(InvoiceBuilder $builder): array
{
    $invoice = (new En16931Calculator)->calculate($builder->build());
    $result = (new PhpRulesDriver)->validate(new ValidationSubject($invoice, new ValidationContext(ProfileRegistry::withDefaults()->get('en16931'), [new CountryDE], 'en')));
    $findings = [];
    foreach ($result->messages as $message) {
        if (str_starts_with($message->code, 'DE-')) {
            $findings[$message->code] = $message->severity->value;
        }
    }

    return $findings;
}

function round3SmallConsumerInvoice(string $country, ?string $postcode = null): InvoiceBuilder
{
    return Scenarios::base()->smallAmount()
        ->buyer(fn ($b) => $b->name('Kunde')->vatId(null)->consumer()->address(null, $postcode, null, $country))
        ->line(fn ($l) => $l->name('E-Book-Abo')->quantity(1, UnitCode::Piece)->netPrice('10.00')->vat(VatCategory::StandardRate, '20'));
}

it('keeps the small amount simplifications for consumers in other member states and only hints at distance sales (section 3c UStG covers goods only)', function () {
    // A 12 EUR e-service (OSS) to an Austrian consumer or a sale on the premises is no distance sale.
    $abroad = round3SmallConsumerInvoice('AT');

    expect(round3UstgFindings($abroad))->toBe(['DE-USTG-12' => 'warning'])
        ->and(UstgRules::isSmallAmountInvoice($abroad->build()))->toBeTrue();
});

it('looks at the deliver to address and the VAT territory for possible distance sales', function () {
    $deliveredToAustria = Scenarios::base()->smallAmount()
        ->buyer(fn ($b) => $b->name('Kunde')->vatId(null)->consumer()->address(null, null, 'Berlin', 'DE'))
        ->deliverTo('Oma', Address::make('Ring 1', '1010', 'Wien', 'AT'))
        ->line(fn ($l) => $l->name('Buch')->quantity(1, UnitCode::Piece)->netPrice('20.00')->vat(VatCategory::StandardRate, '10'));

    expect(round3UstgFindings($deliveredToAustria))->toBe(['DE-USTG-12' => 'warning'])
        ->and(round3UstgFindings(round3SmallConsumerInvoice('MC', '98000')))->toBe(['DE-USTG-12' => 'warning'])
        ->and(round3UstgFindings(round3SmallConsumerInvoice('GB', 'BT1 1AA')))->toBe(['DE-USTG-12' => 'warning'])
        ->and(round3UstgFindings(round3SmallConsumerInvoice('DE', '78266')))->toBe([])
        ->and(round3UstgFindings(round3SmallConsumerInvoice('CH', '8001')))->toBe([]);
});

it('still rejects the small amount simplifications for intra-community supplies (section 33 sentence 3 UStDV)', function () {
    $supply = Scenarios::base()->smallAmount()
        ->buyer(fn ($b) => $b->name('AT GmbH')->vatId('ATU12345678')->address('Ring 1', '1010', 'Wien', 'AT'))
        ->deliverTo('Lager', Address::make('Ring 2', '1010', 'Wien', 'AT'))
        ->line(fn ($l) => $l->name('Teil')->quantity(1, UnitCode::Piece)->netPrice('100.00')->vat(VatCategory::IntraCommunitySupply, '0', 'Steuerfreie innergemeinschaftliche Lieferung'));

    expect(round3UstgFindings($supply))->toHaveKey('DE-USTG-12', 'error')
        ->and(UstgRules::isSmallAmountInvoice($supply->build()))->toBeFalse();
});

it('treats Monaco as a member state and Northern Ireland not for services when reverse charge needs both VAT identifiers', function () {
    $reverseCharge = fn (string $country, string $postcode) => Scenarios::base()
        ->buyer(fn ($b) => $b->name('Client')->vatId(null)->legalRegistration('123')->address('Road 1', $postcode, 'Town', $country))
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Piece)->netPrice('1000.00')->vat(VatCategory::ReverseCharge, '0', 'Steuerschuldnerschaft des Leistungsempfängers'));

    expect(round3UstgFindings($reverseCharge('MC', '98000')))->toHaveKey('DE-USTG-11')
        ->and(round3UstgFindings($reverseCharge('GB', 'BT1 1AA')))->not->toHaveKey('DE-USTG-11');
});
