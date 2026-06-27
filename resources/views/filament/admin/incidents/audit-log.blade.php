{{-- Incident change history. $rows = pre-shaped audit events, newest first:
     ['time' => Carbon, 'actor' => string, 'event' => string, 'summary' => ?string,
      'old' => array, 'new' => array]. Read-only — sourced from the immutable audit log. --}}
@php
    $boolFields = ['injuries_reported', 'authorities_notified'];
    $fieldLabels = [
        'incident_type'           => 'Type',
        'incident_items'          => 'Types',
        'severity'                => 'Severity',
        'status'                  => 'Status',
        'occurred_at'             => 'Occurred',
        'location_description'    => 'Location',
        'description'             => 'Description',
        'injuries_reported'       => 'Injuries reported',
        'authorities_notified'    => 'Authorities notified',
        'authority_report_number' => 'Authority report #',
    ];
    $formatItems = function (array $items): string {
        if ($items === []) return '—';
        return collect($items)->map(function ($it) {
            $type = isset($it['type']) ? \Illuminate\Support\Str::headline($it['type']) : '?';
            $sev  = isset($it['severity']) ? ucfirst($it['severity']) : '?';
            $when = '';
            if (! empty($it['occurred_at'])) {
                try { $when = ' · ' . \Carbon\Carbon::parse($it['occurred_at'])->format('M j, Y H:i'); } catch (\Throwable) {}
            }
            return "{$type} ({$sev}){$when}";
        })->implode('; ');
    };
    $formatVal = function ($field, $val) use ($boolFields, $formatItems): string {
        if ($val === null || $val === '') return '—';
        if (is_array($val)) return $formatItems($val);
        if (in_array($field, $boolFields, true)) return $val ? 'Yes' : 'No';
        if (is_bool($val)) return $val ? 'Yes' : 'No';
        if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}(T|\s)\d{2}:\d{2}/', $val)) {
            try { return \Carbon\Carbon::parse($val)->format('M j, Y H:i'); } catch (\Throwable) {}
        }
        if (is_string($val) && str_contains($val, '_')) return \Illuminate\Support\Str::headline($val);
        return (string) $val;
    };

    $tds  = 'padding:0.55rem 0.75rem;border-bottom:1px solid #f3f4f6;vertical-align:top;font-size:0.875rem;color:#374151;';
    $ths  = 'text-align:left;font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;padding:0.4rem 0.75rem;border-bottom:2px solid #e5e7eb;white-space:nowrap;';
    $dtds = 'padding:0.15rem 0.5rem;font-size:0.75rem;vertical-align:middle;';
@endphp
<div>
    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr>
                <th style="{{ $ths }}">When</th>
                <th style="{{ $ths }}">Who</th>
                <th style="{{ $ths }}">Action</th>
                <th style="{{ $ths }}">What changed</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $r)
                <tr>
                    <td style="{{ $tds }}white-space:nowrap;color:#9ca3af;font-size:0.8rem;">{{ $r['time']?->format('M j, Y H:i') ?? '—' }}</td>
                    <td style="{{ $tds }}white-space:nowrap;">{{ $r['actor'] }}</td>
                    <td style="{{ $tds }}font-family:monospace;font-size:0.8rem;">{{ $r['event'] }}</td>
                    <td style="{{ $tds }}">
                        @if (! empty($r['new']))
                            <table style="border-collapse:collapse;width:auto;">
                                @foreach ($r['new'] as $field => $newVal)
                                    <tr>
                                        <td style="{{ $dtds }}font-family:monospace;color:#6b7280;white-space:nowrap;">{{ $fieldLabels[$field] ?? $field }}</td>
                                        <td style="{{ $dtds }}color:#dc2626;text-decoration:line-through;">{{ $formatVal($field, $r['old'][$field] ?? null) }}</td>
                                        <td style="{{ $dtds }}color:#9ca3af;">&rarr;</td>
                                        <td style="{{ $dtds }}color:#16a34a;">{{ $formatVal($field, $newVal) }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @else
                            <span style="color:#6b7280;font-size:0.85rem;">{{ $r['summary'] ?? '—' }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
