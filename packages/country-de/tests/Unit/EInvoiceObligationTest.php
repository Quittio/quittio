<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\UnitCode;
use Quittio\Core\Quittio;
use Quittio\Core\Tax\TaxPreset;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Country\DE\CountryDE;
use Quittio\Country\DE\Rules\BmfErrorClass;
use Quittio\Country\DE\Tax\VatPreset;

function obligationQuittio(bool $above800k = false): Quittio
{
    return Quittio::create(['countries' => ['de' => ['prior_year_turnover_above_800k' => $above800k]]], [new CountryDE]);
}

function supply(string $delivered, string $issued, string $net = '1000.00', ?TaxPreset $vat = null): InvoiceBuilder
{
    return Scenarios::base()->deliveryDate($delivered)->issueDate($issued)
        ->line(fn ($l) => $l->name('Beratung')->quantity(1, UnitCode::Piece)->netPrice($net)->vat($vat ?? VatPreset::Standard));
}

/**
 * @param  list<string>|string  $formats
 * @return array<string, ValidationMessage>
 */
function obligationFindings(InvoiceBuilder $builder, array|string $formats = 'pdf', ?Quittio $quittio = null, ?string $profile = null): array
{
    $pending = ($quittio ?? obligationQuittio())->make($builder)->formats((array) $formats);
    $findings = [];
    foreach (($profile === null ? $pending : $pending->profile($profile))->check()->messages as $message) {
        if (str_starts_with($message->code, 'DE-EINVOICE-')) {
            $findings[$message->code] = $message;
        }
    }

    return $findings;
}

it('allows another invoice for supplies of 2025 and 2026 until the end of 2026, with the consent of the buyer', function () {
    $transition = obligationFindings(supply('2026-09-30', '2026-10-03'));

    expect(array_keys($transition))->toBe(['DE-EINVOICE-02'])
        ->and($transition['DE-EINVOICE-02']->severity->value)->toBe('warning')
        ->and($transition['DE-EINVOICE-02']->message)->toContain('2026-12-31')
        ->and(array_keys(obligationFindings(supply('2026-12-15', '2027-01-05'))))->toBe(['DE-EINVOICE-01'])
        ->and(obligationFindings(supply('2024-12-15', '2025-01-05')))->toBe([]);
});

it('requires an e-invoice for 2027 above 800,000 EUR prior-year turnover and for everybody from 2028', function () {
    expect(array_keys(obligationFindings(supply('2027-03-31', '2027-04-02'))))->toBe(['DE-EINVOICE-02'])
        ->and(obligationFindings(supply('2027-03-31', '2027-04-02'))['DE-EINVOICE-02']->message)->toContain('2027-12-31')
        ->and(array_keys(obligationFindings(supply('2027-03-31', '2027-04-02'), quittio: obligationQuittio(above800k: true))))->toBe(['DE-EINVOICE-01'])
        ->and(array_keys(obligationFindings(supply('2027-12-20', '2028-01-10'))))->toBe(['DE-EINVOICE-01'])
        ->and(array_keys(obligationFindings(supply('2028-02-29', '2028-03-01'))))->toBe(['DE-EINVOICE-01'])
        ->and(BmfErrorClass::of(obligationFindings(supply('2028-02-29', '2028-03-01'))['DE-EINVOICE-01']))->toBe(BmfErrorClass::Format);
});

it('accepts e-invoices and runs that produce one', function () {
    expect(obligationFindings(supply('2028-02-29', '2028-03-01'), 'zugferd'))->toBe([])
        ->and(obligationFindings(supply('2028-02-29', '2028-03-01'), ['xrechnung-cii', 'pdf']))->toBe([])
        // MINIMUM is no e-invoice according to EN 16931.
        ->and(array_keys(obligationFindings(supply('2028-02-29', '2028-03-01'), 'zugferd', profile: 'minimum')))->toBe(['DE-EINVOICE-01']);
});

it('does not apply to the exceptions of the e-invoice obligation', function (Closure $build) {
    expect(obligationFindings($build()))->toBe([]);
})->with([
    'consumer' => [fn () => supply('2028-02-29', '2028-03-01')->buyer(fn ($b) => $b->name('Erika Mustermann')->vatId(null)->consumer())],
    'buyer abroad' => [fn () => supply('2028-02-29', '2028-03-01')->buyer(fn ($b) => $b->name('Client SARL')->vatId('FR12345678901')->address('1 Rue', '75002', 'Paris', 'FR'))],
    'small amount up to 250 EUR' => [fn () => supply('2028-02-29', '2028-03-01', '210.00')],
    'small business' => [fn () => supply('2028-02-29', '2028-03-01', vat: VatPreset::SmallBusiness)->flag('small_business')],
    'ticket' => [fn () => supply('2028-02-29', '2028-03-01')->de()->ticket()],
    'exempt under section 4 no. 14 UStG' => [fn () => supply('2028-02-29', '2028-03-01', vat: VatPreset::exempt('§ 4 Nr. 14 UStG'))],
    'cancellation of an invoice' => [fn () => supply('2028-02-29', '2028-03-01')->type('381')->precedingInvoice('RE-1', '2028-03-01')],
]);

it('takes the issue date of prepayment invoices (BMF letter of 15 October 2026, marginal no. 2)', function () {
    // A prepayment invoice of 2026 for a supply of 2028 falls under the transition, not the obligation.
    expect(array_keys(obligationFindings(supply('2028-02-29', '2026-11-02')->type('386'))))->toBe(['DE-EINVOICE-02'])
        ->and(array_keys(obligationFindings(supply('2026-09-30', '2028-01-10')->type('386'))))->toBe(['DE-EINVOICE-01']);
});
