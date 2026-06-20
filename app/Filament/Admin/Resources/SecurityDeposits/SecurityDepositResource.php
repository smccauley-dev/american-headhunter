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
                    ->fontFamily('mono')
                    ->limit(8)
                    ->placeholder('—'),
                TextColumn::make('payer_user_id')
                    ->label('Lessee')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->placeholder('—'),
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
                    TextEntry::make('payer_user_id')->label('Lessee (DB 1)')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('payee_user_id')->label('Landowner (DB 1)')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('lease_id')->label('Lease (DB 3)')->fontFamily('mono')->placeholder('—')->copyable(),
                ]),

            Section::make('Dates')
                ->columns(3)
                ->schema([
                    TextEntry::make('held_at')->label('Held')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('released_at')->label('Released')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('created_at')->label('Created')->dateTime('M j, Y H:i'),
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
