{{-- Responsive grid of stat cards for the admin analytics dashboard. --}}
<div
    style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));"
    {{ $attributes }}
>
    {{ $slot }}
</div>
