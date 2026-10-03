@foreach ($invoice->buyer->address->lines as $line)
    {{ $line }}<br>
@endforeach
