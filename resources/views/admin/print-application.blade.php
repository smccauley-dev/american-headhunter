<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application {{ 'AH-' . strtoupper(substr($application->id, 0, 8)) }} — American Headhunter</title>
    <style>
        /* ── Reset & base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 11pt;
            color: #1a1a1a;
            background: #fff;
            line-height: 1.5;
        }

        /* ── Page setup ── */
        @page {
            size: letter;
            margin: 0.75in 0.85in;
        }

        /* ── Layout ── */
        .page { max-width: 7.5in; margin: 0 auto; padding: 0.5in 0; }

        /* ── Header ── */
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding-bottom: 18pt;
            border-bottom: 2pt solid #1a1a1a;
            margin-bottom: 22pt;
        }
        .doc-brand-name {
            font-family: 'Palatino Linotype', Palatino, Georgia, serif;
            font-size: 20pt;
            font-weight: 700;
            letter-spacing: 0.04em;
            line-height: 1.1;
        }
        .doc-brand-tag {
            font-family: 'Courier New', Courier, monospace;
            font-size: 7pt;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: #666;
            margin-top: 3pt;
        }
        .doc-meta {
            text-align: right;
            font-family: 'Courier New', Courier, monospace;
            font-size: 8pt;
            color: #555;
            line-height: 1.8;
        }
        .doc-meta .app-id {
            font-size: 13pt;
            font-weight: 700;
            color: #1a1a1a;
            letter-spacing: 0.08em;
        }

        /* ── Section headings ── */
        .section {
            margin-bottom: 20pt;
        }
        .section-title {
            font-family: 'Courier New', Courier, monospace;
            font-size: 7.5pt;
            font-weight: 700;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            color: #555;
            border-bottom: 0.5pt solid #ccc;
            padding-bottom: 4pt;
            margin-bottom: 10pt;
        }

        /* ── Two-column grid ── */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 32pt;
        }
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 0 20pt;
        }

        /* ── Field rows ── */
        .field-block { margin-bottom: 10pt; }
        .field-label {
            font-family: 'Courier New', Courier, monospace;
            font-size: 7pt;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #888;
            margin-bottom: 2pt;
        }
        .field-value {
            font-size: 10.5pt;
            color: #1a1a1a;
        }
        .field-value.mono {
            font-family: 'Courier New', Courier, monospace;
            font-size: 9pt;
            letter-spacing: 0.05em;
        }

        /* ── Status badge ── */
        .status-badge {
            display: inline-block;
            padding: 2pt 8pt;
            border: 1pt solid #1a1a1a;
            font-family: 'Courier New', Courier, monospace;
            font-size: 8pt;
            font-weight: 700;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        /* ── Quote block ── */
        .quote-block {
            border-left: 2pt solid #999;
            padding: 8pt 12pt;
            margin: 4pt 0;
            font-style: italic;
            color: #333;
            font-size: 10.5pt;
        }

        /* ── Hunter cards ── */
        .hunter-card {
            border: 0.5pt solid #ccc;
            margin-bottom: 12pt;
            page-break-inside: avoid;
        }
        .hunter-card-header {
            background: #f4f4f4;
            border-bottom: 0.5pt solid #ccc;
            padding: 7pt 12pt;
            display: flex;
            align-items: center;
            gap: 10pt;
        }
        .hunter-num {
            font-family: 'Courier New', Courier, monospace;
            font-size: 8pt;
            color: #888;
            letter-spacing: 0.1em;
        }
        .hunter-name {
            font-size: 13pt;
            font-weight: 700;
            letter-spacing: 0.02em;
        }
        .hunter-role {
            font-family: 'Courier New', Courier, monospace;
            font-size: 7pt;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: #888;
            margin-left: auto;
        }
        .minor-badge {
            font-family: 'Courier New', Courier, monospace;
            font-size: 7pt;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            border: 0.5pt solid #999;
            padding: 1pt 5pt;
            color: #555;
        }
        .hunter-card-body {
            padding: 10pt 12pt;
        }
        .hunter-divider {
            border: none;
            border-top: 0.5pt solid #eee;
            margin: 8pt 0;
        }
        .conf-ok  { font-weight: 700; }
        .conf-no  { color: #888; }

        /* ── Message thread ── */
        .message-row {
            display: flex;
            gap: 10pt;
            margin-bottom: 10pt;
            page-break-inside: avoid;
        }
        .message-meta {
            flex-shrink: 0;
            width: 80pt;
            font-family: 'Courier New', Courier, monospace;
            font-size: 7pt;
            letter-spacing: 0.05em;
            color: #666;
            text-transform: uppercase;
            padding-top: 2pt;
        }
        .message-body {
            flex: 1;
            border-left: 1.5pt solid #ccc;
            padding-left: 10pt;
            font-size: 10pt;
            color: #1a1a1a;
            white-space: pre-wrap;
        }

        /* ── Notes ── */
        .notes-box {
            border: 0.5pt solid #ccc;
            padding: 10pt 12pt;
            font-size: 10.5pt;
            white-space: pre-wrap;
            color: #333;
        }

        /* ── Footer ── */
        .doc-footer {
            margin-top: 28pt;
            padding-top: 10pt;
            border-top: 0.5pt solid #ccc;
            display: flex;
            justify-content: space-between;
            font-family: 'Courier New', Courier, monospace;
            font-size: 7pt;
            color: #888;
            letter-spacing: 0.05em;
        }

        /* ── Print control ── */
        .no-print { }
        @media print {
            .no-print { display: none !important; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            a { text-decoration: none; color: inherit; }
        }
    </style>
</head>
<body>
<div class="page">

    {{-- ── Print toolbar (hidden when printing) ── --}}
    <div class="no-print" style="background:#f0f0f0;padding:10px 16px;margin-bottom:24px;display:flex;align-items:center;gap:12px;font-family:sans-serif;font-size:13px;border:1px solid #ddd">
        <button onclick="window.print()" style="background:#1a1a1a;color:#fff;border:none;padding:8px 18px;font-size:13px;cursor:pointer;font-family:sans-serif">
            ⎙ Print / Save as PDF
        </button>
        <span style="color:#666">or press <strong>Ctrl+P</strong> (Windows) / <strong>⌘P</strong> (Mac)</span>
        <a href="javascript:history.back()" style="margin-left:auto;color:#555;text-decoration:none">← Back</a>
    </div>

    {{-- ── Document header ── --}}
    <div class="doc-header">
        <div>
            <div class="doc-brand-name">American Headhunter</div>
            <div class="doc-brand-tag">Hunting Lease Marketplace · Est. 2025</div>
        </div>
        <div class="doc-meta">
            <div class="app-id">AH-{{ strtoupper(substr($application->id, 0, 8)) }}</div>
            <div>Lease Application</div>
            <div>Printed {{ $printedAt }}</div>
            <div>By {{ $printedBy }}</div>
        </div>
    </div>

    {{-- ── Property & Application overview ── --}}
    <div class="section">
        <div class="section-title">Application Overview</div>
        <div class="grid-2">
            <div>
                @if($displayTitle)
                    <div class="field-block">
                        <div class="field-label">Property</div>
                        <div class="field-value">{{ $displayTitle }}</div>
                    </div>
                @endif
                @if($displayLocation)
                    <div class="field-block">
                        <div class="field-label">Location</div>
                        <div class="field-value">{{ $displayLocation }}</div>
                    </div>
                @endif
                @if($property?->total_acres)
                    <div class="field-block">
                        <div class="field-label">Acreage</div>
                        <div class="field-value">{{ number_format($property->total_acres) }} acres</div>
                    </div>
                @endif
                <div class="field-block">
                    <div class="field-label">Listing ID</div>
                    <div class="field-value mono">{{ strtoupper(substr($application->listing_id, 0, 8)) }}</div>
                </div>
            </div>
            <div>
                <div class="field-block">
                    <div class="field-label">Status</div>
                    <div class="field-value">
                        <span class="status-badge">{{ strtoupper($application->status) }}</span>
                    </div>
                </div>
                <div class="field-block" style="margin-top:8pt">
                    <div class="field-label">Application Type</div>
                    <div class="field-value">{{ $application->application_type === 'individual' ? 'Individual' : 'Hunting Club' }}</div>
                </div>
                <div class="field-block">
                    <div class="field-label">Proposed Season</div>
                    <div class="field-value">
                        {{ $application->proposed_start?->format('F j, Y') ?? '—' }}
                        &nbsp;–&nbsp;
                        {{ $application->proposed_end?->format('F j, Y') ?? '—' }}
                    </div>
                </div>
                <div class="field-block">
                    <div class="field-label">Party Size</div>
                    <div class="field-value">{{ $application->desired_hunters }} hunter{{ $application->desired_hunters !== 1 ? 's' : '' }}</div>
                </div>
                <div class="field-block">
                    <div class="field-label">Submitted</div>
                    <div class="field-value">{{ $application->created_at?->format('F j, Y \a\t g:i A') ?? '—' }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Applicant ── --}}
    <div class="section">
        <div class="section-title">Primary Applicant</div>
        <div class="grid-3">
            <div class="field-block">
                <div class="field-label">Name</div>
                <div class="field-value">{{ $applicantName ?? '—' }}</div>
            </div>
            <div class="field-block">
                <div class="field-label">Email</div>
                <div class="field-value">{{ $applicantEmail ?? '—' }}</div>
            </div>
            <div class="field-block">
                <div class="field-label">User ID</div>
                <div class="field-value mono">{{ strtoupper(substr($application->applicant_user_id, 0, 8)) }}</div>
            </div>
        </div>

        @if($application->message)
            <div class="field-label" style="margin-top:8pt">Message to Landowner</div>
            <div class="quote-block">"{{ $application->message }}"</div>
        @endif
    </div>

    {{-- ── Hunter Roster ── --}}
    @if($hunters->isNotEmpty())
        <div class="section">
            <div class="section-title">Hunter Roster ({{ $hunters->count() }})</div>
            @foreach($hunters as $i => $h)
                @php
                    $num      = str_pad($i + 1, 2, '0', STR_PAD_LEFT);
                    $role     = $h->hunter_type === 'primary' ? 'Primary Hunter' : 'Guest Hunter';
                    $address  = collect([$h->address_line1, $h->address_line2, $h->city, $h->state_code, $h->zip_code])->filter()->implode(', ');
                    $phones   = collect([$h->cell_phone ? 'Cell: '.$h->cell_phone : null, $h->home_phone ? 'Home: '.$h->home_phone : null])->filter()->implode(' · ');
                @endphp
                <div class="hunter-card">
                    <div class="hunter-card-header">
                        <span class="hunter-num">{{ $num }}</span>
                        <span class="hunter-name">{{ $h->first_name }} {{ $h->last_name }}</span>
                        @if($h->is_minor)
                            <span class="minor-badge">Minor</span>
                        @endif
                        <span class="hunter-role">{{ $role }}</span>
                    </div>
                    <div class="hunter-card-body">
                        <div class="grid-3">
                            <div class="field-block">
                                <div class="field-label">Date of Birth</div>
                                <div class="field-value">{{ $h->date_of_birth?->format('M j, Y') ?? '—' }}</div>
                            </div>
                            <div class="field-block">
                                <div class="field-label">Email</div>
                                <div class="field-value">{{ $h->email ?? '—' }}</div>
                            </div>
                            <div class="field-block">
                                <div class="field-label">Phone</div>
                                <div class="field-value">{{ $phones ?: '—' }}</div>
                            </div>
                        </div>
                        @if($address)
                            <div class="field-block">
                                <div class="field-label">Home Address</div>
                                <div class="field-value">{{ $address }}</div>
                            </div>
                        @endif
                        @if($h->emergency_contact_name)
                            <div class="field-block">
                                <div class="field-label">Emergency Contact</div>
                                <div class="field-value">
                                    {{ $h->emergency_contact_name }}
                                    @if($h->emergency_contact_relationship) ({{ $h->emergency_contact_relationship }})@endif
                                    @if($h->emergency_contact_phone) · {{ $h->emergency_contact_phone }}@endif
                                </div>
                            </div>
                        @endif
                        @if($h->medical_conditions)
                            <div class="field-block">
                                <div class="field-label">Medical Conditions</div>
                                <div class="field-value">{{ $h->medical_conditions }}</div>
                            </div>
                        @endif
                        <hr class="hunter-divider">
                        <div class="grid-2">
                            <div class="field-block">
                                <div class="field-label">Driver's License</div>
                                @if($h->dl_number)
                                    <div class="field-value">
                                        {{ $h->dl_number }}
                                        @if($h->dl_state) · {{ $h->dl_state }}@endif
                                        @if($h->dl_expiry) · Exp {{ $h->dl_expiry->format('m/Y') }}@endif
                                        —
                                        @if($h->dl_confirmed_current)
                                            <span class="conf-ok">✓ Confirmed current</span>
                                        @else
                                            <span class="conf-no">Not confirmed</span>
                                        @endif
                                    </div>
                                @else
                                    <div class="field-value" style="color:#aaa">—</div>
                                @endif
                            </div>
                            <div class="field-block">
                                <div class="field-label">Hunting License</div>
                                @if($h->hunting_license_number)
                                    <div class="field-value">
                                        {{ $h->hunting_license_number }}
                                        @if($h->hunting_license_state) · {{ $h->hunting_license_state }}@endif
                                        @if($h->hunting_license_expiry) · Exp {{ $h->hunting_license_expiry->format('m/Y') }}@endif
                                        —
                                        @if($h->hunting_license_confirmed_current)
                                            <span class="conf-ok">✓ Confirmed current</span>
                                        @else
                                            <span class="conf-no">Not confirmed</span>
                                        @endif
                                    </div>
                                @else
                                    <div class="field-value" style="color:#aaa">—</div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── Notes ── --}}
    @if($application->admin_notes)
        <div class="section">
            <div class="section-title">Notes</div>
            <div class="notes-box">{{ $application->admin_notes }}</div>
        </div>
    @endif

    {{-- ── Communications ── --}}
    @if($messages->isNotEmpty())
        <div class="section">
            <div class="section-title">Communications ({{ $messages->count() }})</div>
            @foreach($messages as $m)
                @php
                    $roleLabel = match($m->sender_role) {
                        'admin'     => 'Admin',
                        'landowner' => 'Landowner',
                        'applicant' => 'Applicant',
                        default     => 'Unknown',
                    };
                @endphp
                <div class="message-row">
                    <div class="message-meta">
                        <div style="font-weight:700">{{ $roleLabel }}</div>
                        <div>{{ $m->created_at?->format('m/d/Y') }}</div>
                        <div>{{ $m->created_at?->format('g:i A') }}</div>
                    </div>
                    <div class="message-body">{{ $m->message }}</div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── Review record ── --}}
    @if($application->reviewed_at)
        <div class="section">
            <div class="section-title">Review Record</div>
            <div class="grid-3">
                <div class="field-block">
                    <div class="field-label">Decision</div>
                    <div class="field-value">
                        <span class="status-badge">{{ strtoupper($application->status) }}</span>
                    </div>
                </div>
                <div class="field-block">
                    <div class="field-label">Reviewed</div>
                    <div class="field-value">{{ $application->reviewed_at->format('F j, Y \a\t g:i A') }}</div>
                </div>
                <div class="field-block">
                    <div class="field-label">Reviewed By</div>
                    <div class="field-value">{{ $reviewedByName ?? '—' }}</div>
                </div>
            </div>
            @if($application->rejection_reason)
                <div class="field-block" style="margin-top:6pt">
                    <div class="field-label">Rejection Reason</div>
                    <div class="quote-block">{{ $application->rejection_reason }}</div>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Document footer ── --}}
    <div class="doc-footer">
        <span>American Headhunter, LLC · americanheadhunter.com</span>
        <span>Application {{ 'AH-' . strtoupper(substr($application->id, 0, 8)) }} · Confidential</span>
        <span>Printed {{ $printedAt }}</span>
    </div>

</div>
</body>
</html>
