<?php

declare(strict_types=1);

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Country\DE\Builder\GermanInvoiceMacros;

it('describes every builder macro of the pack in the IDE stub', function () {
    GermanInvoiceMacros::register();
    $stub = (string) file_get_contents(dirname(__DIR__, 2).'/stubs/InvoiceBuilder.stub.php');
    preg_match_all('/@method static (\w+)\(/', $stub, $documented);
    preg_match_all("/InvoiceBuilder::macro\\('(\\w+)'/", (string) file_get_contents((new ReflectionClass(GermanInvoiceMacros::class))->getFileName() ?: ''), $registered);

    expect($documented[1])->toBe($registered[1]);
    foreach ($registered[1] as $macro) {
        expect(InvoiceBuilder::hasMacro($macro))->toBeTrue();
    }
});
