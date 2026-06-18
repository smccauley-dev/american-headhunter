<?php

namespace App\Filament\Admin\Resources\Payments;

use App\Filament\Admin\Resources\Payments\Pages\ListPayments;
use App\Filament\Admin\Resources\Payments\Pages\ViewPayment;
use App\Models\Billing\Payment;
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

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $slug = 'payments';

    protected static ?int $navigationSort = 2;

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
            'succeeded'           => 'success',
            'pending', 'processing' => 'warning',
            'failed', 'canceled'  => 'danger',
            'refunded'            => 'info',
            default               => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Payment')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->copyable()
                    ->copyableState(fn (Payment $record): string => $record->id),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('payer_user_id')
                    ->label('Payer')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->placeholder('—'),
                TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('invoice_id')
                    ->label('Invoice')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('M j, Y H:i')
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
            Section::make('Payment')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                    TextEntry::make('amount_cents')->label('Amount')->money('USD', divideBy: 100),
                    TextEntry::make('currency')->label('Currency'),
                    TextEntry::make('failure_reason')->label('Failure Reason')->placeholder('—')->columnSpanFull(),
                ]),

            Section::make('References')
                ->columns(3)
                ->schema([
                    TextEntry::make('payer_user_id')->label('Payer (DB 1)')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('invoice_id')->label('Invoice')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('payment_method_id')->label('Payment Method')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('created_at')->label('Created')->dateTime('M j, Y H:i'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view'  => ViewPayment::route('/{record}'),
        ];
    }
}
