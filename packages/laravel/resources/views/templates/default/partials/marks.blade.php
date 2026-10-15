{{-- Fold and punch marks of the letter layout (e.g. DIN 5008) at the left edge, enabled with quittio.pdf.fold_marks. --}}
@foreach ($design->foldMarks as $mark)
    <div class="mark" style="top: {{ $mark }}mm;"></div>
@endforeach
@if ($design->punchMark)
    <div class="mark" style="top: {{ $design->punchMark }}mm; width: 6.3mm;"></div>
@endif
