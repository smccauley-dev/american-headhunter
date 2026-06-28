<?php

namespace App\Filament\Admin\Resources\LeasePayments;

use App\Filament\Admin\Resources\LeasePayments\Pages\ListLeasePayments;
use App\Filament\Admin\Resources\LeasePayments\Pages\ViewLeasePayment;
use App\Models\Billing\LeasePayment;
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

class LeasePaymentResource extends Resource
{
    protected static ?string $model = LeasePayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Lease Payments';

    protected static ?string $slug = 'lease-payments';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Billing';
    }

    protected static ?string $recordTitleAttribute = 'id';

    // Read-only oversight — payments are authored by the Connect pipeline. The
    // refund action on the view page mutates via LeasePaymentService.
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
            'collected'          => 'success',
            'partially_refunded' => 'warning',
            'refunded'           => 'danger',
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Payment')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->copyable()
                    ->copyableState(fn (LeasePayment $record): string => $record->id),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                TextColumn::make('lease_id')
                    ->label('Lease')
                    ->state(fn (LeasePayment $record): ?string => $record->leaseLabel())
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('payer_user_id')
                    ->label('Lessee')
                    ->state(fn (LeasePayment $record): ?string => $record->getPayer()?->getFilamentName())
                    ->placeholder('Unknown user'),
                TextColumn::make('payee_user_id')
                    ->label('Landowner')
                    ->state(fn (LeasePayment $record): ?string => $record->getPayee()?->getFilamentName())
                    ->placeholder('Unknown user'),
                TextColumn::make('gross_cents')
                    ->label('Gross')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('application_fee_cents')
                    ->label('Platform Fee')
                    ->money('USD', divideBy: 100),
                TextColumn::make('net_cents')
                    ->label('Net to Landowner')
                    ->money('USD', divideBy: 100),
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
            Section::make('Summary')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (string $state): string => self::statusLabel($state)),
                    TextEntry::make('currency')->label('Currency'),
                    TextEntry::make('paid_at')->label('Paid')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('gross_cents')->label('Gross (customer paid)')->money('USD', divideBy: 100),
                    TextEntry::make('surcharge_cents')->label('Processing Surcharge')->money('USD', divideBy: 100),
                    TextEntry::make('application_fee_cents')->label('Platform Fee')->money('USD', divideBy: 100),
                    TextEntry::make('net_cents')->label('Net to Landowner')->money('USD', divideBy: 100),
                ]),

            Section::make('Parties & References')
                ->columns(3)
                ->schema([
                    // Cross-DB UUIDs resolved to names via the service layer (admin runs
                    // under ah_system). The raw id stays available as muted helper text
                    // and as the copied value for support lookups.
                    TextEntry::make('payer_user_id')
                        ->label('Lessee')
                        ->state(fn (LeasePayment $record): ?string => $record->getPayer()?->getFilamentName())
                        ->placeholder('Unknown user')
                        ->helperText(fn (LeasePayment $record): ?HtmlString => self::rawIdHint($record->payer_user_id))
                        ->copyable()
                        ->copyableState(fn (LeasePayment $record): ?string => $record->payer_user_id),
                    TextEntry::make('payee_user_id')
                        ->label('Landowner')
                        ->state(fn (LeasePayment $record): ?string => $record->getPayee()?->getFilamentName())
                        ->placeholder('Unknown user')
                        ->helperText(fn (LeasePayment $record): ?HtmlString => self::rawIdHint($record->payee_user_id))
                        ->copyable()
                        ->copyableState(fn (LeasePayment $record): ?string => $record->payee_user_id),
                    TextEntry::make('lease_id')
                        ->label('Lease')
                        ->state(fn (LeasePayment $record): ?string => $record->leaseLabel())
                        ->placeholder('—')
                        ->helperText(fn (LeasePayment $record): ?HtmlString => self::rawIdHint($record->lease_id))
                        ->copyable()
                        ->copyableState(fn (LeasePayment $record): ?string => $record->lease_id),
                ]),

            Section::make('Lifecycle')
                ->columns(3)
                ->schema([
                    TextEntry::make('created_at')->label('Created')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('paid_at')->label('Paid')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('updated_at')->label('Updated')->dateTime('M j, Y H:i')->placeholder('—'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeasePayments::route('/'),
            'view'  => ViewLeasePayment::route('/{record}'),
        ];
    }
}
