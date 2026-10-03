<?php

declare(strict_types=1);

namespace Quittio\Core\Data\Presentation;

use Quittio\Core\Data\Concerns\Immutable;
use Quittio\Core\Mapping\Attributes as Q;

/**
 * Data used only for the PDF. Never written to XML.
 */
final readonly class Presentation
{
    use Immutable;

    public const string PAYMENT_QR_AUTO = 'auto';

    public const string PAYMENT_QR_GIROCODE = 'girocode';

    public const string PAYMENT_QR_SWISS = 'swiss-qr-bill';

    public const string PAYMENT_QR_NONE = 'none';

    /**
     * @param  list<list<string>>  $footerColumns
     * @param  list<LineGroup>  $lineGroups
     * @param  array<string, string>  $labels
     */
    public function __construct(
        #[Q\PdfOnly]
        public ?string $template = null,
        #[Q\PdfOnly]
        public ?string $logo = null,
        #[Q\PdfOnly]
        public ?Colors $colors = null,
        #[Q\PdfOnly]
        public ?string $font = null,
        #[Q\PdfOnly]
        public ?string $senderLine = null,
        #[Q\PdfOnly]
        public ?string $letterLayout = null,
        #[Q\PdfOnly]
        public ?string $salutation = null,
        #[Q\PdfOnly]
        public ?string $introText = null,
        #[Q\PdfOnly]
        public ?string $closingText = null,
        #[Q\PdfOnly]
        public array $footerColumns = [],
        #[Q\PdfOnly, Q\ListOf(LineGroup::class)]
        public array $lineGroups = [],
        #[Q\PdfOnly]
        public bool $showGrossPrices = false,
        #[Q\PdfOnly]
        public bool $showItemNumbers = false,
        #[Q\PdfOnly]
        public string $paymentQr = self::PAYMENT_QR_AUTO,
        #[Q\PdfOnly]
        public ?string $watermark = null,
        #[Q\PdfOnly]
        public string $paper = 'A4',
        #[Q\PdfOnly]
        public array $labels = [],
        #[Q\PdfOnly]
        public ?string $title = null,
    ) {}
}
