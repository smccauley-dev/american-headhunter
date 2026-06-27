{{-- Admin-only investigation notes. $notes = pre-shaped, newest first:
     ['time' => Carbon, 'author' => string, 'body' => string]. Append-only —
     never shown to the reporter. --}}
@php
    $row = 'padding:0.6rem 0.75rem;border-bottom:1px solid #f3f4f6;';
    $meta = 'font-size:0.75rem;color:#9ca3af;margin-bottom:0.2rem;';
    $body = 'font-size:0.875rem;color:#374151;white-space:pre-wrap;';
@endphp
<div>
    @forelse ($notes as $n)
        <div style="{{ $row }}">
            <div style="{{ $meta }}">
                <span style="font-weight:600;color:#6b7280;">{{ $n['author'] }}</span>
                · {{ $n['time']?->format('M j, Y g:i A') ?? '—' }}
            </div>
            <div style="{{ $body }}">{{ $n['body'] }}</div>
        </div>
    @empty
        <span style="color:#9ca3af;font-size:0.85rem;">No investigation notes yet.</span>
    @endforelse
</div>
