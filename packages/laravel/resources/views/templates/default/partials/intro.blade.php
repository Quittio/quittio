{{-- texts->intro is pre-escaped HTML: Quittio escapes the text (and renders only safe Markdown with pdf.markdown). Print your own data with {{ }}, never with {!! !!}. --}}
@if ($invoice->texts->salutation)
    <p>{{ $invoice->texts->salutation }}</p>
@endif
@if ($invoice->texts->intro)
    <div>{!! $invoice->texts->intro !!}</div>
@endif
