<?php

declare(strict_types=1);

use Brick\Math\BigDecimal;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Codes\RawCode;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Serialization\ArrayNormalizer;
use Quittio\Core\Serialization\JsonSchemaGenerator;
use Quittio\Core\Serialization\JsonSchemaValidator;
use Quittio\Core\Support\Date;
use Quittio\Core\Testing\Scenarios;

it('normalises values to strings without losing precision', function () {
    $normalizer = new ArrayNormalizer;

    expect($normalizer->value(BigDecimal::of('12.3450')))->toBe('12.3450')
        ->and($normalizer->value(Date::parse('2026-02-28')))->toBe('2026-02-28')
        ->and($normalizer->value(VatCategory::ReverseCharge))->toBe('AE')
        ->and($normalizer->value(new RawCode('ZZZ')))->toBe('ZZZ')
        ->and($normalizer->value([BigDecimal::of('1'), null, true]))->toBe(['1', null, true]);
});

it('keeps amounts in the currency they belong to', function () {
    $invoice = (new En16931Calculator)->calculate(Scenarios::make('foreign-currency')->build());
    $restored = InvoiceData::fromArray($invoice->toArray());

    expect($invoice->toArray()['totals']['vatTotalInTaxCurrency'])->toBe('345.82')
        ->and($restored->totals?->grossTotal->getCurrency()->getCurrencyCode())->toBe('USD')
        ->and($restored->totals?->vatTotalInTaxCurrency?->getCurrency()->getCurrencyCode())->toBe('EUR')
        ->and($restored->totals?->vatTotalInTaxCurrency?->getAmount()->toString())->toBe('345.82');
});

it('stores attachment contents as base64 and restores every byte', function () {
    $bytes = implode('', array_map(chr(...), range(0, 255)));
    $invoice = Scenarios::simple()->attachment('BIN-1', $bytes, 'application/pdf', 'scan.pdf')->build();

    $array = $invoice->toArray();
    $restored = InvoiceData::fromJson($invoice->toJson());

    expect($array['attachments'][0]['content'])->toBe(base64_encode($bytes))
        ->and($restored->attachments[0]->content)->toBe($bytes)
        ->and($restored->attachments[0]->size())->toBe(256);
});

it('omits values equal to their default and restores them', function () {
    $invoice = Scenarios::simple()->build();
    $array = $invoice->toArray();

    expect($array)->not->toHaveKey('presentation')
        ->and($array)->not->toHaveKey('allowances')
        ->and(InvoiceData::fromArray($array)->toArray())->toBe($array);
});

it('produces JSON that matches the generated schema for every scenario', function (string $scenario) {
    $json = (new En16931Calculator)->calculate(Scenarios::make($scenario)->build())->toJson();

    expect((new JsonSchemaValidator)->validate($json))->toBe([]);
})->with(Scenarios::names())->skip(fn () => ! JsonSchemaValidator::available() || ! is_file(JsonSchemaGenerator::defaultPath()), 'opis/json-schema or the schema file is missing.');

it('names the path of JSON that does not match the schema', function () {
    $data = Scenarios::simple()->build()->toArray();
    $data['lines'][0]['quantity'] = 3;

    expect(implode("\n", (new JsonSchemaValidator)->validate((string) json_encode($data))))->toContain('/lines/0/quantity');
})->skip(fn () => ! JsonSchemaValidator::available() || ! is_file(JsonSchemaGenerator::defaultPath()), 'opis/json-schema or the schema file is missing.');
