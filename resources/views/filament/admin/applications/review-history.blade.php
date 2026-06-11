{{-- Review decision timeline: $records = collection of LeaseApplicationReviewHistory --}}
<div style="font-family:system-ui,sans-serif;padding:4px 0">
    @foreach ($records as $h)
        @php
            $badgeColor = match ($h->to_status) {
                'approved' => '#15803d',
                'rejected' => '#b91c1c',
                default    => '#555',
            };
            $badgeBg = match ($h->to_status) {
                'approved' => '#f0fdf4',
                'rejected' => '#fef2f2',
                default    => '#f5f5f5',
            };
        @endphp
        <div>
            <div style="display:flex;align-items:flex-start;gap:12px">
                <div style="flex-shrink:0;margin-top:3px">
                    <div style="width:26px;height:26px;border-radius:50%;background:{{ $badgeBg }};border:2px solid {{ $badgeColor }};display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:{{ $badgeColor }}">
                        {{ $loop->iteration }}
                    </div>
                </div>
                <div style="flex:1;padding-bottom:4px">
                    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:4px">
                        <span style="font-size:13px;font-weight:600;color:#1a1a1a">{{ $h->label() }}</span>
                        @if ($h->isOverride())
                            <span style="background:#fef9c3;color:#854d0e;font-family:monospace;font-size:9px;padding:2px 7px;border-radius:2px;text-transform:uppercase;letter-spacing:.1em;margin-left:8px">Override</span>
                            <span style="font-family:monospace;font-size:11px;color:#888">{{ strtoupper($h->from_status) }} → {{ strtoupper($h->to_status) }}</span>
                        @endif
                    </div>
                    <div style="font-family:monospace;font-size:10px;color:#888;margin-bottom:4px">
                        {{ $h->created_at?->format('F j, Y \a\t g:i A') ?? '—' }} &nbsp;·&nbsp; User {{ strtoupper(substr($h->decided_by_user_id, 0, 8)) }}
                    </div>
                    @if ($h->reason)
                        <div style="margin-top:8px;padding:8px 12px;background:#fafaf9;border-left:2px solid #d1d5db;font-size:13px;color:#444;font-style:italic">"{{ $h->reason }}"</div>
                    @endif
                </div>
            </div>
            @unless ($loop->last)
                <div style="width:2px;height:16px;background:#e5e7eb;margin:4px 0 4px 12px"></div>
            @endunless
        </div>
    @endforeach
</div>
