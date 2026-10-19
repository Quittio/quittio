{{--
    Quittio default template (design levels 1 and 2).

    The only variable is $invoice (Quittio\Core\View\InvoiceViewModel). Every value is
    available formatted ({{ $value }}) and raw ($value->raw). No calculation happens here.
    Publish with "php artisan vendor:publish --tag=quittio-views" to change single partials.
--}}
@php
    $design = $invoice->design;
    $labels = $invoice->labels;
    $doc = $invoice->document;
    $info = $invoice->documentInfo;
    $prices = (bool) $doc->showPrices;
    // A VAT column only for several rates; one rate is named in the totals.
    $vatColumn = $prices && count($invoice->vatRows) > 1;
    $columns = $prices ? ($vatColumn ? 6 : 5) : 3;
    $partials = $partials ?? 'quittio::templates.default.partials';
@endphp
<html>
<head>
<style>
@include($partials.'.styles')
</style>
</head>
<body>

@include($partials.'.header')
@include($partials.'.footer')

@if ($design->watermark)
    <watermarktext content="{{ $design->watermark }}" alpha="0.08" />
@endif

@include($partials.'.marks')

{{-- Address field at the position of the letter layout (a direct child of <body> for mPDF). --}}
<div class="address">
    <div class="sender-line">@include($partials.'.sender-line')</div>
    <div class="address-lines">@include($partials.'.recipient')</div>
</div>

@include($partials.'.letterhead')

<table class="head"><tr>
    <td class="address-space">&nbsp;</td>
    <td class="meta-cell">
        @include($partials.'.meta')
    </td>
</tr></table>

@include($partials.'.title')
@include($partials.'.references')
@include($partials.'.intro')
@include($partials.'.lines')
@include($partials.'.totals')
@include($partials.'.documents')
@include($partials.'.tax-notes')
@include($partials.'.payment')
@include($partials.'.notes')
@include($partials.'.attachments')
@include($partials.'.closing')
@include($partials.'.payment-slip')

</body>
</html>
