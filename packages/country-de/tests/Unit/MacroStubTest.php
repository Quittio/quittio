<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Exceptions\InvalidArgumentException;
use Quittio\Core\Testing\Scenarios;
use Quittio\Country\DE\Builder\GermanInvoice;
use Quittio\Country\DE\Builder\GermanInvoiceMacros;
use Quittio\Country\DE\Support\Flags;

it('describes every builder macro of the pack in the IDE stub', function () {
    GermanInvoiceMacros::register();
    $stub = (string) file_get_contents(dirname(__DIR__, 2).'/stubs/InvoiceBuilder.stub.php');
    preg_match_all('/public function (\w+)\(/', $stub, $documented);
    preg_match_all("/InvoiceBuilder::macro\\('(\\w+)'/", (string) file_get_contents((new ReflectionClass(GermanInvoiceMacros::class))->getFileName() ?: ''), $registered);

    expect($documented[1])->toBe($registered[1]);
    foreach ($registered[1] as $macro) {
        expect(InvoiceBuilder::hasMacro($macro))->toBeTrue();
    }
});

it('offers the German special cases behind de() only', function () {
    GermanInvoiceMacros::register();
    $builder = Scenarios::simple();

    expect($builder->de())->toBeInstanceOf(GermanInvoice::class)
        ->and($builder->de()->smallAmount())->toBe($builder)
        ->and($builder->build()->meta->hasFlag(Flags::SMALL_AMOUNT))->toBeTrue()
        ->and(Scenarios::simple()->de()->selfBilled()->build()->typeCode)->toBe(InvoiceTypeCode::SelfBilledInvoice)
        ->and(InvoiceBuilder::hasMacro('selfBilled'))->toBeFalse();
});

it('deducts an advance invoice also on subclasses of the builder', function () {
    GermanInvoiceMacros::register();
    $advance = (new En16931Calculator)->calculate(Scenarios::simple()->number('AB-1')->build());
    $subclass = new class extends InvoiceBuilder {};
    $final = $subclass::make()->currency('USD');

    expect(fn () => $final->de()->deductAdvance($advance))->toThrow(InvalidArgumentException::class, 'is in EUR, the final invoice in USD');
});
