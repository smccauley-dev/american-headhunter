<?php

namespace App\Filament\Admin\Resources\MembershipPlans\RelationManagers;

use App\Models\Platform\FeatureEntitlement;
use App\Services\Platform\EntitlementService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Per-plan feature entitlements (DB 12). Edits affect newly published versions
 * and the live free-tier resolution, so the entitlement cache is flushed after
 * every mutation.
 */
class EntitlementsRelationManager extends RelationManager
{
    protected static string $relationship = 'entitlements';

    protected static ?string $title = 'Entitlements';

    public const FEATURE_TYPES = [
        'boolean' => 'Boolean (on/off)',
        'integer' => 'Integer (limit)',
        'string'  => 'String',
        'json'    => 'JSON',
    ];

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('feature_key')
                ->label('Feature Key')
                ->required()
                ->maxLength(100)
                ->extraInputAttributes(['class' => 'font-mono'])
                ->helperText('Matches the key checked via EntitlementService, e.g. trail_camera_integration.'),
            Select::make('feature_type')
                ->label('Type')
                ->options(self::FEATURE_TYPES)
                ->required()
                ->live(),
            Toggle::make('bool_value')
                ->label('Enabled')
                ->visible(fn (Get $get): bool => $get('feature_type') === 'boolean'),
            TextInput::make('int_value')
                ->label('Limit')
                ->numeric()
                ->helperText('-1 = unlimited.')
                ->visible(fn (Get $get): bool => $get('feature_type') === 'integer'),
            TextInput::make('string_value')
                ->label('Value')
                ->maxLength(255)
                ->visible(fn (Get $get): bool => $get('feature_type') === 'string'),
            KeyValue::make('json_value')
                ->label('JSON Value')
                ->visible(fn (Get $get): bool => $get('feature_type') === 'json'),
            TextInput::make('display_label')
                ->label('Display Label')
                ->maxLength(150)
                ->helperText('Shown on the pricing page.'),
            Textarea::make('display_description')
                ->label('Display Description')
                ->rows(2)
                ->columnSpanFull(),
            TextInput::make('display_order')
                ->label('Display Order')
                ->numeric()
                ->default(0),
            Toggle::make('show_on_pricing')
                ->label('Show on Pricing Page'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('display_order')
            ->columns([
                TextColumn::make('feature_key')
                    ->label('Key')
                    ->searchable()
                    ->fontFamily('mono'),
                TextColumn::make('feature_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('value')
                    ->label('Value')
                    ->state(fn (FeatureEntitlement $record): string => $this->formatValue($record)),
                TextColumn::make('display_label')
                    ->label('Label')
                    ->limit(40)
                    ->placeholder('—'),
                IconColumn::make('show_on_pricing')
                    ->label('On Pricing')
                    ->boolean(),
                TextColumn::make('display_order')
                    ->label('Order')
                    ->alignCenter(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Entitlement')
                    ->after(fn () => $this->flushEntitlementCache()),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(fn () => $this->flushEntitlementCache()),
                DeleteAction::make()
                    ->after(fn () => $this->flushEntitlementCache()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->after(fn () => $this->flushEntitlementCache()),
                ]),
            ]);
    }

    private function formatValue(FeatureEntitlement $record): string
    {
        $value = $record->value();

        return match (true) {
            is_bool($value)  => $value ? 'true' : 'false',
            is_array($value) => json_encode($value),
            $value === null  => '—',
            default          => (string) $value,
        };
    }

    private function flushEntitlementCache(): void
    {
        app(EntitlementService::class)->invalidateAll();
    }
}
