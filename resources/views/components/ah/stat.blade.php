@props(['label', 'value'])

{{-- A single analytics stat card — big mono figure over a muted label. --}}
<div class="fi-section" style="padding: 1rem 1.25rem;">
    <div style="font-size: 1.75rem; font-weight: 600; font-family: 'JetBrains Mono', monospace; line-height: 1.1;">
        {{ $value }}
    </div>
    <div style="font-size: .8rem; opacity: .6; margin-top: .35rem;">
        {{ $label }}
    </div>
</div>
