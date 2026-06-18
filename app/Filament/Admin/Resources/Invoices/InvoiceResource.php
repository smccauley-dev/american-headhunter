<?php

namespace App\Filament\Admin\Resources\Invoices;

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Billing\Invoice;
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

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?string $slug = 'invoices';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Billing';
    }

    protected static ?string $recordTitleAttribute = 'id';

    // Read-only oversight — billing records are written by the payment pipeline.
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
            'open', 'pending'       => 'warning',
            'void', 'uncollectible' => 'danger',
            default                 => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('Invoice')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->copyable()
                    ->copyableState(fn (Invoice $record): string => $record->id),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('payer_user_id')
                    ->label('Payer')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->placeholder('—'),
                TextColumn::make('payee_user_id')
                    ->label('Payee')
                    ->fontFamily('mono')
                    ->limit(8)
                    ->placeholder('—'),
                TextColumn::make('total_cents')
                    ->label('Total')
                    ->money('USD', divideBy: 100)
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Due')
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
            Section::make('Summary')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => self::statusColor($state))
                        ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                    TextEntry::make('currency')->label('Currency'),
                    TextEntry::make('subtotal_cents')->label('Subtotal')->money('USD', divideBy: 100),
                    TextEntry::make('tax_cents')->label('Tax')->money('USD', divideBy: 100),
                    TextEntry::make('platform_fee_cents')->label('Platform Fee')->money('USD', divideBy: 100),
                    TextEntry::make('total_cents')->label('Total')->money('USD', divideBy: 100),
                ]),

            Section::make('Parties & References')
                ->columns(3)
                ->schema([
                    TextEntry::make('payer_user_id')->label('Payer (DB 1)')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('payee_user_id')->label('Payee (DB 1)')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('lease_id')->label('Lease (DB 3)')->fontFamily('mono')->placeholder('—')->copyable(),
                    TextEntry::make('stripe_invoice_id')->label('Stripe Invoice')->fontFamily('mono')->placeholder('—')->copyable(),
                ]),

            Section::make('Dates')
                ->columns(3)
                ->schema([
                    TextEntry::make('due_date')->label('Due')->date('M j, Y')->placeholder('—'),
                    TextEntry::make('paid_at')->label('Paid')->dateTime('M j, Y H:i')->placeholder('—'),
                    TextEntry::make('created_at')->label('Created')->dateTime('M j, Y H:i'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view'  => ViewInvoice::route('/{record}'),
        ];
    }
}
