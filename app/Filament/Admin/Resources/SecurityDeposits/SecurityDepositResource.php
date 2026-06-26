<?php

namespace App\Filament\Admin\Resources\SecurityDeposits;

use App\Filament\Admin\Resources\SecurityDeposits\Pages\ListSecurityDeposits;
use App\Filament\Admin\Resources\SecurityDeposits\Pages\ViewSecurityDeposit;
use App\Models\Billing\SecurityDeposit;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class SecurityDepositResource extends Resource
{
    protected static ?string $model = SecurityDeposit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Security Deposits';

    protected static ?string $slug = 'security-deposits';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Billing';
    }

    protected static ?string $recordTitleAttribute = 'id';

    // Read-only oversight — deposits are authored by the payment pipeline. The
    // release/forfeit actions on the view page mutate via SecurityDepositService.
    public static function canAccess(): bool
    {
        return AdminAuth::canViewBilling();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            'held'               => 'warning',
            'released'           => 'success',
            'forfeited'          => 'danger',
            'partially_released' => 'info',
            'refunded'           => 'success',
            default              => 'gray',
        };
    }

    public static function statusLabel(string $state): string
    {
        return ucwords(str_replace('_', ' ', $state));
    }

    /** Render a raw cross-DB UUID as small muted mono helper text under a name. */
    public static function rawIdHint(?string $id): ?HtmlString
    {
        if (! $id) {
            return null;
        }

        return new HtmlString(
            '<span style="font-size:10px;font-family:ui-monospace,monospace;color:#9ca3af;">REF UUID: '.e($id).'</span>'
        );
    }

    /**
     * A horizontal lifecycle stepper — Created → Held → Released/Forfeited — with
     * the deposit's current position highlighted. Rendered above the date detail.
     */
    public static function lifecycleFlow(SecurityDeposit $record): HtmlString
    {
        $status          = (string) $record->status;
        $isForfeited     = in_array($status, ['forfeited', 'partially_released'], true);
        $terminalReached = in_array($status, ['released', 'refunded', 'forfeited', 'partially_released'], true);
        $heldReached     = $terminalReached || $status === 'held' || (bool) $record->held_at;

        $current = $terminalReached ? 2 : ($heldReached ? 1 : 0);

        $steps = [
            ['label' => 'Created', 'reached' => true],
            ['label' => 'Held',    'reached' => $heldReached],
            ['label' => $isForfeited ? 'Forfeited' : 'Released', 'reached' => $terminalReached],
        ];

        $active = $isForfeited ? '#b5503a' : '#6a8d3f'; // forfeit red vs. platform green
        $idle   = '#cbb994';                            // muted tan
        $glow   = $isForfeited ? 'rgba(181,80,58,.25)' : 'rgba(106,141,63,.25)';

        $html = '<div style="display:flex;align-items:flex-start;width:100%;max-width:520px;margin:2px 0 6px;">';

        foreach ($steps as $i => $step) {
            $isCurrent = $i === $current;
            $reached   = $step['reached'];
            $color     = $reached ? $active : $idle;

            $dot = $isCurrent
                ? "background:{$color};box-shadow:0 0 0 4px {$glow};"
                : ($reached ? "background:{$color};" : "background:#f1ead9;border:2px solid {$color};");

            $html .= '<div style="display:flex;flex-direction:column;align-items:center;flex:0 0 auto;width:84px;">';
            $html .= '<div style="width:14px;height:14px;border-radius:50%;'.$dot.'"></div>';
            $html .= '<div style="margin-top:7px;font-size:10px;letter-spacing:.06em;text-transform:uppercase;'
                .'color:'.($reached ? '#3f3a2e' : '#9b8e74').';font-weight:'.($isCurrent ? '700' : '500').';">'
                .e($step['label']).'</div>';
            $html .= '</div>';

            if ($i < count($steps) - 1) {
                $lineColor = $steps[$i + 1]['reached'] ? $active : $idle;
                $html .= '<div style="flex:1 1 auto;height:2px;background:'.$lineColor.';margin-top:6px;"></div>';
            }
        }

        return new HtmlString($html.'</div>');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Deposit')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->copyable()
                    ->copyableState(fn (SecurityDeposit $record): string => $record->id),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                TextColumn::make('lease_id')
                    ->label('Lease')
                    ->state(fn (SecurityDeposit $record): ?string => $record->leaseLabel())
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('payer_user_id')
                    ->label('Lessee')
                    ->state(fn (SecurityDeposit $record): ?string => $record->getPayer()?->getFilamentName())
                    ->placeholder('Unknown user'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('refunded_amount_cents')
                    ->label('Refunded')
                    ->money('USD', divideBy: 100),
                TextColumn::make('forfeited_amount_cents')
                    ->label('Forfeited')
                    ->money('USD', divideBy: 100),
                TextColumn::make('held_at')
                    ->label('Held')
                    ->dateTime('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Summary')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                    TextEntry::make('currency')->label('Currency'),
                    TextEntry::make('amount_cents')->label('Amount')->money('USD', divideBy: 100),
                    TextEntry::make('refunded_amount_cents')->label('Refunded')->money('USD', divideBy: 100),
                    TextEntry::make('forfeited_amount_cents')->label('Forfeited')->money('USD', divideBy: 100),
                    TextEntry::make('forfeit_reason')->label('Forfeit Reason')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('Parties & References')
                ->columns(3)
                ->schema([
                    // Cross-DB UUIDs resolved to names via the service layer (admin runs
                    // under ah_system). The raw id stays available as muted helper text
                    // and as the copied value for support lookups.
                    TextEntry::make('payer_user_id')
                        ->label('Lessee')
                        ->state(fn (SecurityDeposit $record): ?string => $record->getPayer()?->getFilamentName())
                        ->placeholder('Unknown user')
                        ->helperText(fn (SecurityDeposit $record): ?HtmlString => self::rawIdHint($record->payer_user_id))
                        ->copyable()
                        ->copyableState(fn (SecurityDeposit $record): ?string => $record->payer_user_id),
                    TextEntry::make('payee_user_id')
                        ->label('Landowner')
                        ->state(fn (SecurityDeposit $record): ?string => $record->getPayee()?->getFilamentName())
                        ->placeholder('Unknown user')
                        ->helperText(fn (SecurityDeposit $record): ?HtmlString => self::rawIdHint($record->payee_user_id))
                        ->copyable()
                        ->copyableState(fn (SecurityDeposit $record): ?string => $record->payee_user_id),
                    TextEntry::make('lease_id')
                        ->label('Lease')
                        ->state(fn (SecurityDeposit $record): ?string => $record->leaseLabel())
                        ->placeholder('—')
                        ->helperText(fn (SecurityDeposit $record): ?HtmlString => self::rawIdHint($record->lease_id))
                        ->copyable()
                        ->copyableState(fn (SecurityDeposit $record): ?string => $record->lease_id),
                ]),

            Section::make('Lifecycle')
                ->columns(3)
                ->schema([
                    TextEntry::make('lifecycle_flow')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->state(fn (SecurityDeposit $record): HtmlString => self::lifecycleFlow($record)),
                    TextEntry::make('created_at')->label('Created')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('held_at')->label('Held')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('released_at')->label('Released')->dateTime('M j, Y H:i')->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSecurityDeposits::route('/'),
            'view'  => ViewSecurityDeposit::route('/{record}'),
        ];
    }
}
