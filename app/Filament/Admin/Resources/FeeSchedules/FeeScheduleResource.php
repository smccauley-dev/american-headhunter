<?php

namespace App\Filament\Admin\Resources\FeeSchedules;

use App\Filament\Admin\Resources\FeeSchedules\Pages\CreateFeeSchedule;
use App\Filament\Admin\Resources\FeeSchedules\Pages\EditFeeSchedule;
use App\Filament\Admin\Resources\FeeSchedules\Pages\ListFeeSchedules;
use App\Models\Billing\FeeSchedule;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin editor for the processing-fee surcharge schedule (DB 4 fee_schedules).
 *
 * Rows recover Stripe's processing cost as a customer-facing surcharge, keyed by
 * transaction category and (optionally) state — the most-specific active row wins.
 * The table is system-authored (RLS, SEC-045); these writes run under ah_system
 * (the admin panel), and saving flushes FeeService's Valkey cache so the new rate
 * applies on the next checkout.
 */
class FeeScheduleResource extends Resource
{
    protected static ?string $model = FeeSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $navigationLabel = 'Processing Fees';

    protected static ?string $slug = 'processing-fees';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'description';

    /** Category options — must match the table's CHECK constraint. */
    public const CATEGORIES = [
        'lease'             => 'Lease',
        'auction'           => 'Auction',
        'outfitter_booking' => 'Outfitter Booking',
        'security_deposit'  => 'Security Deposit',
        'marketplace'       => 'Marketplace',
    ];

    public static function getNavigationGroup(): ?string
    {
        return 'Billing';
    }

    public static function canAccess(): bool
    {
        return AdminAuth::canManagePricing();
    }

    public static function canCreate(): bool
    {
        return AdminAuth::canManagePricing();
    }

    public static function canEdit(Model $record): bool
    {
        return AdminAuth::canManagePricing();
    }

    public static function canDelete(Model $record): bool
    {
        return AdminAuth::canManagePricing();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Scope')
                ->description('Which transactions this fee applies to. Leave the state blank to cover all states; a state-specific row overrides the all-states row for the same category.')
                ->columns(2)
                ->schema([
                    Select::make('transaction_category')
                        ->label('Transaction Category')
                        ->options(self::CATEGORIES)
                        ->required(),
                    TextInput::make('state_code')
                        ->label('State')
                        ->placeholder('All states')
                        ->maxLength(2)
                        ->extraInputAttributes(['style' => 'text-transform:uppercase'])
                        ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper(trim($state)) : null)
                        ->helperText('Two-letter code, e.g. NC. Blank = applies to all states.'),
                ]),

            Section::make('Amount')
                ->description('A fee may set a percentage, a flat amount, or both. At least one is required.')
                ->columns(2)
                ->schema([
                    TextInput::make('pct')
                        ->label('Percentage')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.0001')
                        ->suffix('%')
                        ->requiredWithout('flat_cents')
                        ->helperText('e.g. 2.9 for Stripe\'s US rate.'),
                    TextInput::make('flat_cents')
                        ->label('Flat fee (cents)')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('¢')
                        ->requiredWithout('pct')
                        ->helperText('In cents — e.g. 30 = $0.30.'),
                ]),

            Section::make('Window')
                ->columns(2)
                ->schema([
                    Select::make('payer')
                        ->label('Paid by')
                        ->options(['customer' => 'Customer (surcharge)', 'landowner' => 'Landowner (deduction)'])
                        ->default('customer')
                        ->required()
                        ->helperText('Customer-surcharged fees are a visible checkout line item and do not reduce the landowner\'s net.'),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Only one active row per category + state at a time.'),
                    DateTimePicker::make('effective_from')
                        ->label('Effective From')
                        ->seconds(false)
                        ->helperText('Blank = effective immediately.'),
                    DateTimePicker::make('effective_to')
                        ->label('Effective Until')
                        ->seconds(false)
                        ->helperText('Blank = no end date.'),
                    TextInput::make('description')
                        ->label('Description')
                        ->maxLength(200)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_category')
                    ->label('Category')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => self::CATEGORIES[$state] ?? $state),
                TextColumn::make('state_code')
                    ->label('State')
                    ->placeholder('All')
                    ->sortable(),
                TextColumn::make('pct')
                    ->label('Percent')
                    ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : rtrim(rtrim(number_format($state, 4), '0'), '.') . '%')
                    ->alignEnd(),
                TextColumn::make('flat_cents')
                    ->label('Flat')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : '$' . number_format($state / 100, 2))
                    ->alignEnd(),
                TextColumn::make('payer')
                    ->label('Paid by')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->dateTime('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('transaction_category')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation()
                    ->modalHeading('Delete Fee Rule')
                    ->after(fn () => app(\App\Services\Billing\FeeService::class)->flushCache()),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Fee Rule'),
                BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListFeeSchedules::route('/'),
            'create' => CreateFeeSchedule::route('/create'),
            'edit'   => EditFeeSchedule::route('/{record}/edit'),
        ];
    }
}
