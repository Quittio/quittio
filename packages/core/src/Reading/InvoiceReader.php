<?php

declare(strict_types=1);

namespace Quittio\Core\Reading;

use Brick\Money\Money;
use Exception;
use Quittio\Core\Calculation\DeclaredAmounts;
use Quittio\Core\Calculation\En16931Calculator;
use Quittio\Core\Calculation\TotalsCalculator;
use Quittio\Core\Data\InvoiceData;
use Quittio\Core\Pdf\PdfEmbeddedFileExtractor;
use Quittio\Core\Pdf\PdfException;
use Quittio\Core\Profiles\ProfileRegistry;
use Quittio\Core\Support\Amount;
use Quittio\Core\Validation\Severity;
use Quittio\Core\Validation\ValidationLevel;
use Quittio\Core\Validation\ValidationMessage;
use Quittio\Core\Xml\Cii\CiiReader;
use Quittio\Core\Xml\Ubl\UblReader;
use Quittio\Core\Xml\UnsafeXmlException;
use Quittio\Core\Xml\XmlSecurity;

/**
 * Reads e-invoices from XML or PDF.
 *
 * Detects the syntax and profile, keeps the declared totals and reports
 * differences to a recalculation instead of correcting them silently.
 */
final class InvoiceReader
{
    /** Namespace of ZUGFeRD 1.0, which is recognised but not supported. */
    public const string NS_ZUGFERD_1 = 'urn:ferd:CrossIndustryDocument:invoice:1p0';

    private const int DEFAULT_MAX_PDF_BYTES = 25 * 1024 * 1024;

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

    /**
     * A copy that also tries $reader after the readers so far, e.g. for a national format.
     */
    public function withReader(FormatReader $reader): self
    {
        $clone = clone $this;
        $clone->readers[] = $reader;

        return $clone;
    }

    /**
     * Reads the content (bytes) of an XML or PDF e-invoice.
     *
     * The argument is always treated as content, never as a file name; use readFile() for paths.
     *
     * @throws UnsafeXmlException when the XML is too large, not well-formed or not allowed (DOCTYPE, limits)
     * @throws PdfException when the PDF is too large, encrypted, malformed or exceeds a limit
     * @throws UnreadableInvoiceException for an unknown format, ZUGFeRD 1.0 or a PDF without an embedded invoice
     */
    public function read(string $content): ReadResult
    {
        $extracted = null;

        if (str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF')) {
            $extracted = $this->extract($content);
            $xml = $extracted->xml;
        } else {
            $xml = $content;
        }

        $document = XmlSecurity::load($xml, $this->maxXmlBytes);
        foreach ($this->readers as $reader) {
            if ($reader->canRead($document)) {
                $result = $reader->read($document, $xml);

                return $this->complete($result, $extracted === null ? null : $content, $extracted);
            }
        }

        $root = $document->documentElement;
        if ($root?->namespaceURI === self::NS_ZUGFERD_1) {
            throw new UnreadableInvoiceException('ZUGFeRD 1.0 invoices (urn:ferd:CrossIndustryDocument:invoice:1p0) are not supported. ZUGFeRD 1.0 does not conform to EN 16931; ask the sender for ZUGFeRD 2.x, Factur-X or XRechnung.');
        }

        throw new UnreadableInvoiceException(sprintf('Unknown invoice format (root element "%s" in namespace "%s").', $root?->localName, $root?->namespaceURI));
    }

