{{-- Message thread: $messages = collection of LeaseApplicationMessage --}}
<div style="font-family:system-ui,sans-serif;padding:4px 0">
    @foreach ($messages as $m)
        @php
            $roleLabel = match ($m->sender_role) {
                'admin'     => 'Admin',
                'landowner' => 'Landowner',
                'applicant' => 'Applicant',
                default     => 'Unknown',
            };
            $roleColor = match ($m->sender_role) {
                'admin'     => '#1d4ed8',
                'landowner' => '#15803d',
                'applicant' => '#b05a00',
                default     => '#888',
            };
            $isApplicant = $m->sender_role === 'applicant';
        @endphp
        <div style="display:flex;flex-direction:column;align-items:{{ $isApplicant ? 'flex-start' : 'flex-end' }};margin-bottom:16px">
            <div style="max-width:70%">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;justify-content:{{ $isApplicant ? 'flex-start' : 'flex-end' }}">
                    <span style="font-family:monospace;font-size:10px;font-weight:700;color:{{ $roleColor }};text-transform:uppercase;letter-spacing:.1em">{{ $roleLabel }}</span>
                    <span style="font-family:monospace;font-size:10px;color:#aaa">{{ $m->created_at?->format('M j, Y g:i A') ?? '' }}</span>
                </div>
                <div style="background:{{ $isApplicant ? '#f5f1eb' : '#eef2ff' }};border:1px solid {{ $isApplicant ? '#e5e0d8' : '#c7d2fe' }};border-radius:4px;padding:12px 16px;font-size:14px;line-height:1.6;color:#1a1a1a">
                    {!! nl2br(e($m->message)) !!}
                </div>
                @if (! $isApplicant && $m->is_read)
                    <span style="color:#888;font-size:10px;margin-top:4px;display:block">Read {{ $m->read_at?->format('M j g:i A') ?? '' }}</span>
                @endif
            </div>
        </div>
    @endforeach
</div>
