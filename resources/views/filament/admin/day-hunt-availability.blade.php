@php
    $colors = [
        'available'   => ['bg' => '#e7f1ea', 'fg' => '#0a1512', 'bd' => '#bcd6c6'],
        'booked'      => ['bg' => '#c84c21', 'fg' => '#ffffff', 'bd' => '#a83c16'],
        'blocked'     => ['bg' => '#3a3a3a', 'fg' => '#ffffff', 'bd' => '#2a2a2a'],
        'maintenance' => ['bg' => '#d9a521', 'fg' => '#0a1512', 'bd' => '#b88a16'],
        'out'         => ['bg' => 'transparent', 'fg' => '#9aa3a0', 'bd' => 'transparent'],
        'pad'         => ['bg' => 'transparent', 'fg' => 'transparent', 'bd' => 'transparent'],
    ];
    $legend = [
        'available'   => 'Available',
        'booked'      => 'Booked',
        'blocked'     => 'Blocked',
        'maintenance' => 'Maintenance',
    ];
    $dow = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
@endphp

<div style="font-family: 'JetBrains Mono', monospace; font-size: 12px;">
    @if (empty($calendar['months']))
        <p style="padding: 12px; color: #6b726f;">
            This listing has no season dates set, so there is no calendar to show. Set a season
            start and end on the listing to manage day-hunt availability.
        </p>
    @else
        <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: center; margin-bottom: 14px;">
            <span style="color: #6b726f;">
                Season: {{ $calendar['season_start'] }} – {{ $calendar['season_end'] }}
            </span>
            @foreach ($legend as $key => $label)
                <span style="display: inline-flex; align-items: center; gap: 6px;">
                    <span style="width: 14px; height: 14px; border-radius: 3px;
                        background: {{ $colors[$key]['bg'] }}; border: 1px solid {{ $colors[$key]['bd'] }};"></span>
                    {{ $label }}
                    @if ($key === 'available') ({{ $calendar['totals']['available'] }})
                    @elseif ($key === 'booked') ({{ $calendar['totals']['booked'] }})
                    @elseif ($key === 'blocked') ({{ $calendar['totals']['blocked'] }})
                    @endif
                </span>
            @endforeach
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px;">
            @foreach ($calendar['months'] as $month)
                <div>
                    <div style="font-weight: 600; margin-bottom: 6px;">{{ $month['label'] }}</div>
                    <table style="width: 100%; border-collapse: collapse; table-layout: fixed;">
                        <thead>
                            <tr>
                                @foreach ($dow as $d)
                                    <th style="padding: 4px 0; color: #9aa3a0; font-weight: 500;">{{ $d }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($month['weeks'] as $week)
                                <tr>
                                    @foreach ($week as $cell)
                                        @php $c = $colors[$cell['status']] ?? $colors['out']; @endphp
                                        <td style="padding: 2px;">
                                            @if ($cell['day'])
                                                <div title="{{ $cell['title'] }}"
                                                    style="height: 28px; line-height: 28px; text-align: center; border-radius: 4px;
                                                    background: {{ $c['bg'] }}; color: {{ $c['fg'] }}; border: 1px solid {{ $c['bd'] }};">
                                                    {{ $cell['day'] }}
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @endif
</div>
