<?php

declare(strict_types=1);

use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentBuilder;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Quittio;
use Quittio\Core\Rendering\RenderOptions;
use Quittio\Core\Templates\TemplateEngine;
use Quittio\Core\Tests\Support\Invoices;
use Quittio\Core\View\InvoiceViewModel;

final class RecordingTemplates implements TemplateEngine
{
    /** @var list<string> */
    public array $rendered = [];

    public function render(string $template, InvoiceViewModel $invoice, array $data = []): string
    {
        $this->rendered[] = $template;

        return '<html><body>'.$invoice->document->number.'</body></html>';
    }
}

/**
 * @param  array<string, mixed>  $config
 */
function renderedTemplate(array $config, ?Presentation $own, ?Presentation $call, ?string $template = null): string
{
    $templates = new RecordingTemplates;
    $invoice = Invoices::simple()->build();
    if ($own !== null) {
        $invoice = $invoice->with(['presentation' => $own]);
    }
    $pending = Quittio::create([...$config, 'mandatory_fields' => 'off'], templates: $templates)->make($invoice)->format('html');
    if ($call !== null) {
        $pending->presentation($call);
    }
    if ($template !== null) {
        $pending->template($template);
    }
    $pending->render();

    return $templates->rendered[0] ?? '';
}

it('applies the configured profile to a requested format', function () {
    $result = Quittio::create(['profile' => 'basic'])->make(Invoices::simple()->build())->format('zugferd')->render();

    expect($result->profile()?->key())->toBe('basic')
        ->and($result->xml())->toContain('urn:factur-x.eu:1p0:basic');
});

it('keeps the profile of the format when the configured one does not fit', function () {
    $invoice = Invoices::simple()
        ->buyerReference('PO-1')
        ->seller(fn ($s) => $s->electronicAddress('DE123456789', '9930'))
        ->buyer(fn ($b) => $b->electronicAddress('4000001000005', '0088'))
        ->build();
    $result = Quittio::create(['profile' => 'basic'])->make($invoice)->format('peppol')->render();

    expect($result->profile()?->key())->toBe('peppol');
});

it('lets a requested profile win over the configured one', function () {
    $result = Quittio::create(['profile' => 'basic'])->make(Invoices::simple()->build())->format('zugferd')->profile('en16931')->render();

    expect($result->profile()?->key())->toBe('en16931');
});

it('upgrades the configured profile when EXTENDED features are used', function () {
    $invoice = Invoices::base()->line(fn ($l) => $l->name('Set')->vat(VatCategory::StandardRate, '19')
        ->subLine(fn ($s) => $s->name('Part')->quantity(2, 'H87')->netPrice('50.00')->vat(VatCategory::StandardRate, '19')))->build();

    $result = Quittio::create(['profile' => 'en16931'])->make($invoice)->format('zugferd')->render();

    expect($result->profile()?->key())->toBe('extended');
});

it('uses the format default without a configured profile', function () {
    expect(Quittio::create()->make(Invoices::simple()->build())->format('zugferd')->render()->profile()?->key())->toBe('en16931')
        ->and(Quittio::create()->config()->profile)->toBeNull();
});

it('takes the template from the presentations in the documented order', function () {
    expect(renderedTemplate([], null, null))->toBe('default')
        ->and(renderedTemplate(['template' => 'configured'], null, null))->toBe('configured')
        ->and(renderedTemplate(['presentation' => new Presentation(template: 'configured-presentation')], null, null))->toBe('configured-presentation')
        ->and(renderedTemplate(['presentation' => new Presentation(template: 'configured-presentation')], null, new Presentation(template: 'call')))->toBe('call')
        ->and(renderedTemplate([], new Presentation(template: 'invoice'), new Presentation(template: 'call')))->toBe('invoice')
        ->and(renderedTemplate([], new Presentation(template: 'invoice'), new Presentation(template: 'call'), 'explicit'))->toBe('explicit');
});

it('takes the template of the presentation for documents too', function () {
    $templates = new RecordingTemplates;
    $quittio = Quittio::create(['presentation' => new Presentation(template: 'letters'), 'mandatory_fields' => 'off'], templates: $templates);
    $document = DocumentBuilder::quote()
        ->number('AN-1')->issueDate('2026-10-03')->currency('EUR')
        ->seller(fn ($s) => $s->name('Seller Ltd')->address('Main Street 1', '12345', 'Sample City', 'DE'))
        ->buyer(fn ($b) => $b->name('Buyer Inc')->address('Station Road 5', '54321', 'Example Town', 'DE'))
        ->line(fn ($l) => $l->name('A')->quantity(1)->netPrice('10.00')->vat(VatCategory::StandardRate, '19'))
        ->build();

    $quittio->renderDocument($document, new RenderOptions(formats: ['html']));

    expect($templates->rendered)->toBe(['letters']);
});
