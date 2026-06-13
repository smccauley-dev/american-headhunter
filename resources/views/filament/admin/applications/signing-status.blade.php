{{-- Lease summary card + signer status rows: $lease, $signers (collection|null), $signingUrl --}}
@php
    $statusColor = match ($lease->status) {
        'active'             => '#15803d',
        'pending_signatures' => '#b05a00',
        'expired'            => '#888',
        'terminated'         => '#b91c1c',
        'cancelled'          => '#b91c1c',
        default              => '#555',
    };
    $statusBg = match ($lease->status) {
        'active'             => '#f0fdf4',
        'pending_signatures' => '#fff7ed',
        'expired'            => '#f5f5f5',
        'terminated'         => '#fef2f2',
        'cancelled'          => '#fef2f2',
        default              => '#f5f5f5',
    };
@endphp
<div style="font-family:system-ui,sans-serif">
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;padding:16px;background:#fafaf9;border:1px solid #e5e0d8;border-radius:4px;margin-bottom:16px">
        <div>
            <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Lease ID</div>
            <div style="font-family:monospace;font-size:13px;color:#1a1a1a">AH-{{ strtoupper(substr($lease->id, 0, 8)) }}</div>
        </div>
        <div>
            <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Status</div>
            <div style="display:inline-block;background:{{ $statusBg }};color:{{ $statusColor }};font-family:monospace;font-size:10px;font-weight:700;padding:3px 10px;border-radius:2px;text-transform:uppercase;letter-spacing:.08em">{{ str_replace('_', ' ', strtoupper($lease->status)) }}</div>
        </div>
        <div>
            <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Lease Term</div>
            <div style="font-size:13px;color:#1a1a1a">{{ $lease->start_date?->format('M j, Y') ?? '—' }} – {{ $lease->end_date?->format('M j, Y') ?? '—' }}</div>
        </div>
        <div>
            <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Total Price</div>
            <div style="font-size:13px;font-weight:600;color:#1a1a1a">${{ number_format((float) $lease->total_price, 2) }}</div>
        </div>
    </div>
    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:8px">Signature Status</div>
    @if ($signers === null || $signers->isEmpty())
        <p style="color:#888;font-style:italic;font-size:13px">No signing request found.</p>
    @else
        <div style="margin-top:8px">
            @foreach ($signers as $signer)
                @php
                    $isSigned  = $signer->status === 'signed';
                    $role      = $signer->user_id === $lease->lessor_user_id ? 'Lessor (Landowner)' : 'Lessee (Hunter)';
                    $statusClr = $isSigned ? '#15803d' : '#b05a00';
                @endphp
                <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:{{ $isSigned ? '#f0fdf4' : '#fff7ed' }};border-radius:4px;margin-bottom:6px">
                    <span style="font-size:16px;font-weight:700;color:{{ $statusClr }}">{{ $isSigned ? '✓' : '○' }}</span>
                    <div style="flex:1">
                        <div style="font-size:13px;font-weight:600;color:#1a1a1a">{{ $signer->name }}</div>
                        <div style="font-size:11px;color:#888;font-family:monospace">{{ $role }} &nbsp;·&nbsp; {{ $signer->email }}</div>
                    </div>
                    <div style="text-align:right">
                        <span style="font-family:monospace;font-size:10px;font-weight:700;color:{{ $statusClr }};text-transform:uppercase;letter-spacing:.08em">{{ $signer->status }}</span>
                        @if ($isSigned && $signer->signed_at)
                            <span style="font-size:11px;color:#888;margin-left:8px">Signed {{ $signer->signed_at->format('M j, Y g:i A') }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
    <div style="margin-top:12px;font-size:12px;color:#888">
        Lessee signing URL: <span style="font-family:monospace;font-size:11px;color:#555">{{ $signingUrl }}</span>
    </div>
</div>
