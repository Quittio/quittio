@php
    $primary = $design->primaryColor;
    $secondary = $design->secondaryColor;
    $text = $design->textColor;
    $muted = $design->mutedColor;
    // mPDF knows only normal and bold: medium and semibold come from a family of their own (Geist).
    $medium = $design->fontMedium ? 'font-family: '.$design->fontMedium.';' : 'font-weight: bold;';
    $semibold = $design->fontMedium ? 'font-family: '.$design->fontMedium.'; font-weight: bold;' : 'font-weight: bold;';
@endphp
    /* Following pages start below their header (logo, title, number and buyer); the first page with the letterhead. */
    @page { margin: {{ $design->continuationMarginTop }}mm 19mm 37mm 20mm; margin-header: 14.6mm; margin-footer: 5.5mm; header: html_quittioHeader; footer: html_quittioFooter; }
    @page :first { margin-top: {{ $design->pageMarginTop }}mm; header: html_quittioFirstHeader; }
    body { font-family: {{ $design->font }}; font-size: 9.75pt; color: {{ $text }}; line-height: 1.45; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; padding: 0; line-height: 1.5; }
    .muted { color: {{ $muted }}; }
    .small { font-size: 7.9pt; }
    .right { text-align: right; }
    .letterhead td { height: {{ $design->letterheadHeight }}mm; }
    .company { font-size: 16.5pt; font-weight: bold; color: {{ $primary }}; line-height: 1.2; }
    .issuer-address { font-size: 7.9pt; color: {{ $muted }}; }
    /* mPDF ignores margin and padding of blocks inside table cells; spacing there uses cell padding. */
    /* The address field is positioned on the page (letter layout, e.g. DIN 5008), whatever the letterhead height. */
    .address { position: absolute; top: {{ $design->addressTop }}mm; left: {{ $design->addressLeft }}mm; width: {{ $design->addressWidth }}mm; height: {{ $design->addressHeight }}mm; overflow: visible; }
    .sender-line { font-size: 9pt; color: {{ $muted }}; border-bottom: 0.26mm solid #cdd0d6; padding-bottom: 1.2mm; line-height: 1.3; }
    .address-lines { font-size: 10.5pt; line-height: 1.5; padding-top: 2.2mm; }
    .mark { position: absolute; left: 0; width: 4.2mm; height: 0; border-top: 0.26mm solid #cdd0d6; }
    .head td.address-space { width: 108.6mm; height: 41.8mm; }
    .meta td { font-size: 9.4pt; padding-bottom: 0.75mm; line-height: 1.5; }
    .meta td.label { color: {{ $muted }}; padding-right: 4mm; }
    .meta td.value { text-align: right; }
    h1 { font-size: 13.5pt; color: {{ $primary }}; font-weight: normal; {{ $semibold }} margin: 0 0 5mm 0; line-height: 1.5; }
    .lines th { font-size: 9pt; color: {{ $muted }}; font-weight: normal; {{ $medium }} text-align: left; padding: 0 0 2mm 0; border-bottom: 0.26mm solid {{ $text }}; }
    .lines td { padding: 2.2mm 0 2.6mm 0; border-bottom: 0.26mm solid #e6e8ec; }
    .lines .num { text-align: right; white-space: nowrap; }
    .lines td.num, .lines td.position { color: {{ $muted }}; }
    .lines td.amount { color: {{ $text }}; }
    .lines .name { {{ $medium }} }
    .lines .group td { {{ $semibold }} padding-top: 5mm; }
    .lines .sub td { color: {{ $muted }}; font-size: 9pt; padding-top: 1mm; }
    .lines .subtotal td { color: {{ $muted }}; }
    /* Highlighted lines (LineBuilder::highlight()): shaded, marked in the secondary colour. */
    .lines .highlight td { background-color: #f8fafc; }
    .lines .highlight td.position { border-left: 0.8mm solid {{ $secondary }}; padding-left: 1.5mm; }
    /* Carry-over rows at page breaks (filled in by the PDF renderer). */
    .lines .carry td { color: {{ $muted }}; border-bottom: 0; padding-top: 3.6mm; }
    .lines .carry-in td { border-bottom: 0.26mm solid #e6e8ec; padding-top: 2.2mm; }
    .lines td.carry-amount { color: {{ $text }}; {{ $medium }} }
    .description { color: {{ $muted }}; font-size: 9pt; }
    .totals { width: 74.2mm; margin-left: auto; margin-top: 3.1mm; page-break-inside: avoid; }
    .totals td { padding: 0.47mm 0; }
    .totals tr.gap td { height: 2.2mm; padding: 0; line-height: 1; }
    .totals td.label { color: {{ $muted }}; }
    .totals .gross td.label { color: {{ $text }}; }
    .totals .grand td { border-top: 0.26mm solid {{ $secondary }}; padding-top: 3.1mm; vertical-align: bottom; line-height: 1.2; }
    .totals .grand td.label { font-size: 10.5pt; color: {{ $text }}; {{ $semibold }} }
    .totals .grand td.amount { font-size: 12.75pt; font-weight: bold; color: {{ $primary }}; }
    .section { margin-top: 8.3mm; page-break-inside: avoid; }
    .section-title { font-size: 9pt; color: {{ $secondary }}; {{ $semibold }} }
    .payment td { font-size: 9.4pt; }
    .payment td.terms { width: 77mm; padding-right: 6mm; }
    td.qr { width: 17mm; text-align: center; padding-left: 4mm; }
    .payment td.section-title { font-size: 9pt; padding-bottom: 0.7mm; }
    .qr-caption { font-size: 7.9pt; color: {{ $muted }}; text-align: center; }
    .running td { vertical-align: top; }
    .running-title { font-size: 11.25pt; font-weight: bold; color: {{ $primary }}; }
    .running-meta { font-size: 9pt; color: {{ $muted }}; }
    .footer-rule { border-top: 0.26mm solid #e6e8ec; }
    .footer td { font-size: 7.9pt; color: {{ $muted }}; padding: 3.4mm 3mm 0 0; }
    .page-number { font-size: 7.9pt; color: {{ $muted }}; text-align: center; padding-top: 6.9mm; }
@if ($design->watermarkCss)
    /* Watermark for browsers; mPDF ignores generated content and prints <watermarktext> instead. */
    body::before { content: {!! $design->watermarkCss !!}; position: fixed; top: 45%; left: 0; right: 0; text-align: center; font-size: 72pt; font-weight: bold; color: rgba(0, 0, 0, 0.08); transform: rotate(-35deg); pointer-events: none; z-index: 1000; }
@endif
