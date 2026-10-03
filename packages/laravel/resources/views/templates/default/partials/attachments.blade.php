@if (count($invoice->attachments) > 0)
<div class="section">
    <div class="section-title">{{ $labels->attachments }}</div>
    @foreach ($invoice->attachments as $attachment)
        <div>{{ $attachment->id }}@if ($attachment->description) – {{ $attachment->description }}@endif</div>
    @endforeach
</div>
@endif
