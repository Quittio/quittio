@if (count($invoice->notes) > 0)
<div class="section">
    <div class="section-title">{{ $labels->notes }}</div>
    @foreach ($invoice->notes as $note)
        <div>{!! $note->html !!}</div>
    @endforeach
</div>
@endif
