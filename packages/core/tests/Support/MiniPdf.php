<?php

declare(strict_types=1);

namespace Quittio\Core\Tests\Support;

/**
 * Small hand-made PDFs for the parser and extractor tests.
 */
final class MiniPdf
{
    /**
     * @param  array<int, string>  $objects  object number => object body (without "n 0 obj" and "endobj")
     * @param  bool  $xref  false = no cross-reference table, the reader must rebuild the index
     */
    public static function build(array $objects, string $trailer = '', bool $xref = true): string
    {
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        ksort($objects);
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }
        $size = max(array_keys($objects)) + 1;
        if (! $xref) {
            return $pdf."trailer\n<< /Size ".$size.' /Root 1 0 R '.$trailer.">>\n%%EOF\n";
        }

        $start = strlen($pdf);
        $pdf .= "xref\n0 ".$size."\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; $number++) {
            $pdf .= isset($offsets[$number]) ? sprintf("%010d 00000 n \n", $offsets[$number]) : "0000000000 00000 f \n";
        }

        return $pdf."trailer\n<< /Size ".$size.' /Root 1 0 R '.$trailer.">>\nstartxref\n".$start."\n%%EOF\n";
    }

    public static function stream(string $data, string $dictionary = '', bool $compress = false): string
    {
        if ($compress) {
            $data = (string) gzcompress($data);
            $dictionary .= ' /Filter /FlateDecode';
        }

        return '<< '.trim($dictionary).' /Length '.strlen($data)." >>\nstream\n".$data."\nendstream";
    }

    /**
     * A one-page PDF with $xml embedded as associated file and in the name tree.
     */
    public static function withEmbeddedFile(string $xml, string $name = 'factur-x.xml', bool $compress = false, bool $xref = true): string
    {
        return self::build([
            1 => '<< /Type /Catalog /Pages 2 0 R /Names << /EmbeddedFiles << /Names [('.$name.') 4 0 R] >> >> /AF [4 0 R] >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] >>',
            4 => '<< /Type /Filespec /F ('.$name.') /UF ('.$name.') /AFRelationship /Alternative /EF << /F 5 0 R >> >>',
            5 => self::stream($xml, '/Type /EmbeddedFile /Subtype /text#2Fxml', $compress),
        ], xref: $xref);
    }
}
