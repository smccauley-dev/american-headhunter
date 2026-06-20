{{-- Audit event table with before/after diffs.
     $events = collection of AuditLog models (current page, within the window)
     $days   = active time window in days (0 = all time)
     The window selector + CSV export live in the section header actions. --}}
@php
    $boolFields = [
        'is_veteran', 'is_first_responder',
        'veteran_is_active', 'first_responder_is_active',
    ];
    $formatVal = function ($field, $val) use ($boolFields): string {
        if ($val === null || $val === '') return '—';
        if (in_array($field, $boolFields, true)) {
            return $val ? 'Yes' : 'No';
        }
        if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}((T|\s)|$)/', $val)) {
            try { return \Carbon\Carbon::parse($val)->format('M j, Y'); } catch (\Throwable) {}
        }
        return (string) $val;
    };

    $ths = 'text-align:left;font-size:0.72rem;font-weight:600;text-transform:uppercase;'
         . 'letter-spacing:0.05em;color:#6b7280;padding:0.4rem 0.75rem;'
         . 'border-bottom:2px solid #e5e7eb;white-space:nowrap;';
    $tds = 'padding:0.55rem 0.75rem;border-bottom:1px solid #f3f4f6;'
         . 'vertical-align:top;font-size:0.875rem;color:#374151;';
    $dths = 'text-align:left;font-size:0.68rem;font-weight:600;text-transform:uppercase;'
          . 'letter-spacing:0.04em;color:#9ca3af;padding:0.25rem 0.5rem;'
          . 'border-bottom:1px solid #e5e7eb;';
    $dtds = 'padding:0.2rem 0.5rem;border-bottom:1px solid #f9fafb;'
          . 'font-size:0.75rem;vertical-align:middle;';
@endphp
<div>
    @if ($total === 0)
        <p style="font-size:0.85rem;color:#9ca3af;padding:1rem 0;">
            @if (($days ?? 0) > 0)
                No audit events in the last {{ $days }} {{ \Illuminate\Support\Str::plural('day', $days) }}.
            @else
                No audit events for this user.
            @endif
        </p>
    @else
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr>
                    <th style="{{ $ths }}">Time</th>
                    <th style="{{ $ths }}">Event</th>
                    <th style="{{ $ths }}">IP</th>
                    <th style="{{ $ths }}">Summary</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($events as $e)
                    <tr>
                        <td style="{{ $tds }}white-space:nowrap;color:#9ca3af;font-size:0.8rem;">{{ $e->occurred_at?->format('M j, Y H:i') }}</td>
                        <td style="{{ $tds }}font-family:monospace;font-size:0.8rem;">{{ $e->event_type }}</td>
                        <td style="{{ $tds }}font-family:monospace;font-size:0.8rem;color:#9ca3af;">{{ $e->ip_address ?? '—' }}</td>
                        <td style="{{ $tds }}">{{ $e->action_summary ?? '—' }}</td>
                    </tr>
                    @if (! empty($e->new_values))
                        <tr>
                            <td colspan="4" style="padding:0 0.75rem 0.5rem 1.5rem;border-bottom:1px solid #f3f4f6;">
                                <table style="border-collapse:collapse;width:auto;">
                                    <thead>
                                        <tr>
                                            <th style="{{ $dths }}">Field</th>
                                            <th style="{{ $dths }}">Before</th>
                                            <th style="{{ $dths }}">After</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($e->new_values as $field => $newVal)
                                            <tr>
                                                <td style="{{ $dtds }}font-family:monospace;color:#6b7280;">{{ $field }}</td>
                                                <td style="{{ $dtds }}color:#dc2626;text-decoration:line-through;">{{ $formatVal($field, $e->old_values[$field] ?? null) }}</td>
                                                <td style="{{ $dtds }}color:#16a34a;">{{ $formatVal($field, $newVal) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        @isset($lastPage)
            @if ($lastPage > 1)
                @php
                    $from = ($currentPage - 1) * $perPage + 1;
                    $to   = min($currentPage * $perPage, $total);
                @endphp
                <div class="fi-pagination" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;padding-top:1rem;">
                    <span class="fi-pagination-overview" style="font-size:0.8rem;color:#6b7280;">
                        Showing {{ $from }}–{{ $to }} of {{ $total }}
                    </span>
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-m-chevron-left"
                            :disabled="$currentPage <= 1"
                            wire:click="$set('auditLogPage', {{ $currentPage - 1 }})"
                        >
                            Previous
                        </x-filament::button>
                        <span style="font-size:0.8rem;color:#6b7280;white-space:nowrap;">Page {{ $currentPage }} of {{ $lastPage }}</span>
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-m-chevron-right"
                            icon-position="after"
                            :disabled="$currentPage >= $lastPage"
                            wire:click="$set('auditLogPage', {{ $currentPage + 1 }})"
                        >
                            Next
                        </x-filament::button>
                    </div>
                </div>
            @endif
        @endisset
    @endif
</div>
