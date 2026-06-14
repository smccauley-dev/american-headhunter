{{-- Property field check-in/out audit log: $rows = list of assoc arrays from
     CheckInService::getHistoryForProperty(). Read-only; newest first. --}}
@php
    $hs = 'font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'
        . 'color:#6b7280;padding:0.5rem 0.75rem;border-bottom:2px solid #e5e7eb;';
    $cs = 'font-size:0.875rem;color:#374151;padding:0.625rem 0.75rem;'
        . 'border-bottom:1px solid #f3f4f6;display:flex;align-items:center;';
@endphp

@if (empty($rows))
    <p style="color:#6b7280;font-size:0.875rem;padding:0.75rem 0;">
        No check-ins recorded yet. Hunters appear here as they scan the gate QR to check in and out.
    </p>
@else
    <div style="display:grid;grid-template-columns:2.2fr 1.4fr 1.4fr 1fr 1fr;">
        <div style="{{ $hs }}">Hunter</div>
        <div style="{{ $hs }}">Checked In</div>
        <div style="{{ $hs }}">Checked Out</div>
        <div style="{{ $hs }}">Duration</div>
        <div style="{{ $hs }}">Status</div>

        @foreach ($rows as $row)
            @php
                $in  = $row['checked_in_at'];
                $out = $row['checked_out_at'];
                $duration = $in
                    ? $in->diffForHumans($out ?? now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE, true, 2)
                    : '—';
            @endphp

            <div style="{{ $cs }}flex-direction:column;align-items:flex-start;gap:2px;">
                <span style="font-weight:500;color:#111827;">{{ $row['name'] }}</span>
                @if ($row['email'])
                    <span style="font-size:0.75rem;color:#9ca3af;">{{ $row['email'] }}</span>
                @endif
                <span style="font-family:monospace;font-size:0.7rem;color:#9ca3af;letter-spacing:.04em;">Lease {{ $row['lease_ref'] }}</span>
            </div>

            <div style="{{ $cs }}">
                {{ $in ? $in->format('M j, Y g:i A') : '—' }}
            </div>

            <div style="{{ $cs }}">
                {{ $out ? $out->format('M j, Y g:i A') : '—' }}
            </div>

            <div style="{{ $cs }}">
                {{ $duration }}
            </div>

            <div style="{{ $cs }}">
                @if ($row['open'])
                    <span style="display:inline-flex;align-items:center;gap:5px;font-size:0.75rem;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:.04em;">
                        <span style="width:7px;height:7px;border-radius:50%;background:#16a34a;display:inline-block;"></span>
                        In Field
                    </span>
                @else
                    <span style="font-size:0.75rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;">
                        Departed
                    </span>
                @endif
            </div>
        @endforeach
    </div>
@endif
