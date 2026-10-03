<table class="meta">
    <tr><td class="label">{{ $invoice->numberLabel }}</td><td class="right">{{ $invoice->number }}</td></tr>
    <tr><td class="label">{{ $invoice->dateLabel }}</td><td class="right">{{ $invoice->issueDate }}</td></tr>
    @if ($info && $info->validUntil)
        <tr><td class="label">{{ $labels->valid_until }}</td><td class="right">{{ $info->validUntil }}</td></tr>
    @endif
    @if ($info && $info->reference)
        <tr><td class="label">{{ $labels->reference_document }}</td><td class="right">{{ $info->reference }}@if ($info->referenceDate) / {{ $info->referenceDate }}@endif</td></tr>
    @endif
    @if ($info && $info->deliveryDate)
        <tr><td class="label">{{ $labels->delivery_date }}</td><td class="right">{{ $info->deliveryDate }}</td></tr>
    @endif
    @if ($invoice->servicePeriod)
        <tr><td class="label">{{ $labels->service_period }}</td><td class="right">{{ $invoice->servicePeriod }}</td></tr>
    @endif
    @if ($invoice->buyer->customerNumber)
        <tr><td class="label">{{ $labels->customer_number }}</td><td class="right">{{ $invoice->buyer->customerNumber }}</td></tr>
    @endif
    @foreach ($invoice->references as $reference)
        <tr><td class="label">{{ $reference->label }}</td><td class="right">{{ $reference->value }}</td></tr>
    @endforeach
    @if ($invoice->seller->vatId)
        <tr><td class="label">{{ $labels->vat_id }}</td><td class="right">{{ $invoice->seller->vatId }}</td></tr>
    @endif
    @if ($invoice->seller->taxNumber)
        <tr><td class="label">{{ $labels->tax_number }}</td><td class="right">{{ $invoice->seller->taxNumber }}</td></tr>
    @endif
    @if ($invoice->buyer->vatId)
        <tr><td class="label">{{ $labels->buyer }} {{ $labels->vat_id }}</td><td class="right">{{ $invoice->buyer->vatId }}</td></tr>
    @endif
</table>
