{{-- Lease summary rows: $leases = [['id', 'role', 'status', 'start_date', 'end_date'], ...] --}}
@foreach ($leases as $l)
    <div class="py-1 text-sm border-b border-gray-100 flex gap-3">
        <span class="font-mono text-xs text-gray-400">{{ substr($l['id'], 0, 8) }}…</span>
        <span class="text-xs px-1 rounded bg-gray-100">{{ $l['role'] }}</span>
        <span class="text-xs px-1 rounded bg-green-50 text-green-700">{{ $l['status'] }}</span>
        <span class="text-gray-500">{{ $l['start_date'] }} – {{ $l['end_date'] }}</span>
    </div>
@endforeach
