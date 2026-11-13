# quittio/core

Country-neutral core of Quittio: EN 16931 data model, calculation, profiles, CII and UBL writers and readers, ZUGFeRD/Factur-X PDF/A-3 and validation. Usable without Laravel: it runs in any PHP 8.3 application, with Symfony, another framework or none.

With the core you create ZUGFeRD / Factur-X invoices (PDF/A-3 with embedded CII XML), Peppol BIS Billing 3.0 and plain UBL or CII XML, validate them against the rules of EN 16931 and read incoming e-invoices. For XRechnung and the German VAT rules, add the country pack Germany:

```bash
composer require quittio/core quittio/country-de
```

Most applications install everything at once with `composer require quittio/quittio` (core, Laravel integration and the country pack Germany).

- Documentation: https://quittio.dev/docs ([getting started without Laravel](https://quittio.dev/docs/getting-started#without-laravel))
- Source, issues and pull requests: https://github.com/Quittio/quittio

> Developed in [`packages/core`](https://github.com/Quittio/quittio/tree/master/packages/core) of the monorepo [Quittio/quittio](https://github.com/Quittio/quittio) and published as the read-only repository [Quittio/core](https://github.com/Quittio/core). Please open issues and pull requests in the monorepo.

## Licence

MIT, see [LICENSE](LICENSE).
