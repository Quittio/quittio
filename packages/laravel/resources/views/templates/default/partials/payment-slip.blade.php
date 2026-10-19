{{-- Payment slip of the payment QR code (ProvidesPaymentSlip, e.g. the Swiss QR bill): the bottom 105 mm of a page of its own, without footer. --}}
@php($slip = $invoice->payment && $invoice->payment->qrCode ? $invoice->payment->qrCode->slip : null)
@if ($slip)
@php($top = $design->paper === 'Letter' ? 174.4 : 192.0)
<pagebreak page-selector="slip" />
<div class="slip" style="top: {{ $top - 4 }}mm; left: 0; width: 210mm; text-align: center; font-size: 7pt;">{{ $slip->separationNote }}</div>
<div class="slip" style="top: {{ $top }}mm; left: 0; width: 210mm; height: 0.2mm; border-top: 0.2mm dashed #000;"></div>
<div class="slip" style="top: {{ $top }}mm; left: 62mm; width: 0.2mm; height: 105mm; border-left: 0.2mm dashed #000;"></div>
<div class="slip receipt" style="top: {{ $top + 5 }}mm; left: 5mm; width: 52mm; height: 56mm;">
    <div class="slip-title">{{ $slip->receiptTitle }}</div>
    @foreach ($slip->receiptSections as $section)
        <div class="slip-heading">{{ $section->heading }}</div>
        @if (count($section->lines) === 0)<div class="slip-field" style="width: 52mm; height: 20mm;"></div>@endif
        @foreach ($section->lines as $line)<div class="slip-value">{{ $line }}</div>@endforeach
    @endforeach
</div>
<div class="slip receipt" style="top: {{ $top + 68 }}mm; left: 5mm; width: 52mm;">
    <table><tr>
        <td style="width: 16mm;"><div class="slip-heading" style="padding-top: 0;">{{ $slip->currencyHeading }}</div><div class="slip-value">{{ $slip->currency }}</div></td>
        <td><div class="slip-heading" style="padding-top: 0;">{{ $slip->amountHeading }}</div>@if ($slip->amount)<div class="slip-value">{{ $slip->amount }}</div>@else<div class="slip-field" style="width: 30mm; height: 10mm;"></div>@endif</td>
    </tr></table>
</div>
<div class="slip receipt" style="top: {{ $top + 82 }}mm; left: 5mm; width: 52mm; text-align: right;"><div class="slip-heading" style="padding-top: 0;">{{ $slip->acceptancePoint }}</div></div>
<div class="slip" style="top: {{ $top + 5 }}mm; left: 67mm; width: 51mm;"><div class="slip-title">{{ $slip->title }}</div></div>
<div class="slip" style="top: {{ $top + 17 }}mm; left: 67mm; width: 46mm; height: 46mm;"><img src="{{ $slip->dataUri }}" style="width: 46mm; height: 46mm;"></div>
@if ($slip->mark)<div class="slip" style="top: {{ $top + 36.5 }}mm; left: 86.5mm; width: 7mm; height: 7mm;"><img src="{{ $slip->mark }}" style="width: 7mm; height: 7mm;"></div>@endif
<div class="slip" style="top: {{ $top + 68 }}mm; left: 67mm; width: 51mm;">
    <table><tr>
        <td style="width: 16mm;"><div class="slip-heading" style="padding-top: 0;">{{ $slip->currencyHeading }}</div><div class="slip-value">{{ $slip->currency }}</div></td>
        <td><div class="slip-heading" style="padding-top: 0;">{{ $slip->amountHeading }}</div>@if ($slip->amount)<div class="slip-value">{{ $slip->amount }}</div>@else<div class="slip-field" style="width: 40mm; height: 15mm;"></div>@endif</td>
    </tr></table>
</div>
<div class="slip" style="top: {{ $top + 5 }}mm; left: 118mm; width: 87mm; height: 85mm;">
    @foreach ($slip->sections as $i => $section)
        <div class="slip-heading" @if ($i === 0) style="padding-top: 0;" @endif>{{ $section->heading }}</div>
        @if (count($section->lines) === 0)<div class="slip-field" style="width: 65mm; height: 25mm;"></div>@endif
        @foreach ($section->lines as $line)<div class="slip-value">{{ $line }}</div>@endforeach
    @endforeach
</div>
@endif
