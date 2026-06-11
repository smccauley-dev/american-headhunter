{{-- Club affiliation rows: $clubs = [['name', 'role', 'status'], ...] --}}
@foreach ($clubs as $c)
    <div class="py-1 text-sm border-b border-gray-100 flex gap-3">
        <span class="font-medium">{{ $c['name'] }}</span>
        @if ($c['role'] === 'Owner')
            <span class="text-xs px-1 rounded bg-amber-50 text-amber-700">Owner</span>
        @else
            <span class="text-xs px-1 rounded bg-gray-100">{{ $c['role'] }}</span>
        @endif
        <span class="text-xs text-gray-400">{{ $c['status'] }}</span>
    </div>
@endforeach
