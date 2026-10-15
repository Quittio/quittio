<?php

declare(strict_types=1);

use Quittio\Core\Builder\Party;
use Quittio\Laravel\Facades\Quittio;

it('writes validation messages in the language of the application', function () {
    $invoice = Quittio::invoice()->number('RE-1')->deliveryDate('2026-10-14')
        ->buyer(Party::make('Kunde AG')->address(null, null, 'Berlin', 'DE'))
        ->line('Consulting', netPrice: '80.00');

    $english = Quittio::make($invoice)->format('cii')->check()->errors()[0]->message;
    app()->setLocale('de');
    $german = Quittio::make($invoice)->format('cii')->check()->errors()[0]->message;

    expect($english)->toStartWith('The address of the buyer (BG-8) is incomplete')
        ->and($german)->toStartWith('Die Anschrift des Leistungsempfängers');
});

it('writes validation messages in the language of the invoice or a configured one', function (string $configured, string $expected) {
    config()->set('quittio.validation.locale', $configured);
    Quittio::flush();

    $result = Quittio::invoice()->number('RE-1')->deliveryDate('2026-10-14')
        ->buyer(Party::make('Kunde AG')->address(null, null, 'Berlin', 'DE'))
        ->line('Consulting', netPrice: '80.00')
        ->check();

    expect($result->errors()[0]->message)->toStartWith($expected);
})->with([
    'invoice' => ['invoice', 'Die Anschrift des Leistungsempfängers'],
    'fixed' => ['de', 'Die Anschrift des Leistungsempfängers'],
]);
