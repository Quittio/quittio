{{-- Header of the following pages: logo or name, title with number, recipient and date. The first page has the letterhead. --}}
<htmlpageheader name="quittioFirstHeader"></htmlpageheader>
<htmlpageheader name="quittioHeader">
    <table class="running"><tr>
        <td>
            @if ($design->logo)
                <img src="{{ $design->logo }}" style="max-height: {{ $design->logoMaxHeight }}mm; max-width: 60mm;">
            @else
                <span class="company">{{ $invoice->issuer->displayName }}</span>
            @endif
        </td>
        <td class="right">
            <div class="running-title">{{ $invoice->title }} {{ $invoice->number }}</div>
            <div class="running-meta">{{ $invoice->recipient->displayName }}@if (! $doc->isSelfBilled && $invoice->buyer->customerNumber) · {{ $labels->customer_number }} {{ $invoice->buyer->customerNumber }}@endif</div>
            <div class="running-meta">{{ $invoice->dateLabel }} {{ $invoice->issueDate }}</div>
        </td>
    </tr></table>
</htmlpageheader>
