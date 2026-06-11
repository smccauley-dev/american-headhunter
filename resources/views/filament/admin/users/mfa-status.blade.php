{{-- MFA factor status rows: $methods = [['label', 'enabled', 'verified_at', 'platform_on'], ...] --}}
@forelse ($methods as $m)
    <div style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;">
        <span>
            @if ($m['enabled'])
                <span style="color:#16a34a;font-size:16px;">&#9679;</span>
            @else
                <span style="color:#d1d5db;font-size:16px;">&#9675;</span>
            @endif
        </span>
        <span style="flex:1;color:#374151;">
            {{ $m['label'] }}
            @unless ($m['platform_on'])
                <span style="color:#ef4444;font-size:10px;margin-left:8px;">[platform off]</span>
            @endunless
        </span>
        <span>
            @if ($m['enabled'])
                <span style="color:#16a34a;font-weight:600;">Enabled</span>
                @if ($m['verified_at'])
                    <span style="color:#9ca3af;font-size:11px;margin-left:8px;">verified {{ $m['verified_at']->format('M j, Y') }}</span>
                @endif
            @else
                <span style="color:#9ca3af;">Disabled</span>
            @endif
        </span>
    </div>
@empty
    <span style="color:#9ca3af;">No MFA configured</span>
@endforelse
