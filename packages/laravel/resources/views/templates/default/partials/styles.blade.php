@php
    $primary = $design->primaryColor;
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
    .address { width: {{ $design->addressWidth }}mm; }
    .address td { padding: 0; }
    .address td.sender-line { font-size: 6.5pt; color: {{ $muted }}; border-bottom: 0.2mm solid {{ $muted }}; padding-bottom: 1mm; }
    .address td.address-lines { padding-top: 2mm; }
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
    .description { color: {{ $muted }}; font-size: 8pt; }
    .totals { width: 80mm; margin-left: auto; margin-top: 4mm; page-break-inside: avoid; }
    .totals td { padding: 1mm 1.5mm; }
    .totals .due td { font-weight: bold; font-size: 10.5pt; border-top: 0.4mm solid {{ $primary }}; }
    .section { margin-top: 6mm; page-break-inside: avoid; }
    .section-title { font-weight: bold; color: {{ $primary }}; margin-bottom: 1mm; }
    .stack td { padding: 0; }
    .stack td.gap { padding-top: 4mm; }
    .footer td { font-size: 7pt; color: {{ $muted }}; padding-right: 3mm; }
