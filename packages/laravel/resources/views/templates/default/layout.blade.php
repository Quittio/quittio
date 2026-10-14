{{--
    Quittio default template (design levels 1 and 2, specification 11.5).

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
    $columns = $prices ? 6 : 3;
    $partials = $partials ?? 'quittio::templates.default.partials';
@endphp
<html>
<head>
<style>
@include($partials.'.styles')
</style>
</head>
<body>

@include($partials.'.footer')

@if ($design->watermark)
    <watermarktext content="{{ $design->watermark }}" alpha="0.08" />
@endif

@include($partials.'.marks')

{{-- Address field at the position of the letter layout (a direct child of <body> for mPDF). --}}
<div class="address">
    <div class="notice-zone"></div>
    <div class="sender-line">@include($partials.'.sender-line')</div>
    <div class="address-lines">@include($partials.'.recipient')</div>
</div>

@include($partials.'.letterhead')

<table><tr>
    <td style="width: 95mm;" class="recipient">&nbsp;</td>
    <td>
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

</body>
</html>
