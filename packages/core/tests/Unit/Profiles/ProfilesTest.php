<?php

declare(strict_types=1);

use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\ExtendedParty;
use Quittio\Core\Data\Extensions\ThirdPartyPayment;
use Quittio\Core\Data\Extensions\XRechnungExtensionData;
use Quittio\Core\Data\Party\Address;
use Quittio\Core\Data\Party\Contact;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Mapping\FieldMatrix;
use Quittio\Core\Profiles\ProfileLevel;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Support\Amount;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;

it('provides the Factur-X profiles with identifiers and PDF metadata', function (string $key, string $id, ?string $level, ?string $relationship) {
    $profile = ProfileRegistry::withDefaults()->get($key);

    expect($profile->specificationId())->toBe($id)
        ->and($profile->xmpConformanceLevel())->toBe($level)
        ->and($profile->afRelationship())->toBe($relationship);
})->with([
    ['minimum', 'urn:factur-x.eu:1p0:minimum', 'MINIMUM', 'Data'],
    ['basic-wl', 'urn:factur-x.eu:1p0:basicwl', 'BASIC WL', 'Data'],
    ['basic', 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic', 'BASIC', 'Alternative'],
    ['en16931', 'urn:cen.eu:en16931:2017', 'EN 16931', 'Alternative'],
    ['extended', 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended', 'EXTENDED', 'Alternative'],
    ['peppol', 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0', null, null],
]);

it('finds profiles by specification identifier and alias', function () {
    $registry = ProfileRegistry::withDefaults();

    expect($registry->bySpecificationId('urn:cen.eu:en16931:2017')?->key())->toBe('en16931')
        ->and($registry->bySpecificationId('urn:cen.eu:en16931:2026')?->key())->toBe('en16931')
        ->and($registry->get('BASICWL')->key())->toBe('basic-wl')
        ->and($registry->get('peppol')->syntaxes())->toBe([Syntax::Ubl])
        ->and($registry->get('en16931')->supportsStandard(StandardVersion::En16931_2026))->toBeTrue()
        ->and($registry->get('extended')->supportsStandard(StandardVersion::En16931_2026))->toBeFalse();
});

it('rejects EXTENDED data in other profiles', function () {
    $invoice = Invoices::simple()->extended(new ExtendedData(invoicer: new ExtendedParty('Invoicer')))->build();

    expect(Validate::codes(Validate::data($invoice, 'en16931')))->toContain('QUITTIO-PROFILE-EXTENDED-ONLY')
        ->and(Validate::codes(Validate::data($invoice, 'extended')))->not->toContain('QUITTIO-PROFILE-EXTENDED-ONLY');
});

it('warns about fields that a smaller profile cannot carry', function () {
    $invoice = Invoices::simple()->projectReference('P-1', 'Portal')->build();

    $result = Validate::data($invoice, 'basic');

    expect(Validate::codes($result))->toContain('QUITTIO-PROFILE-NOT-ALLOWED')
        ->and($result->isValid())->toBeTrue();
});

it('marks MINIMUM and BASIC WL as no e-invoice', function () {
    expect(Validate::codes(Validate::data(Invoices::simple()->build(), 'minimum')))->toContain('QUITTIO-PROFILE-NOT-EINVOICE');
});

it('requires the electronic addresses in Peppol and applies Peppol rules', function () {
    $invoice = Invoices::simple()->seller(fn ($s) => $s)->build();
    $withoutAddress = $invoice->with(['buyer' => $invoice->buyer->with(['electronicAddress' => null])]);

    $codes = Validate::codes(Validate::data($withoutAddress, 'peppol'));

    expect($codes)->toContain('QUITTIO-PROFILE-REQUIRED')
        ->toContain('PEPPOL-EN16931-R010')
        ->toContain('PEPPOL-EN16931-R003');
});

it('rejects sub lines outside of EXTENDED', function () {
    $invoice = Invoices::base()
        ->line(fn ($l) => $l->name('Bundle')->vat(VatCategory::StandardRate, '19')
            ->subLine(fn ($s) => $s->name('Part')->quantity(1)->netPrice('10')->vat(VatCategory::StandardRate, '19')))
        ->build();

    expect(Validate::codes(Validate::data($invoice, 'en16931')))->toContain('QUITTIO-PROFILE-SUBLINES')
        ->and(Validate::codes(Validate::data($invoice, 'extended')))->not->toContain('QUITTIO-PROFILE-SUBLINES');
});

it('allows the postal addresses in BASIC WL and BASIC and names their business terms', function (string $profile) {
    $invoice = Invoices::simple()
        ->seller(fn ($s) => $s->address('Main Street 1', '12345', 'Sample City', 'DE', line2: 'Building B', line3: 'Floor 3', subdivision: 'Berlin'))
        ->buyer(fn ($b) => $b->address('Station Road 5', '54321', 'Example Town', 'DE', line2: 'Gate 2', line3: 'Dock 4', subdivision: 'Hessen'))
        ->deliverTo('Warehouse', Address::make('Harbour 9', '20457', 'Hamburg', 'DE')->with(['line2' => 'Hall 3', 'subdivision' => 'Hamburg']))
        ->taxRepresentative('Representative GmbH', 'DE111111111', Address::make('Tax Street 2', '10115', 'Berlin', 'DE'))
        ->build();

    $notAllowed = array_map(
        static fn ($m): string => $m->path.' '.$m->bt,
        array_values(array_filter(Validate::data($invoice, $profile)->messages, static fn ($m): bool => $m->code === 'QUITTIO-PROFILE-NOT-ALLOWED')),
    );

    // Only the parts of the test invoice that the profile does not carry: the seller contact
    // (BG-6) and the BIC (BT-86, both EN 16931), in BASIC WL also the lines (BG-25).
    expect($notAllowed)->toBe([
        'seller.contact BG-6',
        ...($profile === 'basic-wl' ? ['lines BG-25'] : []),
        'paymentInstructions.creditTransfers[0].serviceProviderId BT-86',
    ]);
})->with(['basic-wl', 'basic']);

it('still warns about fields that BASIC WL and MINIMUM do not carry', function () {
    $invoice = Invoices::simple()->projectReference('P-1', 'Portal')->build();
    $paths = static fn (string $profile): array => array_map(
        static fn ($m): string => $m->path.' '.$m->bt,
        array_values(array_filter(Validate::data($invoice, $profile)->messages, static fn ($m): bool => $m->code === 'QUITTIO-PROFILE-NOT-ALLOWED')),
    );

    // The seller contact (BG-6) is EN 16931; its parts are covered by the finding of the group.
    expect($paths('basic-wl'))->toContain('seller.contact BG-6', 'projectReference BT-11')
        ->not->toContain('seller.contact.name BT-41')
        // MINIMUM carries only the seller country code (BT-40) and no buyer address.
        ->and($paths('minimum'))->toContain('seller.address.line1 BT-35', 'seller.address.city BT-37', 'buyer.address BG-8')
        ->not->toContain('seller.address.countryCode BT-40')
        ->not->toContain('buyer.address.city BT-52');
});

it('resolves level and business term of address parts from the parent field', function () {
    expect(FieldMatrix::level(Address::class, 'countryCode', ProfileLevel::Minimum))->toBe(ProfileLevel::Minimum)
        ->and(FieldMatrix::level(Address::class, 'line1', ProfileLevel::Minimum))->toBe(ProfileLevel::BasicWl)
        ->and(FieldMatrix::level(Address::class, 'countryCode', ProfileLevel::BasicWl))->toBe(ProfileLevel::BasicWl)
        ->and(FieldMatrix::level(Address::class, 'city', ProfileLevel::Extended))->toBe(ProfileLevel::Extended)
        ->and(FieldMatrix::businessTerm(Address::class, 'countryCode', 'BG-5'))->toBe('BT-40')
        ->and(FieldMatrix::businessTerm(Address::class, 'countryCode', 'BG-8'))->toBe('BT-55')
        ->and(FieldMatrix::businessTerm(Address::class, 'city', 'BG-12'))->toBe('BT-66')
        ->and(FieldMatrix::businessTerm(Address::class, 'postcode', 'BG-15'))->toBe('BT-78')
        ->and(FieldMatrix::businessTerm(Contact::class, 'email', 'BG-9'))->toBe('BT-58')
        ->and(FieldMatrix::businessTerm(Period::class, 'start', 'BG-26'))->toBe('BT-134');
});

it('reports data of EXTENDED and the XRechnung extension once, at the field that is not allowed', function () {
    $invoice = Invoices::simple()
        ->extended(new ExtendedData(invoicer: new ExtendedParty('Invoicer', Address::make('Main Street 1', '12345', 'Sample City', 'DE'))))
        ->xrechnungExtension(new XRechnungExtensionData([new ThirdPartyPayment('Porto', Amount::of('4.90', 'EUR'), 'Briefporto')]))
        ->build();

    $findings = array_map(
        static fn ($m): string => $m->code.' '.$m->path,
        array_values(array_filter(Validate::data($invoice)->messages, static fn ($m): bool => str_starts_with($m->code, 'QUITTIO-PROFILE-'))),
    );

    expect($findings)->toBe([
        'QUITTIO-PROFILE-EXTENDED-ONLY extended',
        'QUITTIO-PROFILE-XRECHNUNG-EXTENSION-ONLY xrechnungExtension',
    ]);
});
