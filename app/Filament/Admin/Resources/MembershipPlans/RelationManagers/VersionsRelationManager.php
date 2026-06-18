<?php

namespace App\Filament\Admin\Resources\MembershipPlans\RelationManagers;

use App\Models\Platform\PlanVersion;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Read-only history of published plan versions. Versions are immutable
 * (Postgres RULE blocks UPDATE) and created only via the page's
 * "Publish New Version" action — so there is no create/edit/delete here.
 */
class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Version History';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version_number', 'desc')
            ->columns([
                TextColumn::make('version_number')
                    ->label('Ver.')
                    ->alignCenter(),
                TextColumn::make('monthly_price_cents')
                    ->label('Monthly')
                    ->money('USD', divideBy: 100),
                TextColumn::make('annual_price_cents')
                    ->label('Annual')
                    ->money('USD', divideBy: 100),
                TextColumn::make('change_reason')
                    ->label('Reason')
                    ->limit(50)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Published')
                    ->dateTime('M j, Y H:i'),
            ])
            ->recordActions([
                Action::make('snapshot')
                    ->label('Snapshot')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (PlanVersion $record): string => "Entitlements — Version {$record->version_number}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (PlanVersion $record): HtmlString => $this->snapshotView($record)),
            ]);
    }

    private function snapshotView(PlanVersion $record): HtmlString
    {
        $snapshot = $record->entitlements_snapshot ?? [];

        if (empty($snapshot)) {
            return new HtmlString('<p style="padding:8px;">No entitlements were captured in this version.</p>');
        }

        $rows = '';
        foreach ($snapshot as $key => $entry) {
            $type  = e($entry['type'] ?? '—');
            $value = $entry['value'] ?? null;
            $value = is_bool($value) ? ($value ? 'true' : 'false') : e(is_array($value) ? json_encode($value) : (string) $value);

            $rows .= "<div style=\"display:flex;gap:12px;padding:3px 0;border-bottom:1px solid rgba(0,0,0,0.06);\">"
                . "<code style=\"min-width:220px;\">" . e($key) . "</code>"
                . "<span style=\"min-width:70px;opacity:0.6;\">{$type}</span>"
                . "<strong>{$value}</strong></div>";
        }

        return new HtmlString('<div style="font-size:13px;">' . $rows . '</div>');
    }
}
