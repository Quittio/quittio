<htmlpagefooter name="quittioFooter">
    <table class="footer"><tr>
        @foreach ($design->footerColumns as $column)
            <td>@foreach ($column as $line){{ $line }}<br>@endforeach</td>
        @endforeach
    </tr></table>
    <div class="small muted right">{{ $labels->page }} {PAGENO} {{ $labels->of }} {nbpg}</div>
</htmlpagefooter>
