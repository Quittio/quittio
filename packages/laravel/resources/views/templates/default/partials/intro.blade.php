@if ($invoice->texts->salutation)
    <p>{{ $invoice->texts->salutation }}</p>
@endif
@if ($invoice->texts->intro)
    <div>{!! $invoice->texts->intro !!}</div>
@endif
