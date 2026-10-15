<?php

declare(strict_types=1);

use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\Tests\Support\Validate;

it('warns that a UBL credit note without payment instructions loses its due date', function (): void {
    $creditNote = Invoices::simple()->type(InvoiceTypeCode::CreditNote)->build()->with(['paymentInstructions' => null]);

    $result = Validate::data($creditNote, options: ['syntax' => Syntax::Ubl]);
    $messages = array_values(array_filter($result->messages, fn ($m) => $m->code === 'QUITTIO-CREDIT-NOTE-DUE-DATE'));

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->bt)->toBe('BT-9')
        ->and($messages[0]->message)->toContain('2026-10-17');
});

it('does not warn when the due date can be transmitted', function (Closure $adjust, Syntax $syntax): void {
    $result = Validate::data($adjust(Invoices::simple()), options: ['syntax' => $syntax]);

    expect(Validate::codes($result))->not->toContain('QUITTIO-CREDIT-NOTE-DUE-DATE');
})->with([
    'UBL credit note with payment instructions' => [fn ($b) => $b->type(InvoiceTypeCode::CreditNote)->build(), Syntax::Ubl],
    'CII credit note' => [fn ($b) => $b->type(InvoiceTypeCode::CreditNote)->build()->with(['paymentInstructions' => null]), Syntax::Cii],
    'UBL invoice' => [fn ($b) => $b->build()->with(['paymentInstructions' => null]), Syntax::Ubl],
]);
