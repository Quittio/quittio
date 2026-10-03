<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

use Brick\Money\Money;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Support\Amount;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Xml\Cii\CiiReader;
use Quittio\Core\Xml\Ubl\UblReader;
use Quittio\Core\Xml\XmlSecurity;
use Throwable;

/**
 * Reads e-invoices from XML or PDF (specification 10.4).
 *
 * Detects the syntax and profile, keeps the declared totals and reports
 * differences to a recalculation instead of correcting them silently.
 */
final class InvoiceReader
{
    /** @var list<FormatReader> */
    private array $readers;

    private readonly ProfileRegistry $profiles;

    /**
     * @param  list<FormatReader>|null  $readers
     */
    public function __construct(
        ?array $readers = null,
        ?ProfileRegistry $profiles = null,
        private readonly TotalsCalculator $calculator = new En16931Calculator,
        private readonly EmbeddedXmlExtractor $pdfExtractor = new PdfEmbeddedFileExtractor,
        private readonly int $maxXmlBytes = XmlSecurity::DEFAULT_MAX_BYTES,
    ) {
        $this->readers = $readers ?? self::defaultReaders();
        $this->profiles = $profiles ?? ProfileRegistry::withDefaults();
    }

    /**
     * @return list<FormatReader>
     */
    public static function defaultReaders(): array
    {
        return [new CiiReader, new UblReader];
    }

    public function withReader(FormatReader $reader): self
    {
        $clone = clone $this;
        $clone->readers[] = $reader;

        return $clone;
    }

    /**
     * Reads a file path or the binary content of an XML or PDF file.
     */
    public function read(string $pathOrContent): ReadResult
    {
        $content = $this->load($pathOrContent);
        $embeddedFilename = null;

        if (str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF')) {
            $embedded = $this->pdfExtractor->extract($content);
            if ($embedded === null) {
                throw new UnreadableInvoiceException('The PDF does not contain an embedded e-invoice (factur-x.xml, xrechnung.xml or zugferd-invoice.xml).');
            }
            [$embeddedFilename, $xml] = $embedded;
        } else {
            $xml = $content;
        }

        $document = XmlSecurity::load($xml, $this->maxXmlBytes);
        foreach ($this->readers as $reader) {
            if ($reader->canRead($document)) {
                $result = $reader->read($document, $xml);

                return $this->complete($result, $embeddedFilename === null ? null : $content, $embeddedFilename);
            }
        }

        $root = $document->documentElement;
        throw new UnreadableInvoiceException(sprintf('Unknown invoice format (root element "%s" in namespace "%s").', $root?->localName, $root?->namespaceURI));
    }

    private function complete(ReadResult $result, ?string $raw, ?string $embeddedFilename): ReadResult
    {
        $profile = $result->specificationId === null ? null : $this->profiles->bySpecificationId($result->specificationId);
        $findings = [...$result->findings, ...$this->compareWithRecalculation($result->data)];

        if ($result->specificationId !== null && $profile === null) {
            $findings[] = new ValidationMessage(Severity::Info, 'QUITTIO-READER-UNKNOWN-PROFILE', sprintf('The specification identifier "%s" (BT-24) is not known to this installation.', $result->specificationId), ValidationLevel::L1, 'BT-24');
        }

        return new ReadResult(
            data: $result->data,
            syntax: $result->syntax,
            specificationId: $result->specificationId,
            profile: $profile?->key(),
            declaredTotals: $result->declaredTotals,
            declaredVatBreakdown: $result->declaredVatBreakdown,
            findings: $findings,
            xml: $result->xml,
            raw: $raw ?? $result->xml,
            embeddedFilename: $embeddedFilename,
        );
    }

    /**
     * @return list<ValidationMessage>
     */
    private function compareWithRecalculation(InvoiceData $declared): array
    {
        if ($declared->totals === null) {
            return [];
        }

        try {
            $recalculated = $this->calculator->calculate($declared->with(['totals' => null, 'vatBreakdown' => []]));
        } catch (Throwable $e) {
            return [new ValidationMessage(Severity::Warning, 'QUITTIO-READER-RECALCULATION', 'The invoice could not be recalculated: '.$e->getMessage(), ValidationLevel::L3)];
        }

        $findings = [];
        $compare = static function (string $bt, string $label, ?Money $declaredValue, ?Money $calculatedValue) use (&$findings): void {
            $a = $declaredValue?->getAmount();
            $b = $calculatedValue?->getAmount();
            if ($a === null || $b === null || $a->isEqualTo($b)) {
                return;
            }
            $findings[] = new ValidationMessage(
                severity: Severity::Warning,
                code: 'QUITTIO-DECLARED-TOTALS',
                message: sprintf('%s (%s): declared %s, recalculated %s.', $label, $bt, Amount::format($declaredValue), Amount::format($calculatedValue)),
                level: ValidationLevel::L3,
                bt: $bt,
            );
        };

        foreach ($declared->lines as $index => $line) {
            $compare('BT-131', 'Line '.$line->id.' net amount', $line->netAmount, $recalculated->lines[$index]->netAmount ?? null);
        }

        $d = $declared->totals;
        $r = $recalculated->totals;
        $compare('BT-106', 'Sum of line net amounts', $d->lineNetSum, $r?->lineNetSum);
        $compare('BT-109', 'Total without VAT', $d->netTotal, $r?->netTotal);
        $compare('BT-110', 'Total VAT amount', $d->vatTotal, $r?->vatTotal);
        $compare('BT-112', 'Total with VAT', $d->grossTotal, $r?->grossTotal);
        $compare('BT-115', 'Amount due', $d->amountDue, $r?->amountDue);

        return $findings;
    }

    private function load(string $pathOrContent): string
    {
        if (strlen($pathOrContent) < 4096 && ! str_contains($pathOrContent, "\0") && ! str_contains($pathOrContent, '<') && is_file($pathOrContent)) {
            $content = file_get_contents($pathOrContent);
            if ($content === false) {
                throw new UnreadableInvoiceException(sprintf('Unable to read file "%s".', $pathOrContent));
            }

            return $content;
        }

        return $pathOrContent;
    }
}
