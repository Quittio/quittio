@if ($invoice->totals && $doc->showTotals)
@php($totals = $invoice->totals)
<table class="totals">
    @if ($totals->allowanceSum || $totals->chargeSum)
        @include($partials.'.allowances')
    @endif
    <tr><td class="label">{{ $labels->net_total }}</td><td class="right">{{ $totals->netTotal }}</td></tr>
    @foreach ($invoice->vatRows as $row)
        <tr><td class="label">{{ $row->label }}</td><td class="right">{{ $row->amount }}</td></tr>
    @endforeach
    @if ($totals->amountDue && ! $totals->dueEqualsGross)
        <tr class="gross"><td>{{ $labels->gross_total }}</td><td class="right">{{ $totals->grossTotal }}</td></tr>
        @if ($totals->prepaid)
            <tr><td class="label">{{ $labels->prepaid }}</td><td class="right">−{{ $totals->prepaid }}</td></tr>
        @endif
        @if ($totals->rounding)
            <tr><td class="label">{{ $labels->rounding }}</td><td class="right">{{ $totals->rounding }}</td></tr>
        @endif
        @foreach ($totals->thirdPartyPayments as $payment)
            <tr><td class="label">{{ $payment->description }}</td><td class="right">{{ $payment->amount }}</td></tr>
        @endforeach
        <tr class="gap"><td></td><td></td></tr>
        <tr class="grand"><td class="grand-label">{{ $totals->amountDueLabel }}</td><td class="right amount">{{ $totals->amountDue }}</td></tr>
    @else
        {{-- Nothing prepaid or rounded: the amount due is the gross total, printed once. --}}
        <tr class="gap"><td></td><td></td></tr>
        <tr class="grand"><td class="grand-label">{{ $labels->gross_total }}</td><td class="right amount">{{ $totals->amountDue ?? $totals->grossTotal }}</td></tr>
    @endif
</table>
@if ($totals->taxCurrencyNote)
    <p class="small muted right">{{ $totals->taxCurrencyNote }}</p>
@endif
@endif
