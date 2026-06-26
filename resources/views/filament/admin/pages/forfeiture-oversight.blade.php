<x-filament-panels::page>
    @php
        $money = fn (int $cents) => '$' . number_format($cents / 100, 2);
        $pct   = fn (float $rate) => round($rate * 100) . '%';
        $landowners = $this->landowners();
        $hunters    = $this->hunters();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Landowners</x-slot>
        <x-slot name="description">
            Flagged for review at {{ $this->flagThresholdLabel() }} of concluded deposits — frequency is the
            scam signal, regardless of the stated reason. Flags are for human review; no automatic penalty.
        </x-slot>

        @if (empty($landowners))
            <p class="fi-color-gray-500" style="font-style: italic;">No forfeitures recorded yet.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="text-align:left;border-bottom:2px solid var(--gray-200);">
                            <th style="padding:8px 12px;">Landowner</th>
                            <th style="padding:8px 12px;text-align:right;">Forfeitures</th>
                            <th style="padding:8px 12px;text-align:right;">Concluded</th>
                            <th style="padding:8px 12px;text-align:right;">Rate</th>
                            <th style="padding:8px 12px;text-align:right;">Total Forfeited</th>
                            <th style="padding:8px 12px;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($landowners as $row)
                            <tr style="border-bottom:1px solid var(--gray-100);{{ $row['flagged'] ? 'background:rgba(181,80,58,.06);' : '' }}">
                                <td style="padding:8px 12px;">
                                    <div style="font-weight:600;">{{ $row['name'] }}</div>
                                    <div style="font-size:10px;font-family:ui-monospace,monospace;color:#9ca3af;">REF UUID: {{ $row['user_id'] }}</div>
                                </td>
                                <td style="padding:8px 12px;text-align:right;">{{ $row['forfeits'] }}</td>
                                <td style="padding:8px 12px;text-align:right;">{{ $row['resolved'] }}</td>
                                <td style="padding:8px 12px;text-align:right;font-weight:600;">{{ $pct($row['rate']) }}</td>
                                <td style="padding:8px 12px;text-align:right;">{{ $money($row['forfeited_cents']) }}</td>
                                <td style="padding:8px 12px;">
                                    @if ($row['flagged'])
                                        <x-filament::badge color="danger">Review</x-filament::badge>
                                    @else
                                        <x-filament::badge color="gray">OK</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Hunters</x-slot>
        <x-slot name="description">
            How often a deposit has been forfeited against each hunter, across concluded deposits.
        </x-slot>

        @if (empty($hunters))
            <p class="fi-color-gray-500" style="font-style: italic;">No forfeitures recorded yet.</p>
        @else
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="text-align:left;border-bottom:2px solid var(--gray-200);">
                            <th style="padding:8px 12px;">Hunter</th>
                            <th style="padding:8px 12px;text-align:right;">Forfeitures</th>
                            <th style="padding:8px 12px;text-align:right;">Concluded</th>
                            <th style="padding:8px 12px;text-align:right;">Rate</th>
                            <th style="padding:8px 12px;text-align:right;">Total Forfeited</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hunters as $row)
                            <tr style="border-bottom:1px solid var(--gray-100);">
                                <td style="padding:8px 12px;">
                                    <div style="font-weight:600;">{{ $row['name'] }}</div>
                                    <div style="font-size:10px;font-family:ui-monospace,monospace;color:#9ca3af;">REF UUID: {{ $row['user_id'] }}</div>
                                </td>
                                <td style="padding:8px 12px;text-align:right;">{{ $row['forfeits'] }}</td>
                                <td style="padding:8px 12px;text-align:right;">{{ $row['resolved'] }}</td>
                                <td style="padding:8px 12px;text-align:right;">{{ $pct($row['rate']) }}</td>
                                <td style="padding:8px 12px;text-align:right;">{{ $money($row['forfeited_cents']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
