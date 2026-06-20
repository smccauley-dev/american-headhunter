{{-- Stripe invoice history. $invoices = StripeService::listInvoices() rows.
     Each row links out to Stripe's hosted invoice page + PDF (source of truth). --}}
@php
    $ths = 'text-align:left;font-size:0.72rem;font-weight:600;text-transform:uppercase;'
         . 'letter-spacing:0.05em;color:#6b7280;padding:0.4rem 0.75rem;'
         . 'border-bottom:2px solid #e5e7eb;white-space:nowrap;';
    $tds = 'padding:0.55rem 0.75rem;border-bottom:1px solid #f3f4f6;font-size:0.85rem;color:#374151;';
    $statusColors = [
        'paid'          => ['#065f46', '#d1fae5'],
        'open'          => ['#92400e', '#fef3c7'],
        'void'          => ['#6b7280', '#f3f4f6'],
        'uncollectible' => ['#991b1b', '#fee2e2'],
        'draft'         => ['#374151', '#f3f4f6'],
    ];
@endphp
<table style="width:100%;border-collapse:collapse;">
    <thead>
        <tr>
            <th style="{{ $ths }}">Invoice</th>
            <th style="{{ $ths }}">Date</th>
            <th style="{{ $ths }}">Amount</th>
            <th style="{{ $ths }}">Status</th>
            <th style="{{ $ths }}">Refund</th>
            <th style="{{ $ths }}">Links</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoices as $inv)
            @php [$ic, $ibg] = $statusColors[$inv['status']] ?? ['#374151', '#f3f4f6']; @endphp
            <tr>
                <td style="{{ $tds }}font-family:monospace;font-size:0.8rem;">{{ $inv['number'] ?? '—' }}</td>
                <td style="{{ $tds }}white-space:nowrap;color:#6b7280;">{{ $inv['date'] ?? '—' }}</td>
                <td style="{{ $tds }}white-space:nowrap;">${{ $inv['amount'] }} {{ $inv['currency'] }}</td>
                <td style="{{ $tds }}">
                    <span style="display:inline-block;padding:0.1rem 0.5rem;border-radius:9999px;font-size:0.68rem;font-weight:600;text-transform:uppercase;color:{{ $ic }};background:{{ $ibg }};">
                        {{ $inv['status'] ?? '—' }}
                    </span>
                </td>
                <td style="{{ $tds }}white-space:nowrap;">
                    @php $rs = $inv['refund_status'] ?? 'none'; @endphp
                    @if ($rs === 'none')
                        <span style="color:#9ca3af;">—</span>
                    @else
                        @php [$rc, $rbg] = $rs === 'full' ? ['#991b1b', '#fee2e2'] : ['#92400e', '#fef3c7']; @endphp
                        <span style="display:inline-block;padding:0.1rem 0.5rem;border-radius:9999px;font-size:0.68rem;font-weight:600;text-transform:uppercase;color:{{ $rc }};background:{{ $rbg }};">
                            {{ $rs === 'full' ? 'Refunded' : 'Partial' }}
                        </span>
                        <span style="color:#6b7280;font-size:0.78rem;margin-left:0.35rem;">${{ $inv['refunded'] }} {{ $inv['currency'] }}</span>
                    @endif
                </td>
                <td style="{{ $tds }}white-space:nowrap;">
                    @if (! empty($inv['hosted_url']))
                        <a href="{{ $inv['hosted_url'] }}" target="_blank" rel="noopener noreferrer" style="color:#1d4ed8;text-decoration:none;font-size:0.8rem;">View ↗</a>
                    @endif
                    @if (! empty($inv['pdf_url']))
                        <a href="{{ $inv['pdf_url'] }}" target="_blank" rel="noopener noreferrer" style="color:#1d4ed8;text-decoration:none;font-size:0.8rem;margin-left:0.6rem;">PDF ↗</a>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
