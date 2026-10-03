{{-- Quotes, delivery notes, reminders and statements (specification 13). --}}
@if ($info)
    @if (count($info->packages) > 0)
        <div class="section">
            <div class="section-title">{{ $labels->packages }}</div>
            @foreach ($info->packages as $package)
                <div>{{ $package->id }}@if ($package->type) · {{ $package->type }}@endif @if ($package->weight) · {{ $labels->weight }} {{ $package->weight }}@endif @if ($package->dimensions) · {{ $package->dimensions }}@endif @if ($package->trackingNumber) · {{ $labels->tracking_number }} {{ $package->trackingNumber }}@endif</div>
            @endforeach
        </div>
    @endif

    @foreach (array_filter([$info->dunning, $info->statement]) as $ledger)
        <table class="lines" style="margin-top: 4mm;">
            <thead><tr>
                <th>{{ $labels->invoice_number }}</th>
                <th>{{ $labels->invoice_date }}</th>
                <th>{{ $labels->due_date }}</th>
                <th class="num">{{ $labels->invoice_amount }}</th>
                <th class="num">{{ $labels->paid }}</th>
                <th class="num">{{ $labels->open_amount }}</th>
            </tr></thead>
            <tbody>
            @foreach (($ledger->openItems ?? $ledger->items) as $item)
                <tr>
                    <td>{{ $item->invoiceNumber }}@if ($item->description)<div class="description">{{ $item->description }}</div>@endif</td>
                    <td>{{ $item->invoiceDate }}</td>
                    <td>{{ $item->dueDate }}</td>
                    <td class="num">{{ $item->amount }}</td>
                    <td class="num">{{ $item->paid }}</td>
                    <td class="num">{{ $item->open }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach

    @if ($info->dunning)
        <table class="totals">
            <tr><td>{{ $labels->open_items }}</td><td class="right">{{ $info->dunning->openTotal }}</td></tr>
            @foreach ($info->dunning->fees as $fee)
                <tr><td>{{ $fee->description }}</td><td class="right">{{ $fee->amount }}</td></tr>
            @endforeach
            @foreach ($info->dunning->interest as $interest)
                <tr><td>{{ $interest->description }}</td><td class="right">{{ $interest->amount }}</td></tr>
            @endforeach
            <tr class="due"><td>{{ $labels->dunning_total }}</td><td class="right">{{ $info->dunning->total }}</td></tr>
        </table>
        @if ($info->dunning->deadlineText)
            <p>{{ $info->dunning->deadlineText }}</p>
        @endif
    @endif

    @if ($info->statement)
        <table class="totals">
            <tr><td>{{ $labels->statement_period }}</td><td class="right">{{ $info->statement->period }}</td></tr>
            @if ($info->statement->openingBalance)
                <tr><td>{{ $labels->opening_balance }}</td><td class="right">{{ $info->statement->openingBalance }}</td></tr>
            @endif
            <tr class="due"><td>{{ $labels->balance }}</td><td class="right">{{ $info->statement->balance }}</td></tr>
        </table>
    @endif
@endif
