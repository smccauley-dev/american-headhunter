{{-- Landowner-facing payment picture: what the hunter has paid + the landowner's net. --}}
{{-- $s = LeaseFinanceSummaryService::landownerSummary(). All money is a formatted $-less string. --}}
@php
    $statusStyle = function (?string $status): array {
        return match ($status) {
            'collected', 'disbursed', 'released' => ['#15803d', '#f0fdf4'],
            'held', 'refunded'                   => ['#3d6b8e', '#eff6fb'],
            'partially_refunded', 'pending'      => ['#b05a00', '#fff7ed'],
            'forfeited'                          => ['#b91c1c', '#fef2f2'],
            default                              => ['#888', '#f5f5f5'],
        };
    };
    $pill = function (?string $status) use ($statusStyle): string {
        if (! $status) return '';
        [$clr, $bg] = $statusStyle($status);
        $label = strtoupper(str_replace('_', ' ', $status));
        return '<span style="display:inline-block;background:#fff;color:' . $clr . ';font-family:monospace;font-size:9px;font-weight:700;padding:3px 9px;border-radius:2px;text-transform:uppercase;letter-spacing:.08em;border:1px solid ' . $clr . '55">' . $label . '</span>';
    };
@endphp
<div style="font-family:system-ui,sans-serif">
    <p style="font-size:13px;line-height:1.5;color:#555;margin:0 0 16px;max-width:760px">
        What the hunter has paid and the landowner's net after the platform fee and processing surcharge. Lease income
        transfers to the landowner's connected payout account automatically; the security deposit is refundable
        collateral held separately.
    </p>

    {{-- Headline tiles --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px">
        @php
            $tiles = [
                ['Lease Total', '$' . $s['lease_total'], '#1a1a1a'],
                ['Paid to Date', '$' . $s['paid_to_date'], '#1a1a1a'],
                $s['fully_paid']
                    ? ['Balance', 'Paid in full', '#15803d']
                    : ['Outstanding', '$' . $s['outstanding'], '#b05a00'],
                ['Net Received to Landowner', '$' . $s['net_received'], '#15803d'],
            ];
        @endphp
        @foreach ($tiles as [$label, $value, $clr])
            <div style="padding:14px 16px;background:#fafaf9;border:1px solid #e5e0d8;border-radius:4px">
                <div style="font-family:monospace;font-size:9px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#888;margin-bottom:7px">{{ $label }}</div>
                <div style="font-size:20px;font-weight:600;color:{{ $clr }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- Booking deposit --}}
    @if (!empty($s['booking_deposit']))
        @php $bd = $s['booking_deposit']; @endphp
        <div style="background:#fafaf9;border:1px solid #e5e0d8;border-radius:4px;padding:12px 16px;margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                <span style="font-family:monospace;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#1a1a1a">Booking Deposit</span>
                {!! $pill($bd['status']) !!}
            </div>
            <div style="display:flex;gap:32px;font-size:13px;color:#1a1a1a">
                <span><span style="color:#888">Amount Paid:</span> ${{ $bd['amount'] }}</span>
                @if ($bd['net'] !== null)<span><span style="color:#888">Net to Landowner:</span> <span style="color:#15803d;font-weight:600">${{ $bd['net'] }}</span></span>@endif
                @if ($bd['collected_at'])<span><span style="color:#888">Collected:</span> {{ $bd['collected_at'] }}</span>@endif
            </div>
            @if (! $bd['paid'])
                <div style="font-family:monospace;font-size:10px;color:#b05a00;margin-top:8px">Not yet paid by the hunter.</div>
            @endif
        </div>
    @endif

    {{-- Lease-rent payments --}}
    @if (!empty($s['payments']))
        <div style="font-family:monospace;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#1a1a1a;margin-bottom:8px">Lease Payments</div>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:12px">
            @foreach ($s['payments'] as $p)
                <div style="display:grid;grid-template-columns:1fr auto auto auto;align-items:center;gap:18px;padding:10px 16px;background:#fafaf9;border:1px solid #e5e0d8;border-radius:4px">
                    <div style="display:flex;align-items:center;gap:10px;min-width:0">
                        {!! $pill($p['status']) !!}
                        <span style="font-family:monospace;font-size:10px;color:#888">{{ $p['paid_at'] ?? '—' }}</span>
                    </div>
                    <div style="text-align:right">
                        <div style="font-family:monospace;font-size:8px;letter-spacing:.1em;text-transform:uppercase;color:#a8a29e">Hunter Paid</div>
                        <div style="font-size:14px;color:#1a1a1a">${{ $p['amount'] }}</div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-family:monospace;font-size:8px;letter-spacing:.1em;text-transform:uppercase;color:#a8a29e">Platform Fee</div>
                        <div style="font-size:14px;color:#6b5e50">−${{ $p['fee'] }}</div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-family:monospace;font-size:8px;letter-spacing:.1em;text-transform:uppercase;color:#a8a29e">Net to Landowner</div>
                        <div style="font-size:14px;font-weight:600;color:#15803d">${{ $p['net'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Security deposit --}}
    @if (!empty($s['security_deposit']))
        @php $sd = $s['security_deposit']; @endphp
        <div style="background:#fafaf9;border:1px solid #e5e0d8;border-radius:4px;padding:12px 16px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                <span style="font-family:monospace;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#1a1a1a">Security Deposit</span>
                {!! $pill($sd['status']) !!}
            </div>
            <div style="display:flex;gap:32px;font-size:13px;color:#1a1a1a">
                <span><span style="color:#888">Held:</span> ${{ $sd['amount'] }}</span>
                <span><span style="color:#888">Refunded:</span> ${{ $sd['refunded'] }}</span>
                <span><span style="color:#888">Forfeited:</span> ${{ $sd['forfeited'] }}</span>
            </div>
            <div style="font-family:monospace;font-size:10px;color:#888;margin-top:8px;border-top:1px solid #e5e0d8;padding-top:8px">Refundable collateral — not lease income.</div>
        </div>
    @endif

    @if (empty($s['booking_deposit']) && empty($s['payments']) && empty($s['security_deposit']))
        <p style="font-family:monospace;font-size:11px;color:#888">No payments collected from the hunter yet.</p>
    @endif
</div>
