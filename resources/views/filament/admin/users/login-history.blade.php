{{-- Login history table: $entries = collection of LoginHistory models --}}
@php
    $col = 'display:grid;grid-template-columns:2fr 1.5fr 1.2fr 0.8fr;gap:0 1rem;';
@endphp
<div style="width:100%;">
    <div style="{{ $col }}padding-bottom:6px;border-bottom:1px solid #e5e7eb;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#9ca3af;">
        <span>Time</span><span>IP Address</span><span>Result</span><span>MFA</span>
    </div>
    @foreach ($entries as $e)
        <div style="{{ $col }}padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;align-items:center;">
            <span style="color:#6b7280;">{{ $e->created_at?->format('M j, Y H:i') }}</span>
            <span style="font-family:monospace;font-size:12px;">{{ $e->ip_address ?? '—' }}</span>
            @if ($e->success)
                <span style="color:#16a34a;">&#10003; Success</span>
            @else
                <span style="color:#dc2626;">&#10007; Failed</span>
            @endif
            <span>
                @if ($e->mfa_used)
                    <span style="color:#2563eb;">MFA</span>
                @else
                    —
                @endif
            </span>
        </div>
    @endforeach
</div>
