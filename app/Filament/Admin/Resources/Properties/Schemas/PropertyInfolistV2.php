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
use Filament\Support\Colors\Color;

class PropertyInfolistV2
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->tabs([

                    Tab::make('General Info')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            Section::make('General Info')
                                ->icon('heroicon-o-information-circle')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('Name, location, size, and current listing status.')
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
                        ->icon('heroicon-o-trophy')
                        ->schema([
                            Section::make('Game Types')
                                ->icon('heroicon-o-trophy')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('The huntable species offered on this property.')
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
                        ->icon('heroicon-o-clipboard-document-list')
                        ->schema([
                            Section::make('Property Rules')
                                ->icon('heroicon-o-clipboard-document-list')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('Rules every hunter must follow on this property.')
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
                        ->icon('heroicon-o-sparkles')
                        ->schema(static::amenitiesTabSchema()),

                    Tab::make('Listings')
                        ->icon('heroicon-o-tag')
                        ->schema([
                            Section::make('Listings')
                                ->icon('heroicon-o-tag')
                                ->iconColor(Color::hex('#c84c21'))
                                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                                ->description('The lease and day-hunt offerings published for this property.')
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
                                                ->formatStateUsing(fn (string $state): string => match ($state) {
                                                    'leased'      => 'Leased Out',
                                                    'unavailable' => 'Not Currently Available',
                                                    default       => ucwords(str_replace('_', ' ', $state)),
                                                })
                                                ->color(fn (string $state): string => match ($state) {
                                                    'active'      => 'success',
                                                    'draft'       => 'gray',
                                                    'pending'     => 'info',
                                                    'leased'      => 'warning',
                                                    'unavailable' => 'gray',
                                                    'expired'     => 'danger',
                                                    'archived'    => 'gray',
                                                    default       => 'gray',
                                                }),
                                            TextEntry::make('visibility')
                                                ->formatStateUsing(fn (string $state): string => $state === 'private'
                                                    ? 'Private / Hidden'
                                                    : ucwords(str_replace('_', ' ', $state))
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
                                            TextEntry::make('booking_deposit_amount')
                                                ->label('Booking Deposit ($)')
                                                ->money('USD')
                                                ->placeholder('—'),
                                            TextEntry::make('booking_deposit_percent')
                                                ->label('Booking Deposit (%)')
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

        return [
            Section::make('Amenities')
                ->icon('heroicon-o-sparkles')
                ->iconColor(Color::hex('#c84c21'))
                ->extraAttributes(['class' => 'ah-section-lead-icon'])
                ->description('Features and facilities available on the property, grouped by category.')
                ->schema([Grid::make(2)->schema($sections)]),
        ];
    }
}
