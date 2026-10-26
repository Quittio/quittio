<?php

declare(strict_types=1);

namespace Quittio\Laravel\Facades;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Testing\QuittioFake;

/**
 * @method static \Quittio\Laravel\Rendering\PendingInvoice make(\Quittio\Core\Data\InvoiceData|\Quittio\Core\Contracts\ProvidesInvoiceData|\Quittio\Core\Builder\InvoiceBuilder $data)
 * @method static \Quittio\Laravel\Builder\RenderableInvoiceBuilder invoice() a new invoice with the seller of config/quittio.php (or the current issuer of quittio/managed)
 * @method static \Quittio\Laravel\Builder\RenderableInvoiceBuilder edit(\Quittio\Core\Data\InvoiceData $data) continues existing invoice data with the configured calculator
 * @method static \Quittio\Laravel\Builder\RenderableDocumentBuilder quote()
 * @method static \Quittio\Laravel\Builder\RenderableDocumentBuilder orderConfirmation()
 * @method static \Quittio\Laravel\Builder\RenderableDocumentBuilder deliveryNote()
 * @method static \Quittio\Laravel\Builder\RenderableDocumentBuilder proforma()
 * @method static \Quittio\Laravel\Builder\RenderableDocumentBuilder paymentReminder(int $level = 0)
 * @method static \Quittio\Laravel\Builder\RenderableDocumentBuilder accountStatement()
 * @method static \Quittio\Laravel\Builder\RenderableDocumentBuilder bonusStatement()
 * @method static \Quittio\Laravel\Rendering\PendingDocument document(\Quittio\Core\Documents\DocumentData|\Quittio\Core\Documents\DocumentBuilder $document)
 * @method static \Quittio\Core\Builder\InvoiceBuilder cancellation(\Quittio\Core\Data\InvoiceData $original, string $number, ?string $reason = null, ?string $strategy = null, ?\Quittio\Core\Support\Date $issueDate = null, ?list<array{line: string, quantity?: \Brick\Math\BigDecimal|int|string, amount?: string}> $lines = null, ?\Quittio\Core\Corrections\CancellationHistory $history = null, ?string $title = null)
 * @method static \Quittio\Core\Builder\InvoiceBuilder correction(\Quittio\Core\Data\InvoiceData $original, string $number, ?string $reason = null, ?\Quittio\Core\Support\Date $issueDate = null, ?string $title = null)
 * @method static \Quittio\Core\Builder\InvoiceBuilder priceReduction(\Quittio\Core\Data\InvoiceData $original, string $number, \Quittio\Core\Corrections\PriceReductionReason $reason, \Brick\Math\BigDecimal|int|string|null $percent = null, \Brick\Math\BigDecimal|int|string|null $amount = null, ?array<string, \Brick\Math\BigDecimal|int|string> $amounts = null, ?\Quittio\Core\Support\Date $issueDate = null, ?string $title = null, ?\Quittio\Core\Corrections\CancellationHistory $history = null)
 * @method static \Quittio\Laravel\Rendering\RenderedInvoice renderInvoice(\Quittio\Core\Data\InvoiceData $invoice, \Quittio\Core\Rendering\RenderOptions $options) the render run behind PendingInvoice::render(), with events
 * @method static \Quittio\Laravel\Rendering\RenderedDocument renderDocument(\Quittio\Core\Documents\DocumentData $document, \Quittio\Core\Rendering\RenderOptions $options) the render run behind PendingDocument::render()
 * @method static \Quittio\Laravel\Jobs\RenderInvoice queueInvoice(\Quittio\Laravel\Jobs\RenderInvoice $job) dispatches the job of PendingInvoice::queue()->save()
 * @method static \Quittio\Laravel\Jobs\RenderDocument queueDocument(\Quittio\Laravel\Jobs\RenderDocument $job)
 * @method static \Quittio\Core\Validation\ValidationResult check(\Quittio\Core\Data\InvoiceData $invoice, \Quittio\Core\Rendering\RenderOptions $options)
 * @method static \Quittio\Core\Rendering\PreparedInvoice prepare(\Quittio\Core\Data\InvoiceData $invoice, \Quittio\Core\Rendering\RenderOptions $options)
 * @method static \Quittio\Core\Reading\ReadResult read(string $content) content (bytes) of an XML or PDF e-invoice, never a path
 * @method static \Quittio\Core\Reading\ReadResult readFile(string $path) path of an XML or PDF e-invoice
 * @method static \Quittio\Core\Validation\ValidationResult validateFile(string $content) content (bytes) of an XML or PDF e-invoice, never a path
 * @method static \Quittio\Core\Validation\ValidationResult validatePath(string $path) path of an XML or PDF e-invoice
 * @method static QuittioManager extend(string $type, string $name, \Closure $factory) type renderer, validator, serializer or format_resolver; the factory receives the container and the driver configuration
 * @method static QuittioManager registerCountry(\Quittio\Core\Countries\CountryPack $pack)
 * @method static \Quittio\Core\Tax\TaxPreset|null defaultVat(string|null $sellerCountry, bool $smallBusiness)
 * @method static \Quittio\Core\Countries\CountryRegistry countries()
 * @method static \Quittio\Core\Profiles\ProfileRegistry profiles()
 * @method static \Quittio\Core\Formats\FormatRegistry formats()
 * @method static \Quittio\Laravel\Support\Settings settings()
 * @method static QuittioManager flush() forgets the built core, e.g. after configuration changes
 * @method static \Quittio\Core\Quittio core()
 * @method static \Quittio\Core\Quittio coreWithLevels(list<string> $levels) a new core with the configured validation levels plus $levels (e.g. kosit, verapdf)
 * @method static void macro(string $name, object|callable $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 * @method static void assertRendered(?callable $callback = null)
 * @method static void assertRenderedCount(int $count)
 * @method static void assertNothingRendered()
 * @method static void assertQueued(?callable $callback = null)
 * @method static void assertNothingQueued()
 * @method static void assertDocumentQueued(?callable $callback = null)
 * @method static void assertDocumentRendered(?callable $callback = null)
 * @method static list<\Quittio\Laravel\Rendering\RenderedInvoice> rendered()
 * @method static list<\Quittio\Laravel\Rendering\RenderedDocument> documents()
 * @method static list<\Quittio\Laravel\Jobs\RenderInvoice> queued()
 * @method static list<\Quittio\Laravel\Jobs\RenderDocument> queuedDocuments()
 *
 * @see QuittioManager
 * @see QuittioFake
 */
final class Quittio extends Facade
{
    /**
     * Replaces rendering with a fast fake that records every call.
     */
    public static function fake(): QuittioFake
    {
        $fake = new QuittioFake(Container::getInstance());
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return QuittioManager::class;
    }
}
