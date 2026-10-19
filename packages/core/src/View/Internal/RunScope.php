<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal;

use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Quittio\Core\Data\Delivery\Period;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Data\Lines\InvoiceLine;
use Quittio\Core\Data\Presentation\Presentation;
use Quittio\Core\Documents\DocumentData;
use Quittio\Core\Enums\InvoiceTypeCode;
use Quittio\Core\Enums\VatCategory;
use Quittio\Core\Support\Date;
use Quittio\Core\View\FormattedValue;
use Quittio\Core\View\InvoiceTitle;
use Quittio\Core\View\Markdown;
use Quittio\Core\View\ViewContext;
use Quittio\Core\View\ViewModelFactory;

/**
 * What the section factories of one view model build share: the invoice, the context, the
 * merged presentation, the labels and the formatting of values. Created once per build.
 *
 * @internal
 */
final readonly class RunScope
{
    public string $locale;

    /** Self-billed invoice (section 14 (2) sentence 5 UStG): the buyer issues the document. */
    public bool $selfBilled;

    /**
     * @param  array<string, string>  $baseLabels  labels of the locale, without the overrides of the presentation
     * @param  array<string, string>  $labels  labels including the per invoice overrides of the presentation
     */
    private function __construct(
        public InvoiceData $invoice,
        public ViewContext $context,
        public Presentation $presentation,
        public ?DocumentData $document,
        public array $baseLabels,
        public array $labels,
    ) {
        // The locale of the render call wins (Quittio resolves it from the call, the invoice and
        // the configuration), so that PDF and generated XML texts use the same language.
        $this->locale = $context->locale ?? $invoice->meta->locale ?? 'en';
        $this->selfBilled = $invoice->typeCode instanceof InvoiceTypeCode && $invoice->typeCode->isSelfBilled();
    }

    public static function create(InvoiceData $invoice, ViewContext $context, ?DocumentData $document = null): self
    {
        $defaults = $context->defaults ?? new Presentation;
        $own = $invoice->presentation;
        $presentation = $own === null ? $defaults : ViewModelFactory::mergePresentation($own, $defaults);
        $locale = $context->locale ?? $invoice->meta->locale ?? 'en';
        $labels = self::loadLabels($context, $locale);

        return new self($invoice, $context, $presentation, $document, $labels, [...$labels, ...$presentation->labels]);
    }

    /**
     * @return array<string, string>
     */
    private static function loadLabels(ViewContext $context, string $locale): array
    {
        $translator = $context->translator();
        // A regional locale such as "de_CH" takes the labels of its language where it has none of its own.
        $language = strtolower(explode('_', str_replace('-', '_', $locale))[0]);
        $label = static function (string $id) use ($translator, $locale, $language): ?string {
            foreach (array_unique([$locale, $language]) as $candidate) {
                if ($translator->has($id, $candidate)) {
                    return $translator->get($id, [], $candidate);
                }
            }

            return null;
        };

        $file = dirname(__DIR__, 3).'/resources/lang/en/labels.php';
        $keys = [...InvoiceTitle::keys(), ...(is_file($file) ? array_map(strval(...), array_keys((array) require $file)) : [])];
        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = $label('quittio::labels.'.$key) ?? $translator->get('quittio::labels.'.$key, [], $locale);
        }

        if ($context->country !== null) {
            $namespace = 'quittio-'.strtolower($context->country->code());
            foreach (array_keys($labels) as $key) {
                $labels[$key] = $label($namespace.'::labels.'.$key) ?? $labels[$key];
            }
        }

        return $labels;
    }

    /**
     * Margin scheme or travel agents (section 25, 25a UStG): the exempt VAT group carries one
     * of their exemption codes, or all its lines do (with different codes the group has none).
     * Taxed on the margin, without a separate VAT statement.
     */
    public function isSpecialScheme(VatCategory $category): bool
    {
        if ($category !== VatCategory::Exempt) {
            return false;
        }
        foreach ($this->invoice->vatBreakdown as $row) {
            if ($row->category === $category && in_array(strtoupper((string) $row->exemptionReasonCode?->value), ViewModelFactory::SPECIAL_SCHEME_CODES, true)) {
                return true;
            }
        }
        $lines = array_filter($this->invoice->lines, static fn (InvoiceLine $line): bool => $line->vat->category === VatCategory::Exempt);

        return $lines !== [] && array_filter($lines, static fn (InvoiceLine $line): bool => ! ViewModelFactory::isSpecialSchemeLine($line)) === [];
    }

    /**
     * A line of a margin scheme or travel agents: by its own exemption code, or as part of a
     * special scheme group (isSpecialScheme()).
     */
    public function isSpecialSchemeLine(InvoiceLine $line): bool
    {
        return ViewModelFactory::isSpecialSchemeLine($line) || $this->isSpecialScheme($line->vat->category);
    }

    /**
     * Credit notes and negative invoices: the buyer does not pay, so no bank details for a transfer.
     */
    public function isRefund(): bool
    {
        return $this->invoice->isCreditNote() || ($this->invoice->totals?->amountDue->isNegative() ?? false);
    }

    /**
     * Descriptions and notes are part of the XML: plain text unless Markdown is enabled.
     */
    public function textHtml(?string $text): string
    {
        return ($this->context->options['markdown'] ?? false) === true ? Markdown::toHtml($text) : Markdown::plain($text);
    }

    public function money(?Money $money): ?FormattedValue
    {
        return $money === null ? null : new FormattedValue($this->context->formatter->money($money, $this->locale), $money);
    }

    public function date(?Date $date): ?FormattedValue
    {
        return $date === null ? null : new FormattedValue($this->context->formatter->date($date, $this->locale), $date);
    }

    public function period(Period $period): FormattedValue
    {
        $start = $period->start === null ? null : $this->context->formatter->date($period->start, $this->locale);
        $end = $period->end === null ? null : $this->context->formatter->date($period->end, $this->locale);
        // Within one year the German short form "01.10.–31.10.2026": the start without its year.
        $year = $period->start?->format('Y');
        $short = $start !== null && $end !== null && $year === $period->end?->format('Y') && str_ends_with($start, '.'.$year)
            ? substr($start, 0, -strlen($year))
            : $start;

        return new FormattedValue(match (true) {
            $start !== null && $end !== null && $start === $end => $start,
            $start !== null && $end !== null => $short.'–'.$end,
            default => (string) ($start ?? $end),
        }, $period);
    }

    public function percent(BigDecimal $value): FormattedValue
    {
        return new FormattedValue($this->context->formatter->percent($value, $this->locale), $value);
    }

    /**
     * @param  list<string|null>  $values
     */
    public static function join(array $values, string $separator = ' '): string
    {
        return implode($separator, self::filled($values));
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    public static function filled(array $values): array
    {
        return array_values(array_filter($values, static fn (?string $value): bool => $value !== null && $value !== ''));
    }
}
