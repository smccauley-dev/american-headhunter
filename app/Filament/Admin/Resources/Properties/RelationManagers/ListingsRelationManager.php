<?php

namespace App\Filament\Admin\Resources\Properties\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'listings';

    protected static ?string $title = 'Listings';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Listing Details')
                    ->columns(2)
                    ->schema([
                        Select::make('listing_type')
                            ->label('Type')
                            ->required()
                            ->options([
                                'annual_lease'   => 'Annual Lease',
                                'seasonal_lease' => 'Seasonal Lease',
                                'day_hunt'       => 'Day Hunt',
                                'auction'        => 'Auction',
                            ]),
                        Select::make('status')
                            ->required()
                            ->options([
                                'draft'    => 'Draft',
                                'active'   => 'Active',
                                'pending'  => 'Pending',
                                'leased'   => 'Leased Out',
                                'expired'  => 'Expired',
                                'archived' => 'Archived',
                            ])
                            ->default('draft'),
                        Select::make('visibility')
                            ->required()
                            ->options([
                                'public'       => 'Public',
                                'members_only' => 'Members Only',
                                'invite_only'  => 'Invite Only',
                            ])
                            ->default('public'),
                        Toggle::make('auto_renew')
                            ->label('Auto Renew')
                            ->default(false)
                            ->inline(false),
                    ]),

                Section::make('Season Dates')
                    ->columns(2)
                    ->schema([
                        DatePicker::make('season_start')
                            ->label('Season Start'),
                        DatePicker::make('season_end')
                            ->label('Season End')
                            ->afterOrEqual('season_start'),
                    ]),

                Section::make('Hunter Limits')
                    ->columns(2)
                    ->schema([
                        TextInput::make('min_hunters')
                            ->label('Minimum Hunters')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('No minimum'),
                        TextInput::make('max_hunters')
                            ->label('Maximum Hunters')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                    ]),

                Section::make('Pricing')
                    ->columns(2)
                    ->description('Set either Price Per Hunter or Total Price — not both.')
                    ->schema([
                        TextInput::make('price_per_hunter')
                            ->label('Price Per Hunter')
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0),
                        TextInput::make('price_total')
                            ->label('Total Price')
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0),
                        TextInput::make('deposit_amount')
                            ->label('Deposit Amount')
                            ->numeric()
                            ->prefix('$')
                            ->minValue(0)
                            ->helperText('Fixed dollar deposit — leave blank to use percent.'),
                        TextInput::make('deposit_percent')
                            ->label('Deposit Percent')
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->helperText('Percent of total — leave blank to use fixed amount.'),
                    ]),

                Section::make('Amenities')
                    ->schema([
                        Select::make('amenities')
                            ->relationship('amenities', 'name')
                            ->multiple()
                            ->preload()
                            ->label('Included Amenities')
                            ->helperText('Select all amenities available to hunters on this listing.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Listing ID')
                    ->fontFamily('mono')
                    ->formatStateUsing(fn (string $state): string => strtoupper(substr($state, 0, 8)))
                    ->copyable()
                    ->copyMessage('Listing ID copied'),
                TextColumn::make('listing_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'annual_lease'   => 'Annual Lease',
                        'seasonal_lease' => 'Seasonal Lease',
                        'day_hunt'       => 'Day Hunt',
                        'auction'        => 'Auction',
                        default          => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'annual_lease'   => 'success',
                        'seasonal_lease' => 'info',
                        'day_hunt'       => 'warning',
                        'auction'        => 'danger',
                        default          => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'leased' => 'Leased Out',
                        default  => ucwords(str_replace('_', ' ', $state)),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active'   => 'success',
                        'draft'    => 'gray',
                        'pending'  => 'info',
                        'leased'   => 'warning',
                        'expired'  => 'danger',
                        'archived' => 'warning',
                        default    => 'gray',
                    }),
                TextColumn::make('visibility')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'public'       => 'success',
                        'members_only' => 'info',
                        'invite_only'  => 'warning',
                        default        => 'gray',
                    }),
                TextColumn::make('price_per_hunter')
                    ->label('Per Hunter')
                    ->money('USD')
                    ->placeholder('—'),
                TextColumn::make('price_total')
                    ->label('Total')
                    ->money('USD')
                    ->placeholder('—'),
                TextColumn::make('season_start')
                    ->label('Starts')
                    ->date('M j, Y')
                    ->placeholder('—'),
                TextColumn::make('season_end')
                    ->label('Ends')
                    ->date('M j, Y')
                    ->placeholder('—'),
                TextColumn::make('max_hunters')
                    ->label('Max'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Listing'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
