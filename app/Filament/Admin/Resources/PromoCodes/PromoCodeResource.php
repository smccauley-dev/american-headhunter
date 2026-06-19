<?php

namespace App\Filament\Admin\Resources\PromoCodes;

use App\Filament\Admin\Resources\PromoCodes\Pages\ManagePromoCodes;
use App\Models\Billing\PromoCode;
use App\Models\Platform\PromotionalPeriod;
use App\Support\AdminAuth;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?string $navigationLabel = 'Promo Codes';

    protected static ?string $slug = 'promo-codes';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Pricing & Promotions';
    }

    protected static ?string $recordTitleAttribute = 'code';

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
        return $schema
            ->columns(2)
            ->components([
                Select::make('promotional_period_id')
                    ->label('Promotion')
                    ->options(fn (): array => PromotionalPeriod::on('platform')
                        ->orderBy('display_name')
                        ->pluck('display_name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                TextInput::make('code')
                    ->label('Code')
                    ->required()
                    ->maxLength(50)
                    ->extraInputAttributes(['class' => 'font-mono'])
                    // Uppercase on blur so the case-insensitive uniqueness rule
                    // and the LOWER(code) unique index agree on the value.
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('code', strtoupper(trim((string) $state))))
                    ->unique(table: 'billing.promo_codes', column: 'code', ignoreRecord: true)
                    ->helperText('Stored uppercase. Must be unique across active codes.'),
                TextInput::make('max_redemptions')
                    ->label('Max Redemptions')
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Leave blank for unlimited.'),
                TextInput::make('per_user_limit')
                    ->label('Per-User Limit')
                    ->numeric()
                    ->minValue(1)
                    ->default(1),
                DateTimePicker::make('starts_at')
                    ->label('Starts At')
                    ->seconds(false),
                DateTimePicker::make('expires_at')
                    ->label('Expires At')
                    ->seconds(false),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->columnSpanFull(),
                Hidden::make('created_by_user_id')
                    ->default(fn () => auth()->id()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('redemption_count')
                    ->label('Redeemed')
                    ->formatStateUsing(fn (int $state, PromoCode $record): string => $record->max_redemptions
                        ? "{$state} / {$record->max_redemptions}"
                        : (string) $state)
                    ->alignCenter(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime('M j, Y')
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Promo Code'),
                BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePromoCodes::route('/'),
        ];
    }
}
