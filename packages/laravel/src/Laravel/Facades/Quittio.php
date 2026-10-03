<?php

declare(strict_types=1);

namespace Quittio\Laravel\Facades;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Quittio\Laravel\QuittioManager;
use Quittio\Laravel\Testing\QuittioFake;

/**
 * @method static \Quittio\Laravel\Rendering\PendingInvoice make(\Quittio\Core\Data\InvoiceData|\Quittio\Core\Contracts\ProvidesInvoiceData|\Quittio\Core\Builder\InvoiceBuilder $data)
 * @method static \Quittio\Laravel\Rendering\PendingDocument document(\Quittio\Core\Documents\DocumentData|\Quittio\Core\Documents\DocumentBuilder $document)
 * @method static \Quittio\Core\Builder\InvoiceBuilder cancellation(\Quittio\Core\Data\InvoiceData $original, string $number, ?string $reason = null, ?string $strategy = null)
 * @method static \Quittio\Core\Reading\ReadResult read(string $pathOrContent)
 * @method static \Quittio\Core\Validation\ValidationResult validateFile(string $pathOrContent)
 * @method static QuittioManager extend(string $type, string $name, \Closure $factory)
 * @method static QuittioManager registerCountry(\Quittio\Core\Countries\CountryPack $pack)
 * @method static \Quittio\Core\Countries\CountryRegistry countries()
 * @method static \Quittio\Core\Profiles\ProfileRegistry profiles()
 * @method static \Quittio\Core\Formats\FormatRegistry formats()
 * @method static \Quittio\Core\Quittio core()
 * @method static void assertRendered(?callable $callback = null)
 * @method static void assertRenderedCount(int $count)
 * @method static void assertNothingRendered()
 *
 * @see QuittioManager
 */
final class Quittio extends Facade
{
    /**
     * Replaces rendering with a fast fake that records every call (specification 14.7).
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
