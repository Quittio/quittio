@if (count($invoice->lines) > 0)
<table class="lines">
    <thead><tr>
        <th style="width: 10mm;">{{ $labels->position }}</th>
        <th>{{ $labels->description }}</th>
        <th class="num">{{ $labels->quantity }}</th>
        @if ($prices)
            <th class="num">{{ $labels->unit_price }}</th>
            <th class="num">{{ $labels->vat_rate }}</th>
            <th class="num">{{ $labels->total }}</th>
        @endif
    </tr></thead>
    <tbody>
    @foreach ($invoice->lineGroups as $group)
        @if ($group->title)
            <tr class="group"><td colspan="{{ $columns }}">{{ $group->title }}</td></tr>
        @endif
        @foreach ($group->lines as $line)
            <tr @if ($line->highlight) class="highlight" @endif>
                <td class="position">{{ $line->position }}</td>
                <td>
                    @if ($line->optional)<span class="muted">[{{ $labels->optional_line }}]</span> @endif
                    @if ($line->alternativeTo)<span class="muted">[{{ $invoice->label('alternative_line', ['position' => (string) $line->alternativeTo]) }}]</span> @endif
                    <strong>{{ $line->name }}</strong>
                    @if ($design->showItemNumbers && $line->sellerId)<span class="muted"> · {{ $labels->item_number }} {{ $line->sellerId }}</span>@endif
                    @if ($line->description->html)<div class="description">{!! $line->description->html !!}</div>@endif
                    @if ($line->period)<div class="description">{{ $labels->service_period }}: {{ $line->period }}</div>@endif
                    @foreach ($line->attributes as $attribute)<div class="description">{{ $attribute->name }}: {{ $attribute->value }}</div>@endforeach
                    @foreach ($line->allowances as $allowance)<div class="description">{{ $allowance->label }}: −{{ $allowance->amount }}</div>@endforeach
                    @foreach ($line->charges as $charge)<div class="description">{{ $charge->label }}: {{ $charge->amount }}</div>@endforeach
                    @if ($line->note)<div class="description">{{ $line->note }}</div>@endif
                    @if (count($line->serialNumbers) > 0)<div class="description">{{ $labels->serial_numbers }}: {{ implode(', ', $line->serialNumbers->toArray()) }}</div>@endif
                </td>
                <td class="num">{{ $line->quantity }} {{ $line->unit->label }}</td>
                @if ($prices)
                    <td class="num">{{ $line->unitPrice }}@if ($line->unitPriceBase) / {{ $line->unitPriceBase }}@endif @if ($line->showGrossPrice)<div class="description">{{ $labels->unit_price_gross }} {{ $line->grossUnitPrice }}</div>@endif</td>
                    <td class="num">{{ $line->vatRate }}</td>
                    <td class="num">{{ $line->total }}</td>
                @endif
            </tr>
            @foreach ($line->subLines as $sub)
                <tr class="sub">
                    <td>{{ $sub->position }}</td>
                    <td>{{ $sub->name }}</td>
                    <td class="num">{{ $sub->quantity }} {{ $sub->unit->label }}</td>
                    @if ($prices)
                        <td class="num">{{ $sub->unitPrice }}@if ($sub->unitPriceBase) / {{ $sub->unitPriceBase }}@endif</td>
                        <td class="num">{{ $sub->vatRate }}</td>
                        <td class="num">{{ $sub->total }}</td>
                    @endif
                </tr>
            @endforeach
        @endforeach
        @if ($prices && $group->subtotal)
            <tr><td></td><td colspan="4" class="right muted">{{ $labels->subtotal }}</td><td class="num">{{ $group->subtotal }}</td></tr>
        @endif
    @endforeach
    </tbody>
</table>
@endif
