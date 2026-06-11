{{-- Trust score event rows: $events = collection of TrustScoreEvent models --}}
@foreach ($events as $e)
    <div class="flex gap-4 py-1 border-b border-gray-100 text-sm">
        <span class="text-gray-400 w-36">{{ $e->created_at?->format('M j Y H:i') }}</span>
        <span class="{{ $e->delta >= 0 ? 'text-green-600' : 'text-red-600' }} w-12">{{ $e->delta >= 0 ? '+' : '' }}{{ $e->delta }}</span>
        <span class="text-gray-700">{{ $e->reason ?? '—' }}</span>
    </div>
@endforeach
