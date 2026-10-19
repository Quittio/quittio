{{-- Credit notes and negative invoices are refunds: no bank details for a transfer by the buyer. --}}
@php($accounts = $invoice->payment && ! $invoice->payment->isRefund && count($invoice->payment->accounts) > 0)
{{-- A direct debit collects from the buyer: not on refunds and not on payouts (self-billed). --}}
@php($debit = $invoice->payment && $invoice->payment->directDebit && ! $invoice->payment->isRefund && ! $invoice->payment->isPayout)
@php($terms = $invoice->paymentTerms || $debit)
@php($qr = $invoice->payment ? $invoice->payment->qrCode : null)
{{-- A code with a payment slip (Swiss QR bill) is printed on the slip at the end (partial payment-slip), not next to the bank details. --}}
@php($qr = $qr && $qr->slip ? null : $qr)
@if ($terms || $accounts)
<div class="section">
    {{-- Headings in a row of their own: mPDF ignores the padding of blocks inside table cells. --}}
    <table><tr>
        <td>
            <table class="payment">
                <tr>
                    @if ($terms)<td class="terms section-title">{{ $labels->payment }}</td>@endif
                    <td class="section-title">@if ($accounts){{ $invoice->payment->isPayout ? $labels->payout_account : $labels->bank_details }}@endif</td>
                </tr>
                <tr>
                    @if ($terms)
                        <td class="terms">
                            @if ($invoice->paymentTerms)
                                @foreach ($invoice->paymentTerms->lines as $termLine)
                                    <div class="{{ $loop->first ? '' : 'muted' }}">{{ $termLine }}</div>
                                @endforeach
                            @endif
                            @if ($qr && $qr->note)
                                <div class="muted">{{ $qr->note }}</div>
                            @endif
                            @if ($debit)
                                <div>{{ $labels->direct_debit }}</div>
                                @if ($invoice->payment->directDebit->mandate || $invoice->payment->directDebit->creditorId)
                                    <div class="muted">@if ($invoice->payment->directDebit->mandate){{ $labels->mandate }}: {{ $invoice->payment->directDebit->mandate }}@endif @if ($invoice->payment->directDebit->creditorId) · {{ $labels->creditor_id }}: {{ $invoice->payment->directDebit->creditorId }}@endif</div>
                                @endif
                            @endif
                        </td>
                    @endif
                    <td>
                        @if ($accounts)
                            @foreach ($invoice->payment->accounts as $account)
                                @if (! $loop->first)<div>&nbsp;</div>@endif
                                @if ($invoice->payee)<div>{{ $labels->payee }}: {{ $invoice->payee->name }}</div>@endif
                                {{-- The account holder only if it is not the issuer named above. --}}
                                @if ($account->name && $account->name !== $invoice->issuer->name && $account->name !== $invoice->issuer->displayName)<div>{{ $account->name }}</div>@endif
                                <div>{{ $account->iban }}</div>
                                <div class="muted">@if ($account->bic){{ $account->bic }} · @endif{{ $labels->remittance_short }}: {{ $invoice->payment->remittance }}</div>
                            @endforeach
                        @endif
                    </td>
                </tr>
            </table>
        </td>
        @if ($qr)
            {{-- The note on a discounted amount is printed with the payment terms. --}}
            <td class="qr">
                <img src="{{ $qr->dataUri }}" style="width: 17mm; height: 17mm;">
                <div class="qr-caption">{{ $labels->girocode }}</div>
            </td>
        @endif
    </tr></table>
</div>
@endif
