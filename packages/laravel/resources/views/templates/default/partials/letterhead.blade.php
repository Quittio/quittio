<table class="letterhead"><tr>
    <td>&nbsp;</td>
    <td class="right">
        @if ($design->logo)
            <img src="{{ $design->logo }}" style="max-height: 20mm; max-width: 60mm;"><br>
        @endif
        <span class="company">{{ $invoice->seller->displayName }}</span>
    </td>
</tr></table>
