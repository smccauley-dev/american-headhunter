<?php

namespace App\Filament\Admin\Resources\FeatureFlags;

use App\Filament\Admin\Resources\FeatureFlags\Pages\ManageFeatureFlags;
use App\Models\Platform\FeatureFlag;
use App\Support\AdminAuth;
use App\Services\Platform\FeatureFlagService;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeatureFlagResource extends Resource
{
    protected static ?string $model = FeatureFlag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return 'System';
    }

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function canAccess(): bool
    {
        return AdminAuth::canManageSystem();
    }

    public static function canCreate(): bool
    {
        return AdminAuth::canManageSystem();
    }

    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('display_name')
                    ->required()
                    ->maxLength(100),
                Textarea::make('description')
                    ->columnSpanFull()
                    ->rows(2),
                Toggle::make('is_enabled')
                    ->label('Globally Enabled')
                    ->helperText('When off, feature is disabled for everyone regardless of rollout settings.'),
                TextInput::make('rollout_percentage')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->suffix('%')
                    ->helperText('Percentage of users who see this feature (0 = no one, 100 = everyone).'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                TextColumn::make('display_name')
                    ->searchable(),
                IconColumn::make('is_enabled')
                    ->label('Enabled')
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedCheckCircle)
                    ->falseIcon(Heroicon::OutlinedXCircle)
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('rollout_percentage')
                    ->label('Rollout')
                    ->suffix('%')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('key')
            ->filters([])
            ->recordActions([
                EditAction::make()
                    ->after(fn (FeatureFlag $record) =>
                        app(FeatureFlagService::class)->invalidateFlag($record->key)
                    ),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->label('Add Feature Flag'),
                BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFeatureFlags::route('/'),
        ];
    }
}
