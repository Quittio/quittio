{{-- texts->closing is pre-escaped HTML: Quittio escapes the text (and renders only safe Markdown with pdf.markdown). Print your own data with {{ }}, never with {!! !!}. --}}
@if ($invoice->texts->closing)
    <div class="section">{!! $invoice->texts->closing !!}</div>
@endif

@if ($info && $info->showAcceptance)
<div class="section">
    <div class="section-title">{{ $labels->acceptance }}</div>
    <table style="margin-top: 12mm; width: 80mm;"><tr><td style="border-top: 0.2mm solid {{ $design->mutedColor }};" class="small muted">{{ $labels->acceptance_signature }}</td></tr></table>
</div>
@endif
