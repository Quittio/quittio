{{-- A Blade template as published before the typed view model: array syntax, no types. Prints what typed.blade.php prints. --}}
<html><body>
<h1>{{ $invoice['title'] }} {{ $invoice['number'] }}</h1>
<p>{{ $invoice['dateLabel'] }} {{ $invoice['issueDate'] }} @if ($invoice['servicePeriod']) · {{ $invoice['servicePeriodLabel'] }} {{ $invoice['servicePeriod'] }} @endif</p>
@foreach (['issuer', 'recipient'] as $role)
    <address>@foreach ($invoice[$role]['address']['lines'] as $line){{ $line }}<br>@endforeach @if ($invoice[$role]['vatId']){{ $invoice[$role]['vatId'] }}@endif @if ($invoice[$role]['taxNumber']){{ $invoice[$role]['taxNumber'] }}@endif</address>
@endforeach
@foreach ($invoice['precedingInvoices'] as $preceding)<p>{{ $preceding['number'] }} {{ $preceding['issueDate'] }}</p>@endforeach
<table>
@foreach ($invoice['lineGroups'] as $group)
    @foreach ($group['lines'] as $line)
        <tr data-carry-over="{{ $line['carryOver'] }}"><td>{{ $line['position'] }}</td><td>{{ $line['name'] }}</td><td>{{ $line['quantity'] }} {{ $line['unit']['label'] }}</td><td>{{ $line['total'] }}</td></tr>
        @foreach ($line['subLines'] as $sub)<tr><td>{{ $sub['position'] }}</td><td>{{ $sub['name'] }}</td><td>{{ $sub['quantity'] }} {{ $sub['unit']['label'] }}</td></tr>@endforeach
    @endforeach
@endforeach
</table>
@if ($invoice['totals'])
    @foreach ($invoice['vatRows'] as $row)<p>{{ $row['label'] }} {{ $row['amount'] }}</p>@endforeach
    <p>{{ $invoice['totals']['amountDueLabel'] }} {{ $invoice['totals']['amountDue'] }}</p>
@endif
@foreach ($invoice['legalNotes'] as $note)<p>{{ $note }}</p>@endforeach
@if ($invoice['paymentTerms'])@foreach ($invoice['paymentTerms']['lines'] as $text)<p>{{ $text }}</p>@endforeach @endif
@if ($invoice['payment'])@foreach ($invoice['payment']['accounts'] as $account)<p>{{ $account['iban'] }}</p>@endforeach @endif
<footer>@foreach ($invoice['design']['footerColumns'] as $column)<div>{{ implode(' · ', $column->toArray()) }}</div>@endforeach</footer>
<p>{{ $invoice['labels']['customer_number'] }} {{ count($invoice['lines']) }}</p>
</body></html>
