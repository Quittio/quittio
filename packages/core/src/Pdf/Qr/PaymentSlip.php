<?php

declare(strict_types=1);

namespace Quittio\Core\Pdf\Qr;

/**
 * The texts of a payment slip with receipt (ProvidesPaymentSlip), laid out by the templates after
 * the Swiss QR bill: receipt (62 mm) and payment part (148 mm) in a strip of 210 × 105 mm at the
 * bottom of the page, with the code at 46 × 46 mm.
 *
 * A section is a heading with its lines; a section without lines is printed as an empty field to
 * be filled in by hand, as is a missing amount.
 */
final readonly class PaymentSlip
{
    /**
     * @param  list<array{heading: string, lines: list<string>}>  $sections  information section of the payment part, in order
     * @param  list<array{heading: string, lines: list<string>}>  $receiptSections  information section of the receipt
     * @param  string|null  $amount  formatted amount, null for an empty amount field
     * @param  string|null  $mark  image (data URI) printed in the middle of the code, e.g. the Swiss cross
     */
    public function __construct(
        public string $title,
        public string $receiptTitle,
        public array $sections,
        public array $receiptSections,
        public string $currencyHeading,
        public string $amountHeading,
        public string $currency,
        public ?string $amount,
        public string $acceptancePoint,
        public string $separationNote,
        public ?string $mark = null,
    ) {}

    /**
     * @return array{title: string, receiptTitle: string, sections: list<array{heading: string, lines: list<string>}>, receiptSections: list<array{heading: string, lines: list<string>}>, currencyHeading: string, amountHeading: string, currency: string, amount: ?string, acceptancePoint: string, separationNote: string, mark: ?string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'receiptTitle' => $this->receiptTitle,
            'sections' => $this->sections,
            'receiptSections' => $this->receiptSections,
            'currencyHeading' => $this->currencyHeading,
            'amountHeading' => $this->amountHeading,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'acceptancePoint' => $this->acceptancePoint,
            'separationNote' => $this->separationNote,
            'mark' => $this->mark,
        ];
    }
}
