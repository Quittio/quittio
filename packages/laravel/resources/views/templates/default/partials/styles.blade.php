@php
    $primary = $design->primaryColor;
    $secondary = $design->secondaryColor;
    $muted = $design->mutedColor;
@endphp
    @page { margin: {{ $design->pageMarginTop }}mm 15mm 30mm 20mm; footer: html_quittioFooter; }
    body { font-family: {{ $design->font }}; font-size: 9pt; color: {{ $design->textColor }}; line-height: 1.35; }
    table { border-collapse: collapse; width: 100%; }
    td, th { vertical-align: top; }
    .muted { color: {{ $muted }}; }
    .small { font-size: 7.5pt; }
    .right { text-align: right; }
    .letterhead td { height: {{ $design->letterheadHeight }}mm; }
    .company { font-size: 14pt; font-weight: bold; color: {{ $primary }}; }
    /* mPDF ignores margin and padding of blocks inside table cells; spacing there uses cell padding. */
    /* The address field is positioned on the page (letter layout, e.g. DIN 5008), whatever the letterhead height. */
    .address { position: absolute; top: {{ $design->addressTop }}mm; left: {{ $design->addressLeft }}mm; width: {{ $design->addressWidth }}mm; height: {{ $design->addressHeight }}mm; overflow: visible; }
    /* DIN 5008: the sender line closes the "Zusatz- und Vermerkzone", the recipient starts in the "Anschriftzone". */
    .notice-zone { height: {{ $design->noticeZoneHeight }}mm; }
    .sender-line { font-size: 6.5pt; color: {{ $muted }}; border-bottom: 0.2mm solid {{ $muted }}; height: {{ max(0, $design->senderLineHeight - 1) }}mm; padding-bottom: 1mm; }
    .recipient { height: {{ $design->addressHeight }}mm; }
    .mark { position: absolute; left: 5mm; width: 5mm; height: 0; border-top: 0.2mm solid {{ $muted }}; }
    .meta td { padding: 0.6mm 0; }
    .meta td.label { color: {{ $muted }}; padding-right: 4mm; }
    h1 { font-size: 15pt; color: {{ $primary }}; margin: 8mm 0 3mm 0; }
    .lines th { background-color: {{ $primary }}; color: #ffffff; font-weight: bold; padding: 1.5mm 1.5mm; text-align: left; font-size: 8pt; }
    .lines td { padding: 1.5mm 1.5mm; border-bottom: 0.2mm solid #e5e7eb; }
    .lines .num { text-align: right; white-space: nowrap; }
    .lines .group td { font-weight: bold; background-color: #f3f4f6; }
    .lines .sub td { color: {{ $muted }}; font-size: 8pt; }
    /* Highlighted lines (LineBuilder::highlight()): shaded, marked in the secondary colour. */
    .lines .highlight td { background-color: #f8fafc; }
    .lines .highlight td.position { border-left: 0.8mm solid {{ $secondary }}; }
    .description { color: {{ $muted }}; font-size: 8pt; }
    .totals { width: 80mm; margin-left: auto; margin-top: 4mm; page-break-inside: avoid; }
    .totals td { padding: 1mm 1.5mm; }
    .totals .due td { font-weight: bold; font-size: 10.5pt; border-top: 0.4mm solid {{ $secondary }}; }
    .section { margin-top: 6mm; page-break-inside: avoid; }
    .section-title { font-weight: bold; color: {{ $secondary }}; margin-bottom: 1mm; }
    .stack td { padding: 0; }
    .stack td.gap { padding-top: 4mm; }
    .footer td { font-size: 7pt; color: {{ $muted }}; padding-right: 3mm; }
@if ($design->watermarkCss)
    /* Watermark for browsers; mPDF ignores generated content and prints <watermarktext> instead. */
    body::before { content: {!! $design->watermarkCss !!}; position: fixed; top: 45%; left: 0; right: 0; text-align: center; font-size: 72pt; font-weight: bold; color: rgba(0, 0, 0, 0.08); transform: rotate(-35deg); pointer-events: none; z-index: 1000; }
@endif
