<tr><td class="label">{{ $labels->line_net_sum }}</td><td class="right">{{ $invoice->totals->lineNetSum }}</td></tr>
@foreach ($invoice->allowances as $allowance)
    <tr><td class="label">{{ $allowance->label }}</td><td class="right">−{{ $allowance->amount }}</td></tr>
@endforeach
@foreach ($invoice->charges as $charge)
    <tr><td class="label">{{ $charge->label }}</td><td class="right">{{ $charge->amount }}</td></tr>
@endforeach
