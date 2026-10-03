<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Mapping\FieldReference;
use Quittio\Core\Serialization\JsonSchemaGenerator;
use Quittio\Core\Serialization\JsonSchemaValidator;
use Quittio\Core\Testing\Scenarios;

it('ships an up-to-date JSON schema', function () {
    expect(file_get_contents(JsonSchemaGenerator::defaultPath()))->toBe((new JsonSchemaGenerator)->toJson());
})->skip(fn () => ! is_file(JsonSchemaGenerator::defaultPath()), 'Run php tools/docs/generate.php.');

it('ships an up-to-date field reference', function () {
    $file = dirname(__DIR__, 5).'/docs/field-reference.md';

    expect(file_get_contents($file))->toBe((new FieldReference)->markdown());
})->skip(fn () => ! is_file(dirname(__DIR__, 5).'/docs/field-reference.md'), 'The field reference is generated in the repository only.');

it('describes every scenario', function (string $scenario) {
    $invoice = Scenarios::make($scenario)->build();

    expect((new JsonSchemaValidator)->validate($invoice->toJson()))->toBe([])
        ->and((new JsonSchemaValidator)->validate((new En16931Calculator)->calculate($invoice)->toJson()))->toBe([]);
})->with(Scenarios::names())->skip(! JsonSchemaValidator::available(), 'opis/json-schema is not installed.');

it('reports schema violations', function () {
    $data = InvoiceData::factory()->make()->toArray();
    $data['issueDate'] = 'yesterday';
    $data['unknown'] = true;
    unset($data['number']);

    $errors = (new JsonSchemaValidator)->validate((string) json_encode($data));

    expect($errors)->not->toBe([])
        ->and(implode("\n", $errors))->toContain('number');
})->skip(! JsonSchemaValidator::available(), 'opis/json-schema is not installed.');

it('documents business terms in the field reference', function () {
    $markdown = (new FieldReference)->markdown();

    expect($markdown)->toContain('| `number` | BT-1 | 1 | MINIMUM |')
        ->and($markdown)->toContain('`lines[].item.name`')
        ->and($markdown)->toContain('PDF only');
});
