<?php

namespace App\Filament\Admin\Resources\Payouts;

use App\Filament\Admin\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Admin\Resources\Payouts\Pages\ViewPayout;
use App\Models\Billing\Payout;
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

class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Payouts';

    protected static ?string $slug = 'payouts';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Billing';
    }

    protected static ?string $recordTitleAttribute = 'id';

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
            'paid'                  => 'success',
            'pending', 'in_transit' => 'warning',
            'failed', 'canceled'    => 'danger',
            default                 => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Payout')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->copyable()
                    ->copyableState(fn (Payout $record): string => $record->id),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                TextColumn::make('payee_user_id')
                    ->label('Payee')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->placeholder('—'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('scheduled_for')
                    ->label('Scheduled')
                    ->date('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('paid_at')
                    ->label('Paid')
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
            Section::make('Payout')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),
                    TextEntry::make('amount_cents')->label('Amount')->money('USD', divideBy: 100),
                    TextEntry::make('currency')->label('Currency'),
                ]),

            Section::make('References & Dates')
                ->columns(3)
                ->schema([
                    TextEntry::make('payee_user_id')->label('Payee (DB 1)')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('scheduled_for')->label('Scheduled')->date('M j, Y')->placeholder('—'),
                    TextEntry::make('paid_at')->label('Paid')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('created_at')->label('Created')->dateTime('M j, Y H:i'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayouts::route('/'),
            'view'  => ViewPayout::route('/{record}'),
        ];
    }
}
