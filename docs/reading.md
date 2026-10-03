# Reading incoming invoices

```php
$read = Quittio::read($pathOrBinary);     // XML (CII, UBL) or PDF with embedded XML

$read->data;                 // InvoiceData as stated in the document
$read->declaredTotals;       // totals as stated in the document
$read->findings;             // e.g. deviations from the recalculated totals (QUITTIO-DECLARED-TOTALS)
$read->profile;              // detected profile (BT-24)
$read->embeddedFilename;     // factur-x.xml, xrechnung.xml, zugferd-invoice.xml …
```

```bash
php artisan quittio:read invoice.pdf
php artisan quittio:read invoice.xml --json
```

Security: external entities and DTDs are rejected, network access is disabled, and XML size, PDF size and decompressed stream size are limited (`quittio.reader.*`). Embedded file names are never used as paths. Unknown codes are kept as raw values instead of failing.
