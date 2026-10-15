{{-- Logo, or the issuer's name, at the top left of the first page. --}}
<table class="letterhead"><tr>
    <td>
        @if ($design->logo)
            <img src="{{ $design->logo }}" style="max-height: {{ $design->logoMaxHeight }}mm; max-width: 60mm;">
        @else
            <div class="company">{{ $invoice->issuer->displayName }}</div>
        @endif
        @if (! $design->footerHasAddress)
            <div class="issuer-address">@foreach ($invoice->issuer->address->lines as $line){{ $line }}<br>@endforeach</div>
        @endif
    </td>
</tr></table>
