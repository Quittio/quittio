<?php

declare(strict_types=1);

namespace Quittio\Core\View\Internal\Sections;

use Quittio\Core\Data\Presentation\Colors;
use Quittio\Core\Exceptions\ConfigurationException;
use Quittio\Core\Pdf\LogoCache;
use Quittio\Core\View\Internal\RunScope;
use Quittio\Core\View\LetterLayout;
use Quittio\Core\View\ViewModelFactory;

/**
 * Colours, font, logo, letter layout, sender line and footer columns.
 *
 * @internal
 */
final readonly class DesignSection
{
    public function __construct(private RunScope $scope) {}

    /**
     * @param  array<string, mixed>  $issuer  the party shown in letterhead, sender line and footer
     * @return array{0: array<string, mixed>, 1: array<string, string>} the design, and the footer
     *         entries that print issuer fields (aliases for the mandatory field check)
     */
    public function build(array $issuer): array
    {
        $scope = $this->scope;
        $p = $scope->presentation;
        $colors = $p->colors ?? new Colors;
        $layouts = $scope->context->country?->letterLayouts() ?? [];
        $layoutName = $p->letterLayout ?? $scope->context->country?->defaults()->letterLayout ?? 'free';
        $layout = $layouts[$layoutName] ?? ($layoutName === 'free' ? LetterLayout::free() : throw new ConfigurationException(sprintf(
            'Unknown letter layout "%s". Known layouts: %s.',
            $layoutName,
            implode(', ', array_unique(['free', ...array_keys($layouts)])),
        )));
        $options = $scope->context->options;
        $marks = ($options['fold_marks'] ?? true) === true;
        $watermark = $p->watermark ?? (is_string($options['watermark'] ?? null) ? $options['watermark'] : null);
        // Always prepared: mPDF never fetches a logo itself (remote URLs are downloaded from public hosts only).
        $resolved = $scope->context->logo;
        if ($resolved !== null && $resolved->logo === $p->logo) {
            $logo = $resolved->src;
        } else {
            // Called directly, without a render run that resolved the logo.
            $cache = is_string($options['logo_cache'] ?? null) ? $options['logo_cache'] : sys_get_temp_dir().'/quittio-mpdf/logos';
            $logo = (new LogoCache($cache, pdfa: ($options['pdfa'] ?? false) === true))->resolve($p->logo);
        }
        // Own footer columns, also none at all when set explicitly to [].
        $customFooter = $p->footerColumns !== [] || $p->isExplicit('footerColumns');
        [$footer, $aliases] = $customFooter ? [$p->footerColumns, []] : $this->footerColumns($issuer);
        $font = self::font($p->font ?? 'geist');

        return [[
            // Values printed into <style>: only hex colours and plain font names (no CSS injection).
            'primaryColor' => self::color($colors->primary),
            'secondaryColor' => self::color($colors->secondary ?? $colors->primary),
            'textColor' => self::color($colors->text),
            'mutedColor' => self::color($colors->muted),
            'font' => $font,
            // Medium weight (line names, table head) and semibold (title, headings): Geist has
            // them as the family "geistmedium" (normal and bold); for other fonts null, bold is used.
            'fontMedium' => strtolower($font) === 'geist' ? 'geistmedium' : null,
            'logo' => $logo,
            'letterLayout' => $layout->name,
            // Address field position on the first page, independent of the letterhead height.
            'addressTop' => $layout->addressTop,
            'addressLeft' => $layout->addressLeft,
            'pageMarginTop' => ViewModelFactory::PAGE_MARGIN_TOP,
            'continuationMarginTop' => ViewModelFactory::CONTINUATION_MARGIN_TOP,
            'letterheadHeight' => $layout->letterheadHeight(ViewModelFactory::PAGE_MARGIN_TOP),
            // The logo ends 2 mm above the address field (DIN 5008 form A leaves less room than form B).
            'logoMaxHeight' => round(min(14.0, max(6.0, $layout->letterheadHeight(ViewModelFactory::PAGE_MARGIN_TOP) - 2.0)), 2),
            'addressHeight' => $layout->addressHeight,
            'addressWidth' => $layout->addressWidth,
            // DIN 5008: the sender line is the last line of the "Zusatz- und Vermerkzone",
            // the recipient starts in the "Anschriftzone" at addressZoneTop below the field top.
            'addressZoneTop' => $layout->addressZoneTop,
            'senderLineHeight' => $layout->senderLineHeight,
            'noticeZoneHeight' => max(0.0, round($layout->addressZoneTop - $layout->senderLineHeight, 2)),
            'foldMarks' => $marks ? $layout->foldMarks : [],
            'punchMark' => $marks ? $layout->punchMark : null,
            'paper' => $p->paper,
            'watermark' => $watermark,
            // The watermark as CSS string literal for the HTML overlay (mPDF prints <watermarktext>).
            'watermarkCss' => $watermark === null ? null : self::cssString($watermark),
            'senderLine' => $p->senderLine ?? $this->senderLine($issuer),
            'footerColumns' => $footer,
            // The generated footer starts with the issuer's address; a custom footer may not.
            'footerHasAddress' => ! $customFooter && isset($aliases['design.footerColumns[0]']),
            // The generated footer shows the issuer's VAT identifier and tax number; otherwise the
            // template prints them with the invoice details.
            'footerHasTaxIds' => ! $customFooter && array_intersect(['issuer.vatId', 'issuer.taxNumber'], $aliases) !== [],
            'showItemNumbers' => $p->showItemNumbers,
            'showGrossPrices' => $p->showGrossPrices,
        ], $aliases];
    }

    /**
     * @param  array<string, mixed>  $issuer
     */
    private function senderLine(array $issuer): string
    {
        $address = is_array($issuer['address'] ?? null) ? $issuer['address'] : [];
        $place = trim((is_string($address['postcode'] ?? null) ? $address['postcode'] : '').' '.(is_string($address['city'] ?? null) ? $address['city'] : ''));

        return implode(' · ', RunScope::filled([
            is_string($issuer['displayName'] ?? null) ? $issuer['displayName'] : null,
            is_string($address['street'] ?? null) ? $address['street'] : null,
            $place,
        ]));
    }

    /**
     * Default footer: address, contact, registration and tax identifiers of the issuer. The bank
     * details are printed with the payment information.
     *
     * @param  array<string, mixed>  $issuer
     * @return array{0: list<list<string>>, 1: array<string, string>} the columns, and the entries
     *         that print issuer fields (aliases for the mandatory field check)
     */
    private function footerColumns(array $issuer): array
    {
        $labels = $this->scope->labels;
        $label = static fn (string $key): string => $labels[$key] ?? $key;
        $string = static fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;
        $address = is_array($issuer['address'] ?? null) ? $issuer['address'] : [];
        $contact = is_array($issuer['contact'] ?? null) ? $issuer['contact'] : [];

        $columns = [];
        $aliases = [];
        // Exactly the issuer's address lines (the guard maps this column onto them).
        $lines = is_array($address['lines'] ?? null) ? array_values(array_filter($address['lines'], is_string(...))) : [];
        if ($lines !== []) {
            $aliases['design.footerColumns[0]'] = 'issuer.address.lines';
            $columns[] = $lines;
        }

        $reach = RunScope::filled([$string($contact['phone'] ?? null), $string($contact['email'] ?? null)]);
        if ($reach !== []) {
            $columns[] = $reach;
        }

        // Register entry and further legal information (BT-33), e.g. "Amtsgericht Musterstadt · Geschäftsführer: …", one part per line.
        $additional = $string($issuer['additionalLegalInfo'] ?? null);
        $parts = $additional === null ? false : preg_split('/\s+·\s+|\R/u', $additional);
        $registration = $string($issuer['legalRegistration'] ?? null);
        $legal = RunScope::filled([$registration, ...array_map(trim(...), $parts === false ? [] : $parts)]);
        if ($legal !== []) {
            if ($registration !== null) {
                // The register number (BT-30) counts as printed for the mandatory field guard, as the tax identifiers below.
                $aliases[sprintf('design.footerColumns[%d][0]', count($columns))] = 'issuer.legalRegistration';
            }
            $columns[] = $legal;
        }

        $tax = [];
        foreach (['vatId' => 'vat_id', 'taxNumber' => 'tax_number_short'] as $field => $key) {
            $value = $string($issuer[$field] ?? null);
            if ($value !== null) {
                $aliases[sprintf('design.footerColumns[%d][%d]', count($columns), count($tax))] = 'issuer.'.$field;
                $tax[] = $label($key).' '.$value;
            }
        }
        if ($tax !== []) {
            $columns[] = $tax;
        }

        return [$columns, $aliases];
    }

    private static function color(string $value): string
    {
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) !== 1) {
            throw new ConfigurationException(sprintf('Invalid colour "%s": use a hex colour such as "#1e40af".', $value));
        }

        return $value;
    }

    private static function font(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{0,63}$/', $value) !== 1) {
            throw new ConfigurationException(sprintf('Invalid font name "%s": use the name of a registered font family such as "geist".', $value));
        }

        return $value;
    }

    /**
     * Quoted CSS string with every character outside [A-Za-z0-9 ] escaped, safe inside <style>.
     */
    private static function cssString(string $value): string
    {
        $escaped = preg_replace_callback('/[^A-Za-z0-9 ]/u', static fn (array $m): string => sprintf('\\%X ', (int) mb_ord($m[0], 'UTF-8')), $value);

        return '"'.($escaped ?? '').'"';
    }
}
