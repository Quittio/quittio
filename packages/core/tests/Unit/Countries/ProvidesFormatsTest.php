<?php

declare(strict_types=1);

use Quittio\Core\Countries\AbstractCountryPack;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Formats\OutputFormat;
use Quittio\Core\Profiles\Profile;
use Quittio\Core\Profiles\Syntax;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Testing\Scenarios;
use Quittio\Core\Xml\SerializationOptions;
use Quittio\Core\Xml\XmlSerializer;

it('registers the formats, aliases and serializers of a country pack', function () {
    $serializer = new class implements XmlSerializer
    {
        public function syntax(): Syntax
        {
            return Syntax::Ubl;
        }

        public function serialize(InvoiceData $invoice, Profile $profile, SerializationOptions $options = new SerializationOptions): string
        {
            return '<national>'.$invoice->number.'</national>';
        }
    };
    $pack = new class($serializer) extends AbstractCountryPack
    {
        public function __construct(private readonly XmlSerializer $serializer) {}

        public function code(): string
        {
            return 'XX';
        }

        public function formats(): array
        {
            return [new OutputFormat('xx-national', Syntax::Ubl, defaultProfile: 'en16931')];
        }

        public function formatAliases(): array
        {
            return ['national' => 'xx-national'];
        }

        public function serializers(): array
        {
            return [$this->serializer];
        }
    };
    $quittio = Quittio::create([], [$pack]);
    $rendered = $quittio->render(Scenarios::simple()->build(), new RenderOptions(formats: ['national'], validation: 'off'));

    expect($quittio->formats()->has('xx-national'))->toBeTrue()
        ->and($quittio->formats()->get('national')->key)->toBe('xx-national')
        ->and($rendered->format())->toBe('xx-national')
        ->and($rendered->xml())->toBe('<national>RE-2026-0042</national>');
});

it('knows the XRechnung formats only with the German pack', function () {
    $formats = Quittio::create()->formats();

    expect($formats->has('xrechnung-cii'))->toBeFalse()
        ->and($formats->has('xrechnung'))->toBeFalse();
});
