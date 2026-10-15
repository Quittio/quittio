@php($selfBilled = $doc->isSelfBilled)
<table class="meta">
    <tr><td class="label">{{ $invoice->numberLabel }}</td><td class="value">{{ $invoice->number }}</td></tr>
    <tr><td class="label">{{ $invoice->dateLabel }}</td><td class="value">{{ $invoice->issueDate }}</td></tr>
    @if ($info && $info->validUntil)
        <tr><td class="label">{{ $labels->valid_until }}</td><td class="value">{{ $info->validUntil }}</td></tr>
    @endif
    @if ($info && $info->reference)
        <tr><td class="label">{{ $labels->reference_document }}</td><td class="value">{{ $info->reference }}@if ($info->referenceDate) / {{ $info->referenceDate }}@endif</td></tr>
    @endif
    @if ($info && $info->deliveryDate)
        <tr><td class="label">{{ $labels->delivery_date }}</td><td class="value">{{ $info->deliveryDate }}</td></tr>
    @endif
    @if ($invoice->servicePeriod)
        <tr><td class="label">{{ $invoice->servicePeriodLabel }}</td><td class="value">{{ $invoice->servicePeriod }}</td></tr>
    @endif
    @if ($invoice->deliveryDate)
        <tr><td class="label">{{ $labels->delivery_date }}</td><td class="value">{{ $invoice->deliveryDate }}</td></tr>
    @endif
    @if ($invoice->buyer->customerNumber)
        <tr><td class="label">{{ $labels->customer_number }}</td><td class="value">{{ $invoice->buyer->customerNumber }}</td></tr>
    @endif
    @foreach ($invoice->references as $reference)
        <tr><td class="label">{{ $reference->label }}</td><td class="value">{{ $reference->value }}</td></tr>
    @endforeach
    {{-- The issuer's tax identifiers are in the footer; here only if the footer does not show them. --}}
    @if (! $design->footerHasTaxIds)
        @if ($invoice->issuer->vatId)
            <tr><td class="label">{{ $labels->vat_id }}</td><td class="value">{{ $invoice->issuer->vatId }}</td></tr>
        @endif
        @if (! $selfBilled && $invoice->seller->taxNumber)
            <tr><td class="label">{{ $labels->tax_number }}</td><td class="value">{{ $invoice->seller->taxNumber }}</td></tr>
        @endif
    @endif
    {{-- The recipient's identifiers; on a self-billed invoice (Gutschrift) those of the seller. --}}
    @if ($invoice->recipient->vatId)
        <tr><td class="label">{{ $labels->your_vat_id }}</td><td class="value">{{ $invoice->recipient->vatId }}</td></tr>
    @endif
    @if ($selfBilled && $invoice->seller->taxNumber)
        <tr><td class="label">{{ $labels->your_tax_number }}</td><td class="value">{{ $invoice->seller->taxNumber }}</td></tr>
    @endif
    @if ($invoice->taxRepresentative)
        <tr><td class="label">{{ $labels->tax_representative }}</td><td class="value">@foreach ($invoice->taxRepresentative->address->lines as $line){{ $line }}<br>@endforeach{{ $labels->vat_id }} {{ $invoice->taxRepresentative->vatId }}</td></tr>
    @endif
    @if ($invoice->delivery && $invoice->delivery->address)
        <tr><td class="label">{{ $labels->deliver_to }}</td><td class="value">@foreach ($invoice->delivery->address->lines as $line){{ $line }}<br>@endforeach</td></tr>
    @endif
</table>
