<?php

declare(strict_types=1);

namespace Quittio\Country\DE\Builder;

use Quittio\Core\Builder\InvoiceBuilder;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\NoteSubjectCode;
use Quittio\Country\DE\Support\Flags;
use Quittio\Country\DE\Texts\LegalTexts;

/**
 * Builder helpers for German special cases (specification 8.3):
 *
 *     InvoiceBuilder::make()->smallAmount()->...
 *     InvoiceBuilder::make()->propertyRelatedService()->...
 *     InvoiceBuilder::make()->selfBilled()->...
 */
final class GermanInvoiceMacros
{
    public static function register(): void
    {
        if (InvoiceBuilder::hasMacro('smallAmount')) {
            return;
        }

        InvoiceBuilder::macro('smallAmount', function (): InvoiceBuilder {
            return $this->flag(Flags::SMALL_AMOUNT);
        });

        InvoiceBuilder::macro('propertyRelatedService', function (string $locale = 'de'): InvoiceBuilder {
            return $this->flag(Flags::PROPERTY_RELATED_SERVICE)
                ->note((string) LegalTexts::catalog()->get(LegalTexts::PROPERTY_RETENTION, $locale), NoteSubjectCode::RegulatoryInformation);
        });

        InvoiceBuilder::macro('newVehicle', function (): InvoiceBuilder {
            return $this->flag(Flags::NEW_VEHICLE);
        });

        InvoiceBuilder::macro('selfBilled', function (string $locale = 'de'): InvoiceBuilder {
            return $this->type(InvoiceTypeCode::SelfBilledInvoice)
                ->note((string) LegalTexts::catalog()->get(LegalTexts::SELF_BILLING, $locale), NoteSubjectCode::RegulatoryInformation);
        });

        InvoiceBuilder::macro('consumerDefaultNotice', function (string $locale = 'de'): InvoiceBuilder {
            return $this->note((string) LegalTexts::catalog()->get(LegalTexts::CONSUMER_DEFAULT, $locale), NoteSubjectCode::PaymentInformation);
        });
    }
}
