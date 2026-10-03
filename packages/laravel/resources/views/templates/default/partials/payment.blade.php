@if ($invoice->paymentTerms || $invoice->payment)
@php($gap = '')
<div class="section">
    <table><tr>
        <td>
            {{-- Cell padding instead of block margins, which mPDF ignores inside table cells. --}}
            <table class="stack">
            @if ($invoice->paymentTerms)
                <tr><td class="{{ $gap }}">
                    <div class="section-title">{{ $labels->payment_terms }}</div>
                    @foreach ($invoice->paymentTerms->lines as $termLine)
                        <div>{{ $termLine }}</div>
                    @endforeach
                </td></tr>
                @php($gap = 'gap')
            @endif
            @if ($invoice->payment)
                @foreach ($invoice->payment->accounts as $account)
                    <tr><td class="{{ $gap }}">
                        <div class="section-title">{{ $labels->bank_details }}</div>
                        @if ($account->name){{ $labels->account_holder }}: {{ $account->name }}<br>@endif
                        {{ $labels->iban }}: {{ $account->iban }}<br>
                        @if ($account->bic){{ $labels->bic }}: {{ $account->bic }}<br>@endif
                        {{ $labels->remittance }}: {{ $invoice->payment->remittance }}
                    </td></tr>
                    @php($gap = 'gap')
                @endforeach
                @if ($invoice->payment->directDebit)
                    <tr><td class="{{ $gap }}">{{ $labels->direct_debit }}
                        @if ($invoice->payment->directDebit->mandate){{ $labels->mandate }}: {{ $invoice->payment->directDebit->mandate }}@endif
                        @if ($invoice->payment->directDebit->creditorId) · {{ $labels->creditor_id }}: {{ $invoice->payment->directDebit->creditorId }}@endif
                    </td></tr>
                @endif
            @endif
            </table>
        </td>
        @if ($invoice->payment && $invoice->payment->qrCode)
            <td style="width: 32mm;" class="right">
                <img src="{{ $invoice->payment->qrCode->dataUri }}" style="width: 28mm; height: 28mm;"><br>
                <span class="small muted">{{ $labels->girocode }}</span>
            </td>
        @endif
    </tr></table>
</div>
@endif
