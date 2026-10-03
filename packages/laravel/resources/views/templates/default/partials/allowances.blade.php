<tr><td>{{ $labels->line_net_sum }}</td><td class="right">{{ $invoice->totals->lineNetSum }}</td></tr>
@foreach ($invoice->allowances as $allowance)
    <tr><td>{{ $labels->allowance }} {{ $allowance->reason }} {{ $allowance->percent }}</td><td class="right">−{{ $allowance->amount }}</td></tr>
@endforeach
@foreach ($invoice->charges as $charge)
    <tr><td>{{ $labels->charge }} {{ $charge->reason }} {{ $charge->percent }}</td><td class="right">{{ $charge->amount }}</td></tr>
@endforeach
