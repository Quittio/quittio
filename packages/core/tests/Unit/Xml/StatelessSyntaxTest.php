<?php

declare(strict_types=1);

use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Reading\FormatReader;
use Quittio\Core\Reading\InvoiceReader;
use Quittio\Core\Standards\StandardVersion;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Xml\Cii\CiiWriter;
use Quittio\Core\Xml\Ubl\UblWriter;
use Quittio\Core\Xml\XmlSerializer;

it('writes reentrantly: a nested call does not change the outer document', function (XmlSerializer $writer) {
    $outer = (new En16931Calculator)->calculate(Scenarios::make('simple')->build());
    $inner = (new En16931Calculator)->calculate(Scenarios::make('mixed-vat')->number('INNER-1')->build());
    $profile = ProfileRegistry::withDefaults()->get('en16931');
    $expected = $writer->serialize($outer, $profile);

    // A profile that serialises another invoice with the same writer while the outer one is written.
    $nesting = new class($writer, $inner, $profile) extends Quittio\Core\Profiles\AbstractProfile
    {
        public function __construct(private readonly XmlSerializer $writer, private readonly Quittio\Core\Data\InvoiceData $inner, private readonly Quittio\Core\Profiles\Profile $profile) {}

        public function key(): string
        {
            return $this->profile->key();
        }

        public function name(): string
        {
            return $this->profile->name();
        }

        public function level(): Quittio\Core\Profiles\ProfileLevel
        {
            return $this->profile->level();
        }

        public function specificationId(StandardVersion $version = StandardVersion::En16931_2017): string
        {
            $this->writer->serialize($this->inner, $this->profile);

            return $this->profile->specificationId($version);
        }

        public function xmpConformanceLevel(): ?string
        {
            return $this->profile->xmpConformanceLevel();
        }

        public function syntaxes(): array
        {
            return $this->profile->syntaxes();
        }
    };

    expect($writer->serialize($outer, $nesting))->toBe($expected);
})->with([
    'CII' => fn () => new CiiWriter,
    'UBL' => fn () => new UblWriter,
]);

it('keeps no mutable state in the serializers and readers of the core', function () {
    $classes = [CiiWriter::class, UblWriter::class, ...array_map(fn (FormatReader $reader) => $reader::class, InvoiceReader::defaultReaders())];
    $mutable = [];
    foreach ($classes as $class) {
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            if (! $property->isStatic() && ! $property->isReadOnly()) {
                $mutable[] = $class.'::$'.$property->getName();
            }
        }
    }

    expect($mutable)->toBe([]);
});
