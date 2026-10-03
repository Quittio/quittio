<?php

declare(strict_types=1);

use Quittio\Core\Data\Extensions\ExtendedData;
use Quittio\Core\Data\Extensions\ExtendedParty;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Standards\StandardVersion;
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
