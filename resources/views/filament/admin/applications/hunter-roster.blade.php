{{-- Hunter roster cards: $hunters = collection of LeaseApplicationHunter --}}
<div style="font-family:system-ui,sans-serif">
    @foreach ($hunters as $h)
        <div style="border:1px solid #e5e0d8;border-radius:4px;overflow:hidden;margin-bottom:16px">
            <div style="background:#f5f1eb;padding:12px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #e5e0d8">
                <span style="font-family:monospace;font-size:11px;color:#888;letter-spacing:.1em">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <span style="font-size:16px;font-weight:600;color:#1a1a1a">{{ $h->first_name }} {{ $h->last_name }}</span>
                @if ($h->is_minor)
                    <span style="background:#fff0d6;color:#b05a00;font-size:10px;padding:2px 8px;font-family:monospace;text-transform:uppercase;letter-spacing:.08em;border-radius:2px">Minor</span>
                @endif
                <span style="font-family:monospace;font-size:10px;color:#888;text-transform:uppercase;letter-spacing:.1em;margin-left:auto">{{ $h->hunter_type === 'primary' ? 'Primary Hunter' : 'Guest Hunter' }}</span>
            </div>
            <div style="padding:16px 20px;display:grid;grid-template-columns:repeat(3,1fr);gap:16px 32px;font-size:13px">
                <div>
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Date of Birth</div>
                    <div style="color:#1a1a1a">{{ $h->date_of_birth?->format('M j, Y') ?? '—' }}</div>
                </div>
                <div>
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Email</div>
                    <div style="color:#1a1a1a">{{ $h->email ?? '—' }}</div>
                </div>
                <div>
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Phone</div>
                    <div style="color:#1a1a1a">{{ collect([$h->cell_phone, $h->home_phone])->filter()->implode(' / ') ?: '—' }}</div>
                </div>
                <div style="grid-column:1/-1">
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Home Address</div>
                    <div style="color:#1a1a1a">{{ collect([$h->address_line1, $h->address_line2, $h->city, $h->state_code, $h->zip_code])->filter()->implode(', ') ?: '—' }}</div>
                </div>
                <div style="grid-column:1/-1">
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Emergency Contact</div>
                    <div style="color:#1a1a1a">
                        @if ($h->emergency_contact_name)
                            {{ $h->emergency_contact_name }}@if ($h->emergency_contact_relationship) ({{ $h->emergency_contact_relationship }})@endif — {{ $h->emergency_contact_phone ?? '' }}
                        @else
                            —
                        @endif
                    </div>
                </div>
                <div style="grid-column:1/-1">
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Medical Conditions</div>
                    <div>
                        @if ($h->medical_conditions)
                            <span style="color:#b05a00">{{ $h->medical_conditions }}</span>
                        @else
                            <span style="color:#aaa">None reported</span>
                        @endif
                    </div>
                </div>
                <div style="grid-column:1/-1;padding-top:8px;border-top:1px solid #f0ece6">
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Driver's License</div>
                    <div>
                        @if ($h->dl_number)
                            {{ $h->dl_number }} · {{ $h->dl_state ?? '' }}@if ($h->dl_expiry) · Exp {{ $h->dl_expiry->format('m/Y') }}@endif
                            &nbsp;
                            @if ($h->dl_confirmed_current)
                                <span style="color:#2d7a3a;font-weight:600">✓ Confirmed current</span>
                            @else
                                <span style="color:#b05a00;font-weight:600">⚠ Not confirmed</span>
                            @endif
                        @else
                            <span style="color:#aaa">—</span>
                        @endif
                    </div>
                </div>
                <div style="grid-column:1/-1">
                    <div style="font-family:monospace;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:#888;margin-bottom:4px">Hunting License</div>
                    <div>
                        @if ($h->hunting_license_number)
                            {{ $h->hunting_license_number }} · {{ $h->hunting_license_state ?? '' }}@if ($h->hunting_license_expiry) · Exp {{ $h->hunting_license_expiry->format('m/Y') }}@endif
                            &nbsp;
                            @if ($h->hunting_license_confirmed_current)
                                <span style="color:#2d7a3a;font-weight:600">✓ Confirmed current</span>
                            @else
                                <span style="color:#b05a00;font-weight:600">⚠ Not confirmed</span>
                            @endif
                        @else
                            <span style="color:#aaa">—</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
