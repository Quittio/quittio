@if (count($invoice->lines) > 0)
{{-- Fixed column widths: the PDF renderer splits the table at page breaks and inserts the carry-over. --}}
@php($descriptionWidth = $prices ? ($vatColumn ? 65.9 : 79.9) : 135.9)
<table class="lines">
    <thead><tr>
        <th style="width: 11.1mm;">{{ $labels->position }}</th>
        <th style="width: {{ $descriptionWidth }}mm;">{{ $labels->description }}</th>
        <th class="num" style="width: 24mm;">{{ $labels->quantity }}</th>
        @if ($prices)
            <th class="num" style="width: 27mm;">{{ $labels->unit_price }}</th>
            @if ($vatColumn)
                <th class="num" style="width: 14mm;">{{ $labels->vat_rate }}</th>
            @endif
            <th class="num" style="width: 29mm;">{{ $labels->total }}</th>
        @endif
    </tr></thead>
    <tbody>
    <!--quittio:rows-->
    @foreach ($invoice->lineGroups as $group)
        @if ($group->title)
            <tr class="group"><td colspan="{{ $columns }}">{{ $group->title }}</td></tr>
        @endif
        @foreach ($group->lines as $line)
            <tr class="line{{ $line->highlight ? ' highlight' : '' }}"@if ($prices) data-carry-over="{{ $line->carryOver }}"@endif>
                <td class="position">{{ $line->position }}</td>
                <td>
                    <div class="name">@if ($line->optional)<span class="muted">[{{ $labels->optional_line }}]</span> @endif @if ($line->alternativeTo)<span class="muted">[{{ $invoice->label('alternative_line', ['position' => (string) $line->alternativeTo]) }}]</span> @endif{{ $line->name }}@if ($design->showItemNumbers && $line->sellerId)<span class="muted"> · {{ $labels->item_number }} {{ $line->sellerId }}</span>@endif</div>
                    @if ($line->description->html)<div class="description">{!! $line->description->html !!}</div>@endif
                    @if ($line->period)<div class="description">{{ $labels->service_period }} {{ $line->period }}</div>@endif
                    @foreach ($line->attributes as $attribute)<div class="description">{{ $attribute->name }}: {{ $attribute->value }}</div>@endforeach
                    @foreach ($line->allowances as $allowance)<div class="description">{{ $allowance->label }}: −{{ $allowance->amount }}</div>@endforeach
                    @foreach ($line->charges as $charge)<div class="description">{{ $charge->label }}: {{ $charge->amount }}</div>@endforeach
                    @if ($line->note)<div class="description">{{ $line->note }}</div>@endif
                    @if (count($line->serialNumbers) > 0)<div class="description">{{ $labels->serial_numbers }}: {{ implode(', ', $line->serialNumbers->toArray()) }}</div>@endif
                </td>
                <td class="num">{{ $line->quantity }} {{ $line->unit->label }}</td>
                @if ($prices)
                    <td class="num">{{ $line->unitPrice }}@if ($line->unitPriceBase)<div class="description">/ {{ $line->unitPriceBase }}</div>@endif @if ($line->showGrossPrice)<div class="description">{{ $labels->unit_price_gross }} {{ $line->grossUnitPrice }}</div>@endif</td>
                    @if ($vatColumn)
                        <td class="num">{{ $line->vatRate }}</td>
                    @endif
                    <td class="num amount">{{ $line->total }}</td>
                @endif
            </tr>
            @foreach ($line->subLines as $sub)
                <tr class="sub">
                    <td>{{ $sub->position }}</td>
                    <td>{{ $sub->name }}</td>
                    <td class="num">{{ $sub->quantity }} {{ $sub->unit->label }}</td>
                    @if ($prices)
                        <td class="num">{{ $sub->unitPrice }}@if ($sub->unitPriceBase)<div class="description">/ {{ $sub->unitPriceBase }}</div>@endif</td>
                        @if ($vatColumn)
                            <td class="num">{{ $sub->vatRate }}</td>
                        @endif
                        <td class="num">{{ $sub->total }}</td>
                    @endif
                </tr>
            @endforeach
        @endforeach
        @if ($prices && $group->subtotal)
            <tr class="subtotal"><td></td><td colspan="{{ $columns - 2 }}" class="right">{{ $labels->subtotal }}</td><td class="num amount">{{ $group->subtotal }}</td></tr>
        @endif
    @endforeach
    <!--/quittio:rows-->
    </tbody>
</table>
@if ($prices)
{{-- Rows the PDF renderer inserts where the table continues on the next page; {page} and {amount} are filled in. --}}
<!--quittio:carry-out <tr class="carry carry-out"><td colspan="2">{{ $invoice->label('continued_on', ['page' => '{page}']) }}</td><td colspan="{{ $columns - 3 }}" style="padding-left: 5.8mm;">{{ $labels->carry_over }}</td><td class="num carry-amount">{amount}</td></tr> -->
<!--quittio:carry-in <tr class="carry carry-in"><td></td><td colspan="{{ $columns - 2 }}">{{ $invoice->label('carry_over_from', ['page' => '{page}']) }}</td><td class="num">{amount}</td></tr> -->
@endif
@endif
