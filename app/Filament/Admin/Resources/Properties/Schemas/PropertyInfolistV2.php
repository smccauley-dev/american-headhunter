<?php

namespace App\Filament\Admin\Resources\Properties\Schemas;

use App\Models\Property\PropertyAmenity;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class PropertyInfolistV2
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->tabs([

                    Tab::make('General Info')
                        ->schema([
                            Section::make()
                                ->columns(2)
                                ->schema([
                                    TextEntry::make('title')
                                        ->columnSpanFull(),
                                    TextEntry::make('slug')
                                        ->fontFamily('mono')
                                        ->columnSpanFull(),
                                    TextEntry::make('description')
                                        ->columnSpanFull()
                                        ->placeholder('—'),
                                    TextEntry::make('status')
                                        ->badge()
                                        ->color(fn (string $state): string => match ($state) {
                                            'active'    => 'success',
                                            'draft'     => 'gray',
                                            'suspended' => 'danger',
                                            'archived'  => 'warning',
                                            default     => 'gray',
                                        }),
                                    TextEntry::make('state_code')
                                        ->label('State')
                                        ->formatStateUsing(fn ($state) => \App\Support\UsStates::names()[$state] ?? $state),
                                    TextEntry::make('county'),
                                    TextEntry::make('center_lat')
                                        ->label('Latitude')
                                        ->fontFamily('mono')
                                        ->placeholder('—'),
                                    TextEntry::make('center_lng')
                                        ->label('Longitude')
                                        ->fontFamily('mono')
                                        ->placeholder('—'),
                                    TextEntry::make('total_acres')
                                        ->label('Total Acres')
                                        ->numeric(decimalPlaces: 2),
                                    TextEntry::make('huntable_acres')
                                        ->label('Huntable Acres')
                                        ->numeric(decimalPlaces: 2)
                                        ->placeholder('—'),
                                ]),
                        ]),

                    Tab::make('Game Type')
                        ->schema([
                            Section::make()
                                ->schema([
                                    RepeatableEntry::make('species')
                                        ->columns(2)
                                        ->schema([
                                            TextEntry::make('species_code')
                                                ->label('Species')
                                                ->formatStateUsing(fn (string $state): string =>
                                                    ucwords(str_replace('_', ' ', $state))
                                                ),
                                            IconEntry::make('is_primary')
                                                ->label('Primary Species')
                                                ->boolean(),
                                        ]),
                                ]),
                        ]),

                    Tab::make('Property Rules')
                        ->schema([
                            Section::make()
                                ->schema([
                                    RepeatableEntry::make('rules')
                                        ->schema([
                                            TextEntry::make('rule_text')
                                                ->label('Rule')
                                                ->columnSpanFull(),
                                            TextEntry::make('sort_order')
                                                ->label('Order'),
                                        ]),
                                ]),
                        ]),

                    Tab::make('Amenities')
                        ->schema(static::amenitiesTabSchema()),

                    Tab::make('Listings')
                        ->schema([
                            Section::make()
                                ->schema([
                                    RepeatableEntry::make('listings')
                                        ->columns(2)
                                        ->schema([
                                            TextEntry::make('id')
                                                ->label('Listing ID')
                                                ->fontFamily('mono')
                                                ->formatStateUsing(fn (string $state): string => strtoupper(substr($state, 0, 8)))
                                                ->copyable()
                                                ->copyMessage('Listing ID copied')
                                                ->columnSpanFull(),
                                            TextEntry::make('listing_type')
                                                ->label('Type')
                                                ->formatStateUsing(fn (string $state): string =>
                                                    ucwords(str_replace('_', ' ', $state))
                                                ),
                                            TextEntry::make('status')
                                                ->badge()
                                                ->color(fn (string $state): string => match ($state) {
                                                    'active'   => 'success',
                                                    'draft'    => 'gray',
                                                    'sold_out' => 'warning',
                                                    'expired'  => 'danger',
                                                    'archived' => 'gray',
                                                    default    => 'gray',
                                                }),
                                            TextEntry::make('visibility')
                                                ->formatStateUsing(fn (string $state): string =>
                                                    ucwords(str_replace('_', ' ', $state))
                                                ),
                                            IconEntry::make('auto_renew')
                                                ->label('Auto Renew')
                                                ->boolean(),
                                            TextEntry::make('season_start')
                                                ->label('Season Start')
                                                ->date()
                                                ->placeholder('—'),
                                            TextEntry::make('season_end')
                                                ->label('Season End')
                                                ->date()
                                                ->placeholder('—'),
                                            TextEntry::make('max_hunters')
                                                ->label('Max Hunters'),
                                            TextEntry::make('min_hunters')
                                                ->label('Min Hunters')
                                                ->placeholder('—'),
                                            TextEntry::make('price_per_hunter')
                                                ->label('Price / Hunter')
                                                ->money('USD')
                                                ->placeholder('—'),
                                            TextEntry::make('price_total')
                                                ->label('Total Price')
                                                ->money('USD')
                                                ->placeholder('—'),
                                            TextEntry::make('deposit_amount')
                                                ->label('Deposit ($)')
                                                ->money('USD')
                                                ->placeholder('—'),
                                            TextEntry::make('deposit_percent')
                                                ->label('Deposit (%)')
                                                ->suffix('%')
                                                ->placeholder('—'),
                                        ]),
                                ]),
                        ]),

                ]),
        ]);
    }

    private static function amenitiesTabSchema(): array
    {
        $sections = PropertyAmenity::distinct()
            ->orderBy('category')
            ->pluck('category')
            ->map(fn ($cat) =>
                Section::make(PropertyAmenity::categoryLabel($cat))
                    ->schema([
                        TextEntry::make('amenities_' . $cat)
                            ->hiddenLabel()
                            ->getStateUsing(fn ($record) =>
                                $record->amenities()
                                    ->where('property_amenities.category', $cat)
                                    ->orderBy('property_amenities.name')
                                    ->pluck('property_amenities.name')
                                    ->all()
                            )
                            ->badge()
                            ->color('primary')
                            ->placeholder('None selected')
                            ->columnSpanFull(),
                    ])
            )
            ->all();

        return [Grid::make(2)->schema($sections)];
    }
}
