@foreach ($invoice->precedingInvoices as $preceding)
    <p>{{ $invoice->label('preceding_invoice_text', ['number' => (string) $preceding->number, 'date' => (string) $preceding->issueDate]) }}</p>
@endforeach
