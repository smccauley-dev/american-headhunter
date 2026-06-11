{{-- Identity verification rows: $records = collection of IdentityVerification models --}}
@foreach ($records as $v)
    <div class="py-1 text-sm border-b border-gray-100">
        <span class="font-medium">{{ ucfirst($v->verification_type ?? '—') }}</span>
        — {{ ucfirst($v->status ?? '—') }}{{ $v->verified_at ? ' on ' . $v->verified_at->format('M j Y') : '' }} via {{ $v->provider ?? '—' }}
    </div>
@endforeach
