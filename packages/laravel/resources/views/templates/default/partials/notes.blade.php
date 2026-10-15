{{-- $note->html is pre-escaped HTML: Quittio escapes the note (and renders only safe Markdown with pdf.markdown). Print your own data with {{ }}, never with {!! !!}. --}}
@if (count($invoice->notes) > 0)
<div class="section">
    <div class="section-title">{{ $labels->notes }}</div>
    @foreach ($invoice->notes as $note)
        <div>{!! $note->html !!}</div>
    @endforeach
</div>
@endif
