{{-- Current membership summary card.
     $m   = EntitlementService::currentMembership($user) array
     $sub = the active Subscription model, or null (free tier / promo-only) --}}
@php
    $statusColors = [
        'Active'      => ['#065f46', '#d1fae5'],
        'Trial'       => ['#1e40af', '#dbeafe'],
        'Past Due'    => ['#92400e', '#fef3c7'],
        'Promotional' => ['#5b21b6', '#ede9fe'],
        'Free'        => ['#374151', '#f3f4f6'],
    ];
    [$sc, $sbg] = $statusColors[$m['status_label']] ?? ['#374151', '#f3f4f6'];

    $sourceLabel = match ($m['source']) {
        'subscription' => $sub && ! $sub->stripe_subscription_id ? 'Complimentary (no billing)' : 'Paid subscription',
        'promotion'    => 'Promotional grant',
        'free'         => 'Free tier (default)',
        default        => ucfirst($m['source']),
    };

    $accent = $m['accent_color'] ?: '#6b7280';
    $label  = 'font-size:0.7rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#9ca3af;';
    $val    = 'font-size:0.9rem;color:#374151;text-align:right;';
    $row    = 'display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;border-bottom:1px solid #f3f4f6;';

    $fmtDate = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('M j, Y') : null;
@endphp

<div style="border:1px solid #e5e7eb;border-radius:0.5rem;overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.25rem;border-left:4px solid {{ $accent }};background:#fafafa;">
        <div>
            <div style="font-size:1.05rem;font-weight:700;color:#111827;">{{ $m['display_name'] }}</div>
            @if (! empty($m['tagline']))
                <div style="font-size:0.8rem;color:#6b7280;margin-top:0.15rem;">{{ $m['tagline'] }}</div>
            @endif
            <div style="font-size:0.72rem;color:#9ca3af;margin-top:0.35rem;">{{ $sourceLabel }}</div>
        </div>
        <span style="display:inline-block;padding:0.25rem 0.6rem;border-radius:9999px;font-size:0.72rem;font-weight:600;color:{{ $sc }};background:{{ $sbg }};white-space:nowrap;">
            {{ $m['status_label'] }}
        </span>
    </div>

    <div style="padding:0.5rem 1.25rem 1rem;">
        <div style="{{ $row }}">
            <span style="{{ $label }}">Pricing</span>
            <span style="{{ $val }}">
                @if ($m['is_free'])
                    Free
                @else
                    ${{ $m['monthly_price'] }}/mo &nbsp;·&nbsp; ${{ $m['annual_price'] }}/yr {{ $m['currency'] }}
                @endif
            </span>
        </div>

        @if ($sub && $fmtDate($sub->current_period_start))
            <div style="{{ $row }}">
                <span style="{{ $label }}">Started</span>
                <span style="{{ $val }}">{{ $fmtDate($sub->current_period_start) }}</span>
            </div>
        @endif

        @if ($m['renews_at'])
            <div style="{{ $row }}">
                <span style="{{ $label }}">{{ ($sub && $sub->cancelled_at) ? 'Access ends / renews' : 'Renews' }}</span>
                <span style="{{ $val }}">{{ $m['renews_at'] }}</span>
            </div>
        @endif

        @if (! empty($m['trial_ends_at']))
            <div style="{{ $row }}">
                <span style="{{ $label }}">Trial ends</span>
                <span style="{{ $val }}">{{ $m['trial_ends_at'] }}</span>
            </div>
        @endif

        @if ($sub && $sub->cancelled_at)
            <div style="{{ $row }}">
                <span style="{{ $label }}">Scheduled to cancel</span>
                <span style="{{ $val }}color:#b91c1c;font-weight:600;">{{ $fmtDate($sub->cancelled_at) }}</span>
            </div>
        @endif

        <div style="display:flex;justify-content:space-between;align-items:center;padding:0.5rem 0;">
            <span style="{{ $label }}">Billing</span>
            <span style="{{ $val }}">
                @if ($sub && $sub->stripe_subscription_id)
                    <span style="font-family:monospace;font-size:0.78rem;color:#059669;">● Stripe-linked</span>
                @elseif ($sub)
                    <span style="font-family:monospace;font-size:0.78rem;color:#9ca3af;">○ No Stripe (complimentary)</span>
                @else
                    <span style="font-family:monospace;font-size:0.78rem;color:#9ca3af;">—</span>
                @endif
            </span>
        </div>
    </div>
</div>
