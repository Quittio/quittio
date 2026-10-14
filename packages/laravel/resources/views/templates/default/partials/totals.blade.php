@if ($invoice->totals && $doc->showTotals)
<table class="totals">
    @if ($invoice->totals->allowanceSum || $invoice->totals->chargeSum)
        @include($partials.'.allowances')
    @endif
    <tr><td>{{ $labels->net_total }}</td><td class="right">{{ $invoice->totals->netTotal }}</td></tr>
    @foreach ($invoice->vatRows as $row)
        <tr><td>{{ $row->label }}</td><td class="right">{{ $row->amount }}</td></tr>
    @endforeach
    <tr><td><strong>{{ $labels->gross_total }}</strong></td><td class="right"><strong>{{ $invoice->totals->grossTotal }}</strong></td></tr>
    @if ($invoice->totals->prepaid)
        <tr><td>{{ $labels->prepaid }}</td><td class="right">−{{ $invoice->totals->prepaid }}</td></tr>
    @endif
    @if ($invoice->totals->rounding)
        <tr><td>{{ $labels->rounding }}</td><td class="right">{{ $invoice->totals->rounding }}</td></tr>
    @endif
    @foreach ($invoice->totals->thirdPartyPayments as $payment)
        <tr><td>{{ $payment->description }}</td><td class="right">{{ $payment->amount }}</td></tr>
    @endforeach
    @if ($invoice->totals->amountDue)
        <tr class="due"><td>{{ $invoice->totals->amountDueLabel }}</td><td class="right">{{ $invoice->totals->amountDue }}</td></tr>
    @endif
</table>
@if ($invoice->totals->taxCurrencyNote)
    <p class="small muted right">{{ $invoice->totals->taxCurrencyNote }}</p>
@endif
@endif
