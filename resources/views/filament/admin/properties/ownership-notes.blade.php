@php
    /** @var array $notes */
    $notes = $notes ?? [];

    $vs = 'font-size:0.875rem;color:#374151;';
@endphp

{{-- Internal staff review notes — append-only, never shown to the landowner --}}
@if (empty($notes))
    <p style="color:#9ca3af;font-size:0.8125rem;padding:0.25rem 0;">
        No notes yet. Use <strong>Add Note</strong> to record a question or observation — staff-only, never shown to the landowner.
    </p>
@else
    <div style="display:flex;flex-direction:column;gap:0.6rem;">
        @foreach ($notes as $n)
            <div style="background:#f9fafb;border:1px solid #e5e7eb;padding:0.6rem 0.85rem;">
                <div style="display:flex;justify-content:space-between;gap:1rem;margin-bottom:0.25rem;">
                    <span style="font-size:0.8125rem;font-weight:600;color:#374151;">{{ $n['author'] }}</span>
                    <span style="font-size:0.75rem;color:#9ca3af;white-space:nowrap;">{{ $n['created_at'] }}</span>
                </div>
                <div style="{{ $vs }}white-space:pre-wrap;">{{ $n['note'] }}</div>
            </div>
        @endforeach
    </div>
@endif
