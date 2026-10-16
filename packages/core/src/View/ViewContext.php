<?php

declare(strict_types=1);

namespace Quittio\Core\View;

use Quittio\Core\Countries\CountryPack;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\I18n\Translations;
use Quittio\Core\I18n\Translator;
use Quittio\Core\Pdf\Qr\PaymentQrGenerator;
use Quittio\Core\Profiles\Profile;

/**
 * Everything the view model factory needs besides the invoice data.
 */
final readonly class ViewContext
{
    /**
     * @param  array<string, mixed>  $options  e.g. girocode_amount (due|discounted), watermark
     */
    public function __construct(
        /** null = the invoice's locale (meta), then English */
        public ?string $locale = null,
        public ?CountryPack $country = null,
        public ?Profile $profile = null,
        public Formatter $formatter = new IntlFormatter,
        public ?Translator $translator = null,
        public ?PaymentQrGenerator $paymentQr = null,
        public ?Presentation $defaults = null,
        public array $options = [],
        /** The logo of the presentation, resolved by the render run; null = the factory resolves it */
        public ?ResolvedLogo $logo = null,
    ) {}

    public function translator(): Translator
    {
        return $this->translator ?? Translations::translator();
    }
}
