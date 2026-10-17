{{-- The output of legacy.blade.php, written against the typed view model (Quittio\Core\View\Model). --}}
@php
    /** @var \Quittio\Core\View\InvoiceViewModel $invoice */
    $party = static fn (\Quittio\Core\View\Model\PartyView $party): \Quittio\Core\View\Model\PartyView => $party;
    $line = static fn (\Quittio\Core\View\Model\LineView $line): \Quittio\Core\View\Model\LineView => $line;
@endphp
<html><body>
<h1>{{ $invoice->title }} {{ $invoice->number }}</h1>
<p>{{ $invoice->dateLabel }} {{ $invoice->issueDate }} @if ($invoice->servicePeriod) · {{ $invoice->servicePeriodLabel }} {{ $invoice->servicePeriod }} @endif</p>
@foreach ([$party($invoice->issuer), $party($invoice->recipient)] as $role)
    <address>@foreach ($role->address->lines as $text){{ $text }}<br>@endforeach @if ($role->vatId){{ $role->vatId }}@endif @if ($role->taxNumber){{ $role->taxNumber }}@endif</address>
@endforeach
@foreach ($invoice->precedingInvoices as $preceding)<p>{{ $preceding->number }} {{ $preceding->issueDate }}</p>@endforeach
<table>
@foreach ($invoice->lineGroups as $group)
    @foreach ($group->lines as $entry)
        @php($entry = $line($entry))
        <tr data-carry-over="{{ $entry->carryOver }}"><td>{{ $entry->position }}</td><td>{{ $entry->name }}</td><td>{{ $entry->quantity }} {{ $entry->unit->label }}</td><td>{{ $entry->total }}</td></tr>
        @foreach ($entry->subLines as $sub)<tr><td>{{ $sub->position }}</td><td>{{ $sub->name }}</td><td>{{ $sub->quantity }} {{ $sub->unit->label }}</td></tr>@endforeach
    @endforeach
@endforeach
</table>
@if ($invoice->totals)
    @foreach ($invoice->vatRows as $row)<p>{{ $row->label }} {{ $row->amount }}</p>@endforeach
    <p>{{ $invoice->totals->amountDueLabel }} {{ $invoice->totals->amountDue }}</p>
@endif
@foreach ($invoice->legalNotes as $note)<p>{{ $note }}</p>@endforeach
@if ($invoice->paymentTerms)@foreach ($invoice->paymentTerms->lines as $text)<p>{{ $text }}</p>@endforeach @endif
@if ($invoice->payment)@foreach ($invoice->payment->accounts as $account)<p>{{ $account->iban }}</p>@endforeach @endif
<footer>@foreach ($invoice->design->footerColumns as $column)<div>{{ implode(' · ', $column->toArray()) }}</div>@endforeach</footer>
<p>{{ $invoice->labels['customer_number'] }} {{ $invoice->lines->count() }}</p>
</body></html>
