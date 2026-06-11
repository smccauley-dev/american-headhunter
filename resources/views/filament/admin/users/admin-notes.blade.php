{{-- Staff note rows: $notes = collection of UserAdminNote models --}}
@foreach ($notes as $n)
    <div class="py-2 border-b border-gray-100">
        <div class="text-xs text-gray-400 mb-1">
            {{ $n->created_at?->format('M j Y H:i') }} — {{ trim(($n->getAuthor()?->profile?->first_name ?? '') . ' ' . ($n->getAuthor()?->profile?->last_name ?? '')) ?: '—' }}
        </div>
        <div class="text-sm text-gray-800 whitespace-pre-wrap">{{ $n->note }}</div>
    </div>
@endforeach
