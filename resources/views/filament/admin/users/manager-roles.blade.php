{{-- Property manager grants grid: $grants = [['property_title', 'role', 'granted_at'], ...] --}}
@php
    $hs = 'font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;'
        . 'color:#6b7280;padding:0.4rem 0.75rem;border-bottom:2px solid #e5e7eb;';
    $cs = 'padding:0.6rem 0.75rem;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;';
@endphp
<div style="display:grid;grid-template-columns:2fr 1fr 1.2fr;">
    <div style="{{ $hs }}">Property</div>
    <div style="{{ $hs }}">Role</div>
    <div style="{{ $hs }}">Granted</div>
</div>
@foreach ($grants as $g)
    @php
        [$label, $bg, $color] = match ($g['role']) {
            'owner'    => ['Owner',    '#fce7f3', '#9d174d'],
            'co_owner' => ['Co-Owner', '#d1fae5', '#065f46'],
            'manager'  => ['Manager',  '#dbeafe', '#1e40af'],
            'operator' => ['Operator', '#fef3c7', '#92400e'],
            default    => [ucfirst($g['role']), '#f3f4f6', '#374151'],
        };
    @endphp
    <div style="display:grid;grid-template-columns:2fr 1fr 1.2fr;">
        <div style="{{ $cs }}"><span style="font-weight:500;font-size:0.875rem;color:#374151;">{{ $g['property_title'] }}</span></div>
        <div style="{{ $cs }}">
            <span style="background:{{ $bg }};color:{{ $color }};padding:0.15rem 0.5rem;border-radius:9999px;font-size:0.72rem;font-weight:600;">{{ $label }}</span>
        </div>
        <div style="{{ $cs }}"><span style="font-size:0.8rem;color:#6b7280;">{{ $g['granted_at'] ?? '—' }}</span></div>
    </div>
@endforeach
