<htmlpagefooter name="quittioNoFooter"></htmlpagefooter>
<htmlpagefooter name="quittioFooter">
    @if (count($design->footerColumns) > 0)
        <div class="footer-rule"><table class="footer"><tr>
            @if (count($design->footerColumns) <= 4)
                {{-- Up to four columns at a fixed pitch of 43.8 mm. --}}
                @foreach ([43.8, 43.8, 43.8, 39.6] as $i => $width)
                    <td style="width: {{ $width }}mm;">@if (isset($design->footerColumns[$i]))@foreach ($design->footerColumns[$i] as $line){{ $line }}<br>@endforeach @endif</td>
                @endforeach
            @else
                @foreach ($design->footerColumns as $column)
                    <td>@foreach ($column as $line){{ $line }}<br>@endforeach</td>
                @endforeach
            @endif
        </tr></table></div>
    @endif
    <div class="page-number">{{ $labels->page }} {PAGENO} {{ $labels->of }} {nbpg}</div>
</htmlpagefooter>
