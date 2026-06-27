@php
    /** @var array|null $verification */
    $verification = $verification ?? null;

    $statusColors = [
        'submitted' => ['#e0e7ff', '#3730a3'],
        'pending'   => ['#fef3c7', '#92400e'],
        'approved'  => ['#d1fae5', '#065f46'],
        'rejected'  => ['#fee2e2', '#991b1b'],
    ];

    $statusLabels = [
        'submitted' => 'Submitted',
        'pending'   => 'Under Review',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
    ];

    $hs = 'font-size:0.7rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;';
    $vs = 'font-size:0.875rem;color:#374151;';
@endphp

@if (! $verification)
    <p style="color:#6b7280;font-size:0.875rem;padding:0.75rem 0;">
        The landowner has not submitted proof of ownership yet. This property cannot go
        <strong>Active</strong> until proof is submitted and approved here.
    </p>
@else
    @php
        [$badgeBg, $badgeFg] = $statusColors[$verification['status']] ?? ['#f3f4f6', '#374151'];
        $statusLabel = $statusLabels[$verification['status']] ?? ucfirst($verification['status']);
    @endphp

    {{-- Submission --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem 1.5rem;margin-bottom:1.25rem;">
        <div>
            <div style="{{ $hs }}">Status</div>
            <span style="display:inline-block;margin-top:0.25rem;background:{{ $badgeBg }};color:{{ $badgeFg }};padding:0.15rem 0.6rem;font-size:0.75rem;font-weight:600;">
                {{ $statusLabel }}
            </span>
        </div>
        <div>
            <div style="{{ $hs }}">Owner Type</div>
            <div style="{{ $vs }}margin-top:0.25rem;">{{ $verification['owner_type_label'] }}</div>
        </div>
        @php
            // Individual owners have no separate entity — fall back to their own name
            // (the submitter, or the certified legal name) rather than showing a dash.
            $ownerEntity = $verification['entity_name']
                ?: (($verification['owner_type'] ?? null) === 'individual'
                    ? ($verification['submitter'] ?: $verification['certification_name'])
                    : null);
        @endphp
        <div>
            <div style="{{ $hs }}">Owner / Entity</div>
            <div style="{{ $vs }}margin-top:0.25rem;">{{ $ownerEntity ?: '—' }}</div>
        </div>
        <div>
            <div style="{{ $hs }}">Submitted By</div>
            <div style="{{ $vs }}margin-top:0.25rem;">{{ $verification['submitter'] ?: 'Unknown user' }}</div>
        </div>
        <div>
            <div style="{{ $hs }}">Certified</div>
            <div style="{{ $vs }}margin-top:0.25rem;">{{ $verification['certified_at'] ?: '—' }}</div>
        </div>
    </div>

    {{-- Certification --}}
    <div style="background:#f9fafb;border:1px solid #e5e7eb;padding:0.75rem 1rem;margin-bottom:1.25rem;">
        <div style="{{ $hs }}">Certification — under penalty of perjury</div>
        <div style="{{ $vs }}margin-top:0.35rem;">
            Signed by <strong>{{ $verification['certification_name'] ?: '—' }}</strong>, certifying the listed property
            is owned or managed by the submitter and that these documents are current and accurate.
        </div>
    </div>

    {{-- Proof documents --}}
    <div style="margin-bottom:1.25rem;">
        <div style="{{ $hs }}margin-bottom:0.5rem;">Proof Documents</div>
        @include('filament.admin.incidents.evidence-gallery', ['items' => $items ?? [], 'missing' => $missing ?? []])
    </div>

    {{-- Review outcome --}}
    @if (in_array($verification['status'], ['approved', 'rejected'], true))
        <div style="border-top:1px solid #f3f4f6;padding-top:1rem;display:grid;grid-template-columns:repeat(3,1fr);gap:1rem 1.5rem;">
            <div>
                <div style="{{ $hs }}">Reviewed By</div>
                <div style="{{ $vs }}margin-top:0.25rem;">{{ $verification['reviewer'] ?: 'Auto-approved' }}</div>
            </div>
            <div>
                <div style="{{ $hs }}">Reviewed</div>
                <div style="{{ $vs }}margin-top:0.25rem;">{{ $verification['reviewed_at'] ?: '—' }}</div>
            </div>
            @if ($verification['status'] === 'rejected')
                <div style="grid-column:1 / -1;">
                    <div style="{{ $hs }}">Rejection Reason</div>
                    <div style="{{ $vs }}margin-top:0.25rem;">{{ $verification['review_notes'] ?: '—' }}</div>
                </div>
            @endif
        </div>
    @endif
@endif