    /**
     * Reads an XML or PDF e-invoice from a local file.
     *
     * @throws UnsafeXmlException when the XML is too large, not well-formed or not allowed (DOCTYPE, limits)
     * @throws PdfException when the PDF is too large, encrypted, malformed or exceeds a limit
     * @throws UnreadableInvoiceException for an unknown format, ZUGFeRD 1.0, a PDF without an embedded invoice, or a file that does not exist, is not readable or is too large
     */
    public function readFile(string $path): ReadResult
    {
        if (str_contains($path, "\0") || ! is_file($path) || ! is_readable($path)) {
            throw new UnreadableInvoiceException(sprintf('The file "%s" does not exist or is not readable.', $path));
        }

        $size = filesize($path);
        $limit = max($this->maxXmlBytes, $this->maxPdfBytes());
        if ($size !== false && $size > $limit) {
            throw new UnreadableInvoiceException(sprintf('The file "%s" exceeds the size limit of %d bytes.', $path, $limit));
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new UnreadableInvoiceException(sprintf('Unable to read file "%s".', $path));
        }

        return $this->read($content);
    }

    private function extract(string $pdf): ExtractedXml
    {
        if ($this->pdfExtractor instanceof DiagnosingXmlExtractor) {
            $extracted = $this->pdfExtractor->extractFile($pdf);
        } else {
            $embedded = $this->pdfExtractor->extract($pdf);
            $extracted = $embedded === null ? null : new ExtractedXml($embedded[0], $embedded[1]);
        }

        if ($extracted === null) {
            throw new UnreadableInvoiceException('The PDF does not contain an embedded e-invoice (factur-x.xml, xrechnung.xml or zugferd-invoice.xml).');
        }

        return $extracted;
    }

    private function maxPdfBytes(): int
    {
        return $this->pdfExtractor instanceof PdfEmbeddedFileExtractor ? $this->pdfExtractor->maxPdfBytes() : self::DEFAULT_MAX_PDF_BYTES;
    }

    private function complete(ReadResult $result, ?string $raw, ?ExtractedXml $extracted): ReadResult
    {
        $profile = $result->specificationId === null ? null : $this->profiles->bySpecificationId($result->specificationId);
        $findings = [...$result->findings, ...($extracted->findings ?? []), ...$this->compareWithRecalculation($result->data)];

        if ($result->specificationId !== null && $profile === null) {
            $findings[] = new ValidationMessage(Severity::Info, 'QUITTIO-READER-UNKNOWN-PROFILE', sprintf('The specification identifier "%s" (BT-24) is not known to this installation.', $result->specificationId), ValidationLevel::L1, 'BT-24');
        }

        $expectedRelationship = $profile?->afRelationship();
        if ($profile !== null && $extracted?->relationship !== null && $expectedRelationship !== null && $extracted->relationship !== $expectedRelationship) {
            $findings[] = new ValidationMessage(Severity::Warning, 'QUITTIO-READER-PDF-RELATIONSHIP', sprintf('The embedded file "%s" is attached with /AFRelationship /%s; the profile %s requires /%s.', $extracted->filename, $extracted->relationship, $profile->name(), $expectedRelationship), ValidationLevel::L1);
        }

        // Stamps the declared amounts so that edits made before from() or a cancellation are detected.
        $data = $result->data->with(['meta' => $result->data->meta->withAmountsFingerprint(DeclaredAmounts::fingerprint($result->data))]);

        return new ReadResult(
            data: $data,
            syntax: $result->syntax,
            specificationId: $result->specificationId,
            profile: $profile?->key(),
            declaredTotals: $result->declaredTotals,
            declaredVatBreakdown: $result->declaredVatBreakdown,
            findings: $findings,
            xml: $result->xml,
            raw: $raw ?? $result->xml,
            embeddedFilename: $extracted?->filename,
        );
    }

    /**
     * @return list<ValidationMessage>
     */
    private function compareWithRecalculation(InvoiceData $declared): array
    {
        // Profiles without lines (Factur-X MINIMUM, BASIC WL) cannot be recalculated from lines.
        if ($declared->totals === null || $declared->lines === []) {
            return [];
        }

        try {
            $recalculated = $this->calculator->calculate($declared->with(['totals' => null, 'vatBreakdown' => []]));
        } catch (Exception $e) {
            // Read data the calculator rejects is reported; errors such as a TypeError are not hidden.
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
}
