<?php

namespace App\Filament\Admin\Resources\MembershipPlans\RelationManagers;

use App\Models\Platform\FeatureEntitlement;
use App\Services\Platform\EntitlementService;
use App\Support\Entitlements;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
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

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('feature_key')
                ->label('Entitlement')
                ->options(fn (?FeatureEntitlement $record): array => $this->entitlementOptions($record))
                ->required()
                ->live()
                ->afterStateUpdated(function ($state, callable $set): void {
                    if ($type = Entitlements::typeFor((string) $state)) {
                        $set('feature_type', $type);
                    }
                })
                ->helperText('Only entitlements the platform actually offers are listed. To add a new capability, define it in App\Support\Entitlements first, then wire its gate in code.'),
            Hidden::make('feature_type'),
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
                ->label('Show on Pricing Page')
                ->inline(false)
                ->afterContent('Disable / Enable'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('display_order')
            ->columns([
                TextColumn::make('display_label')
                    ->label('Entitlement Name')
                    ->searchable()
                    ->limit(40)
                    ->placeholder('—'),
                TextColumn::make('value')
                    ->label('Limit')
                    ->state(fn (FeatureEntitlement $record): string => $this->formatValue($record)),
                IconColumn::make('show_on_pricing')
                    ->label('Pricing Page')
                    ->boolean(),
                TextColumn::make('display_order')
                    ->label('Order')
                    ->alignCenter(),
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

    /**
     * Catalog options for the entitlement picker, hiding keys already attached to
     * this plan (the unique (plan_id, feature_key) constraint forbids duplicates).
     * When editing a row whose key predates the catalog, surface it so the Select
     * isn't blank.
     */
    private function entitlementOptions(?FeatureEntitlement $record): array
    {
        $used = $this->getOwnerRecord()->entitlements()
            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
            ->pluck('feature_key')
            ->all();

        $options = Entitlements::groupedOptions($used);

        if ($record && Entitlements::typeFor($record->feature_key) === null) {
            $options['Uncatalogued'][$record->feature_key] = $record->feature_key;
        }

        return $options;
    }

    private function flushEntitlementCache(): void
    {
        app(EntitlementService::class)->invalidateAll();
    }
}
