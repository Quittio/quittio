{{-- The buyer; on a self-billed invoice (Gutschrift) the buyer issues it to the seller. --}}
@foreach ($invoice->recipient->address->lines as $line)
    {{ $line }}<br>
@endforeach
