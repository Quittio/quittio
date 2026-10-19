<?php

declare(strict_types=1);

use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentCalculator;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DocumentType;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;

function bonusStatement(): DocumentBuilder
{
    return DocumentBuilder::bonusStatement()
        ->number('BO-2027-0001')
        ->issueDate('2027-01-20')
        ->locale('de')
        ->seller(fn ($s) => $s->name('Muster GmbH')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
        ->buyer(fn ($b) => $b->name('Handel AG')->vatId('DE987654321')->address('Ring 1', '54321', 'Beispielstadt', 'DE'))
        ->bonusPeriod('2026-01-01', '2026-12-31')
        ->bonusAgreement('der Bonusvereinbarung vom 15.01.2026');
}

it('reduces the consideration of a period per VAT rate (section 17 (4) UStG)', function () {
    $document = (new DocumentCalculator)->calculate(bonusStatement()
        ->bonusOnRevenue('100000.00', '3', VatCategory::StandardRate, '19')
        ->bonusOnRevenue('20000.00', '3', VatCategory::StandardRate, '7')
        ->bonus('150.00', VatCategory::Exempt, '0', 'Steuerfrei nach § 4 Nr. 8 UStG')
        ->bonusInvoices('RE-2026-0001', 'RE-2026-0002')
        ->build());
    $rows = array_map(static fn ($row): array => [$row->category->value, (string) $row->rate?->strippedOfTrailingZeros(), $row->taxableAmount->getAmount()->toString(), $row->taxAmount->getAmount()->toString()], $document->vatBreakdown);

    expect($document->type)->toBe(DocumentType::BonusStatement)
        ->and($document->bonus?->period->start?->toIsoString())->toBe('2026-01-01')
        ->and($document->bonus?->agreement)->toBe('der Bonusvereinbarung vom 15.01.2026')
        ->and($document->bonus?->invoices)->toBe(['RE-2026-0001', 'RE-2026-0002'])
        ->and($document->lines[0]->item->name)->toBe('Bonus')
        ->and($document->lines[0]->item->description)->toBe("3\u{00A0}% auf den Nettoumsatz (Entgelt) von 100.000,00\u{00A0}€")
        ->and($rows)->toContain(['S', '19', '3000.00', '570.00'], ['S', '7', '600.00', '42.00'], ['E', '0', '150.00', '0.00'])
        ->and($document->totals?->grossTotal->getAmount()->toString())->toBe('4362.00')
        ->and(DocumentData::fromJson($document->toJson())->bonus?->invoices)->toBe(['RE-2026-0001', 'RE-2026-0002']);
});

it('names period, agreement and invoices and asks for no payment', function () {
    $document = (new DocumentCalculator)->calculate(bonusStatement()->bonus('3000.00', VatCategory::StandardRate, '19')->bonusInvoices('RE-2026-0001')->build());
    $view = (new ViewModelFactory)->buildDocument($document, new ViewContext(locale: 'de'));

    expect($view->title())->toBe('Bonusabrechnung')
        ->and(iterator_to_array($view->legalNotes))->toBe([
            'Nachträgliche Minderung der Entgelte für die Lieferungen und Leistungen vom 01.01.2026 bis 31.12.2026 gemäß der Bonusvereinbarung vom 15.01.2026.',
            'Betroffene Rechnungen: RE-2026-0001',
            'Der Bonus wird mit offenen Forderungen verrechnet oder ausgezahlt.',
        ])
        ->and($view->payment)->toBeNull()
        ->and($view->totals?->amountDue)->toBeNull();
});

it('requires period, agreement and a reduction', function (Closure $build, string $message) {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no period' => [fn () => DocumentBuilder::bonusStatement()->number('B')->seller(fn ($s) => $s->name('S')->address('a', '1', 'b', 'DE'))->buyer(fn ($b) => $b->name('B')->address('a', '1', 'b', 'DE'))->bonusAgreement('x')->bonus('1', VatCategory::StandardRate, '19')->build(), 'requires its period'],
    'no agreement' => [fn () => bonusStatement()->bonusAgreement(' ')->bonus('1', VatCategory::StandardRate, '19')->build(), 'must name the agreement'],
    'no reduction' => [fn () => bonusStatement()->build(), 'requires at least one line'],
]);

it('names reductions instead of prices, VAT and amounts, so that it does not read like an invoice', function () {
    $document = (new DocumentCalculator)->calculate(bonusStatement()
        ->bonus('3000.00', VatCategory::StandardRate, '19')
        ->bonus('300.00', VatCategory::IntraCommunitySupply, '0')
        ->build());
    $view = (new ViewModelFactory)->buildDocument($document, new ViewContext(locale: 'de'));
    $rows = array_map(static fn ($row): string => (string) $row->label, iterator_to_array($view->vatRows));

    expect((string) $view->labels->unit_price)->toBe('Minderung')
        ->and((string) $view->labels->total)->toBe('Summe')
        ->and((string) $view->labels->net_total)->toBe('Minderung Entgelt gesamt')
        ->and((string) $view->labels->gross_total)->toBe('Bonus gesamt (brutto)')
        ->and($rows)->toContain("Minderung USt. 19\u{00A0}%", 'Minderung ohne USt. (Innergemeinschaftliche Lieferung)');
});

it('states the reduction for intra-community supplies and exports instead of the exemption of a supply', function (VatCategory $category, string $reason, string $code) {
    $document = bonusStatement()->bonus('300.00', $category)->build();

    expect($document->lines[0]->vat->exemptionReason)->toBe($reason)
        ->and((string) $document->lines[0]->vat->exemptionReasonCode?->value)->toBe($code)
        ->and(bonusStatement()->bonus('300.00', $category, '0', 'Eigener Text')->build()->lines[0]->vat->exemptionReason)->toBe('Eigener Text');
})->with([
    'intra-community supply' => [VatCategory::IntraCommunitySupply, 'Minderung des Entgelts für steuerfreie innergemeinschaftliche Lieferungen', 'VATEX-EU-IC'],
    'export' => [VatCategory::Export, 'Minderung des Entgelts für steuerfreie Ausfuhrlieferungen', 'VATEX-EU-G'],
]);
