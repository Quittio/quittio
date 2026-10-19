<?php

declare(strict_types=1);

use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Documents\DunningFeeKind;
use Quittio\Core\Exceptions\InvalidArgumentException;

function flatRateReminder(): DocumentBuilder
{
    return DocumentBuilder::paymentReminder(1)
        ->number('M-1')
        ->issueDate('2026-10-03')
        ->locale('de')
        ->seller(fn ($s) => $s->name('Muster GmbH')->vatId('DE123456789')->address('Hauptstraße 1', '12345', 'Musterstadt', 'DE'))
        ->buyer(fn ($b) => $b->name('Kunde AG')->address('Bahnhofstraße 5', '54321', 'Beispielstadt', 'DE'))
        ->openItem('RE-1', '2026-08-01', '1190.00', '2026-08-15')
        ->openItem('RE-2', '2026-08-05', '595.00', '2026-08-19');
}

it('adds the flat rate for recovery costs once per claim (Article 6 Directive 2011/7/EU)', function () {
    $reminder = flatRateReminder()->flatRate('RE-1')->flatRate('RE-2')->fee('Mahngebühr', '5.00')->build();
    $fees = $reminder->dunning?->fees ?? [];

    expect($fees)->toHaveCount(3)
        ->and($fees[0]->kind)->toBe(DunningFeeKind::FlatRate)
        ->and($fees[0]->claim)->toBe('RE-1')
        ->and($fees[0]->amount->getAmount()->toString())->toBe('40.00')
        ->and($fees[0]->description)->toBe('Pauschale für Beitreibungskosten (§ 288 Abs. 5 BGB), Forderung RE-1')
        ->and($fees[2]->kind)->toBe(DunningFeeKind::Fee)
        ->and($fees[2]->claim)->toBeNull()
        ->and($reminder->dunning?->feeTotal('EUR')->getAmount()->toString())->toBe('85.00')
        ->and(DocumentData::fromJson($reminder->toJson())->dunning?->fees[0]->kind)->toBe(DunningFeeKind::FlatRate);
});

it('rejects a second flat rate for the same claim', function () {
    expect(fn () => flatRateReminder()->flatRate('RE-1')->flatRate('RE-1')->build())
        ->toThrow(InvalidArgumentException::class, 'owed once per late payment');
});

it('rejects the flat rate for consumers', function () {
    expect(fn () => flatRateReminder()->buyer(fn ($b) => $b->consumer())->flatRate('RE-1')->build())
        ->toThrow(InvalidArgumentException::class, 'applies only to businesses');
});

it('notes that reminder fees count towards the flat rate (section 288 (5) sentence 3 BGB)', function () {
    $combined = flatRateReminder()->flatRate('RE-1')->fee('Mahngebühr', '5.00')->build();
    $notices = $combined->notices('de');

    expect($notices)->toHaveCount(1)
        ->and($notices[0]->code)->toBe('QUITTIO-DUNNING-FLAT-RATE-FEES')
        ->and($notices[0]->severity->value)->toBe('warning')
        ->and($notices[0]->message)->toContain("Gebühren von 5,00\u{00A0}€ neben der Pauschale für Beitreibungskosten von 40,00\u{00A0}€")
        ->and($notices[0]->message)->toContain('§ 288 Abs. 5 Satz 3 BGB')
        ->and($combined->notices('en')[0]->message)->toStartWith('The reminder claims fees of')
        ->and(flatRateReminder()->flatRate('RE-1')->build()->notices())->toBe([])
        ->and(flatRateReminder()->fee('Mahngebühr', '5.00')->build()->notices())->toBe([]);
});
