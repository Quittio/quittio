<table class="letterhead"><tr>
    <td>&nbsp;</td>
    <td class="right">
        @if ($design->logo)
            <img src="{{ $design->logo }}" style="max-height: 20mm; max-width: 60mm;"><br>
        @endif
        <span class="company">{{ $invoice->issuer->displayName }}</span>
        @if (! $design->footerHasAddress)
            <div class="small muted">@foreach ($invoice->issuer->address->lines as $line){{ $line }}<br>@endforeach</div>
        @endif
    </td>
</tr></table>
