{{-- Owned properties grid: $properties = [['title', 'state_code', 'status'], ...] --}}
@php
    $hs = 'font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'
        . 'color:#6b7280;padding:0.4rem 0.75rem;border-bottom:2px solid #e5e7eb;';
    $cs = 'padding:0.6rem 0.75rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;';
@endphp
<div style="display:grid;grid-template-columns:2fr 0.5fr 1fr;">
    <div style="{{ $hs }}">Property</div>
    <div style="{{ $hs }}">State</div>
    <div style="{{ $hs }}">Status</div>
</div>
@foreach ($properties as $p)
    @php
        [$bg, $color] = match ($p['status']) {
            'active'    => ['#d1fae5', '#065f46'],
            'draft'     => ['#f3f4f6', '#374151'],
            'suspended' => ['#fef3c7', '#92400e'],
            'archived'  => ['#e5e7eb', '#6b7280'],
            default     => ['#f3f4f6', '#374151'],
        };
    @endphp
    <div style="display:grid;grid-template-columns:2fr 0.5fr 1fr;">
        <div style="{{ $cs }}"><span style="font-weight:500;font-size:0.875rem;color:#374151;">{{ $p['title'] }}</span></div>
        <div style="{{ $cs }}"><span style="font-size:0.8rem;color:#6b7280;">{{ $p['state_code'] }}</span></div>
        <div style="{{ $cs }}">
            <span style="background:{{ $bg }};color:{{ $color }};padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">{{ ucfirst($p['status']) }}</span>
        </div>
    </div>
@endforeach
