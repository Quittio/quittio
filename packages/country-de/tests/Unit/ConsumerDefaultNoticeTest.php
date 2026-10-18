<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Testing\Scenarios;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Tax\VatPreset;

const CONSUMER_DEFAULT_TEXT = 'Sie kommen spätestens in Verzug, wenn Sie nicht innerhalb von 30 Tagen nach Fälligkeit und Zugang dieser Rechnung zahlen (§ 286 Abs. 3 BGB).';

/**
 * @return list<string>
 */
function renderedNotes(Quittio $quittio, InvoiceBuilder $builder): array
{
    return array_map(static fn ($note): string => $note->content, $quittio->make($builder)->format('pdf')->render()->data()->notes);
}

function consumerInvoice(): InvoiceBuilder
{
    return Scenarios::base()
        ->buyer(fn ($b) => $b->name('Erika Mustermann')->vatId(null)->consumer()->address('Lindenweg 3', '10115', 'Berlin', 'DE'))
        ->line(fn ($l) => $l->name('Kochbuch')->quantity(1, UnitCode::Piece)->netPrice('20.00')->vat(VatPreset::Reduced));
}

it('adds the default notice to invoices for consumers (section 286 (3) BGB)', function () {
    $quittio = Quittio::create([], [new CountryDE]);

    expect(renderedNotes($quittio, consumerInvoice()))->toBe([CONSUMER_DEFAULT_TEXT])
        // Not twice, not for businesses, not for credit notes.
        ->and(renderedNotes($quittio, consumerInvoice()->de()->consumerDefaultNotice()))->toBe([CONSUMER_DEFAULT_TEXT])
        ->and(renderedNotes($quittio, Scenarios::make('simple')))->toBe([])
        ->and(renderedNotes($quittio, consumerInvoice()->type('381')->precedingInvoice('RE-1', '2026-09-01')))->not->toContain(CONSUMER_DEFAULT_TEXT)
        ->and(renderedNotes($quittio, consumerInvoice()->locale('en')))->toBe(['You will be in default at the latest if you do not pay within 30 days of the due date and receipt of this invoice (section 286 (3) BGB).']);
});

it('leaves the default notice out with the setting consumer_default_notice = false', function () {
    $quittio = Quittio::create(['countries' => ['de' => ['consumer_default_notice' => false]]], [new CountryDE]);

    expect(renderedNotes($quittio, consumerInvoice()))->toBe([]);
});
