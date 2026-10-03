@if (count($invoice->legalNotes) > 0)
<div class="section">
    @foreach ($invoice->legalNotes as $note)
        <div>{{ $note }}</div>
    @endforeach
</div>
@endif
